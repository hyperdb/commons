<?php

namespace App\Phpunit;

use App\Shared\Utils\HashTools;
use Flight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 偽のHTTP通信（Flight::httpClient()の代わり）
 *
 * 用意した応答を順に返し、送信内容を記録する
 */
class StubHttpClient
{
    /** @var array<int, array{status: int, body: string}> 返す応答（先頭から使う） */
    public array $responses = [];

    /** @var array<int, array<string, mixed>> 送信内容の記録 */
    public array $requests = [];

    public function get(string $url, array $headers = []): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers];
        return $this->next();
    }

    public function post(string $url, array $params, array $headers = []): array
    {
        $this->requests[] = ['url' => $url, 'params' => $params, 'headers' => $headers];
        return $this->next();
    }

    private function next(): array
    {
        return array_shift($this->responses) ?? throw new \RuntimeException('connection failed');
    }
}

/**
 * テストクラス: 外部ログイン（/auth）
 *
 * 各テストは「準備 → 実行 → 確認」の順で書いている
 */
class AuthTest extends ApiTestCase
{
    private const BLOG = 'https://blog.example.com/login';
    private const VERIFIER = 'blog-verifier-0123456789-0123456789-0123456789';
    private const ENV = [
        'GITHUB_CLIENT_ID' => 'gh-id',
        'GITHUB_CLIENT_SECRET' => 'gh-secret',
        'GITHUB_CALLBACK_URL' => 'https://api.example.com/github/callback',
        'GOOGLE_CLIENT_ID' => 'gg-id',
        'GOOGLE_CLIENT_SECRET' => 'gg-secret',
        'GOOGLE_CALLBACK_URL' => 'https://api.example.com/google/callback',
        'AUTH_REDIRECT_ALLOWLIST' => self::BLOG . ', https://blog.example.com/?page=login',
    ];

    private StubHttpClient $http;

    /** @var array<string, mixed> テスト前の環境変数 */
    private array $originalEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = $_ENV;
        $_ENV = [...$_ENV, ...self::ENV];

        $this->http = new StubHttpClient();
        Flight::map('httpClient', fn() => $this->http);
        $logger = new class
        {
            public function error(string $message): void
            {
            }
        };
        Flight::map('logger', fn() => $logger);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
        parent::tearDown();
    }

    private static function challenge(): string
    {
        return \OAuthProvider::challenge(self::VERIFIER);
    }

    /**
     * @param array<string, string> $query
     */
    private function callLogin(string $provider, array $query): void
    {
        $this->request('GET', "/api/v1/auth/{$provider}/login", query: $query);
        (new \Auth())->login($provider);
    }

    /**
     * @param array<string, string> $query
     */
    private function callCallback(string $provider, array $query): void
    {
        $this->request('GET', "/api/v1/auth/{$provider}/callback?" . http_build_query($query), query: $query);
        (new \Auth())->callback($provider);
    }

    /**
     * @param array<string, string> $body
     */
    private function callExchange(array $body): void
    {
        $this->request('POST', '/api/v1/auth/exchange', $body);
        (new \Auth())->exchange();
    }

    /**
     * ログイン途中の状態にする
     *
     * @return string state
     */
    private function startLogin(string $provider, string $redirectUri = self::BLOG): string
    {
        $state = \OAuthProvider::randomString();
        $this->db->states[$state] = [
            'state' => $state,
            'provider' => $provider,
            'code_verifier' => 'provider-verifier',
            'code_challenge' => self::challenge(),
            'redirect_uri' => $redirectUri,
        ];
        return $state;
    }

    /**
     * プロバイダーの応答（アクセストークン → ユーザー情報）を用意する
     *
     * @param array<string, mixed> $user
     */
    private function providerReturns(array $user): void
    {
        $this->http->responses = [
            ['status' => 200, 'body' => json_encode(['access_token' => 'secret-access-token'])],
            ['status' => 200, 'body' => json_encode($user)],
        ];
    }

    /**
     * リダイレクト先のURLとクエリ
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function location(): array
    {
        [$url, $query] = explode('?', Flight::response()->getHeader('Location') ?? '', 2) + ['', ''];
        parse_str($query, $params);
        return [$url, $params];
    }

    /**
     * 交換できる一時コードを用意する
     */
    private function prepareCode(?string $stoppedAt = null): string
    {
        $uuid = HashTools::expandShortUuidV4($this->loginAsExternal($stoppedAt));
        $code = \OAuthProvider::randomString();
        $this->db->codes[$code] = ['token' => $uuid, 'code_challenge' => self::challenge()];
        return $code;
    }

    // ==================================================
    // GET /auth/{provider}/login — ログイン開始
    // ==================================================

    public static function loginProvider(): array
    {
        return [
            'GitHub' => ['github', 'https://github.com/login/oauth/authorize', 'gh-id', null],
            'Google' => ['google', 'https://accounts.google.com/o/oauth2/v2/auth', 'gg-id', 'openid profile'],
        ];
    }

    #[TestDox('ログイン開始: 認可画面へ302、stateとPKCEを渡してDBに保存する')]
    #[DataProvider('loginProvider')]
    public function testLoginRedirects(string $provider, string $url, string $clientId, ?string $scope): void
    {
        $this->callLogin($provider, ['redirect_uri' => self::BLOG, 'code_challenge' => self::challenge()]);

        $this->assertSame(302, $this->responseStatus());
        [$location, $params] = $this->location();
        $this->assertSame($url, $location);
        $this->assertSame($clientId, $params['client_id']);
        $this->assertSame($scope, $params['scope'] ?? null);
        $this->assertSame('S256', $params['code_challenge_method']);
        $saved = $this->db->states[$params['state']];
        $this->assertSame($provider, $saved['provider']);
        $this->assertSame(self::BLOG, $saved['redirect_uri']);
        $this->assertSame(self::challenge(), $saved['code_challenge']);
        // プロバイダーに渡すのはハッシュ値で、元の値はDBにだけ保存する
        $this->assertSame(\OAuthProvider::challenge($saved['code_verifier']), $params['code_challenge']);
        $this->assertSame('no-store', Flight::response()->getHeader('Cache-Control'));
    }

    #[TestDox('ログイン開始: 未対応のプロバイダーは404')]
    public function testLoginUnknownProviderReturns404(): void
    {
        $this->callLogin('twitter', ['redirect_uri' => self::BLOG, 'code_challenge' => self::challenge()]);

        $this->assertSame(404, $this->responseStatus());
    }

    public static function invalidLoginProvider(): array
    {
        return [
            'redirect_uriなし' => ['', null, 'redirect_uri'],
            '許可リストにない' => ['https://evil.example.com/', null, 'redirect_uri'],
            '前方一致だけでは許可しない' => [self::BLOG . '/../evil', null, 'redirect_uri'],
            'code_challengeなし' => [self::BLOG, '', 'code_challenge'],
            'code_challengeの形式が不正' => [self::BLOG, 'short', 'code_challenge'],
            'code_challengeの末尾が改行' => [self::BLOG, self::challenge() . "\n", 'code_challenge'],
        ];
    }

    #[TestDox('ログイン開始: パラメーターが不正なら400（リダイレクトしない）')]
    #[DataProvider('invalidLoginProvider')]
    public function testLoginRejectsInvalidParams(string $redirectUri, ?string $challenge, string $errorKey): void
    {
        $this->callLogin('github', [
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge ?? self::challenge(),
        ]);

        $this->assertSame(400, $this->responseStatus());
        $this->assertSame([$errorKey], array_keys($this->json()['data']['errors']));
        $this->assertNull(Flight::response()->getHeader('Location'));
        $this->assertCount(0, $this->db->states);
    }

    #[TestDox('ログイン開始: 許可リストが未設定ならredirect_uriが空でも400')]
    public function testLoginWithoutAllowlistReturns400(): void
    {
        $_ENV['AUTH_REDIRECT_ALLOWLIST'] = '';

        $this->callLogin('github', ['redirect_uri' => '', 'code_challenge' => self::challenge()]);

        $this->assertSame(400, $this->responseStatus());
    }

    #[TestDox('ログイン開始: 設定値が不足していれば500（設定名をレスポンスに含めない）')]
    public function testLoginWithoutConfigReturns500(): void
    {
        $_ENV['GITHUB_CLIENT_ID'] = '';

        $this->callLogin('github', ['redirect_uri' => self::BLOG, 'code_challenge' => self::challenge()]);

        $this->assertSame(500, $this->responseStatus());
        $this->assertStringNotContainsString('GITHUB', Flight::response()->getBody());
    }

    // ==================================================
    // GET /auth/{provider}/callback — コールバック
    // ==================================================

    #[TestDox('コールバック: GitHubの初回ログインでユーザーを登録し、一時コードを付けてブログへ戻す')]
    public function testCallbackCreatesGithubUser(): void
    {
        $state = $this->startLogin('github');
        $this->providerReturns(
            ['id' => 583231, 'login' => 'octocat', 'name' => 'Octo', 'avatar_url' => 'https://example.com/o.png']
        );

        $this->callCallback('github', ['code' => 'provider-code', 'state' => $state]);

        $this->assertSame(302, $this->responseStatus());
        [$location, $params] = $this->location();
        $this->assertSame(self::BLOG, $location);
        $this->assertSame(['code'], array_keys($params));
        $this->assertSame('Octo', $this->db->externalUsers['gh-583231']['display_name']);
        // 一時コードは発行したトークンに紐付き、トークン本体はURLに載らない
        $uuid = $this->db->codes[$params['code']]['token'];
        $this->assertSame('gh-583231', $this->db->externalTokens[$uuid]);
        $this->assertSame(self::challenge(), $this->db->codes[$params['code']]['code_challenge']);
        // 認可コードはPKCEの元の値と一緒に送る
        $this->assertSame('provider-code', $this->http->requests[0]['params']['code']);
        $this->assertSame('provider-verifier', $this->http->requests[0]['params']['code_verifier']);
        $this->assertContains('Authorization: Bearer secret-access-token', $this->http->requests[1]['headers']);
        // アクセストークンは保存しない
        $this->assertStringNotContainsString('secret-access-token', json_encode($this->db->queries));
        // 期限切れのデータを削除する
        $this->assertCount(1, $this->db->executed('DELETE FROM external_tokens WHERE expired_at'));
        $this->assertCount(1, $this->db->executed('DELETE FROM oauth_states WHERE expired_at'));
        $this->assertCount(1, $this->db->executed('DELETE FROM auth_codes WHERE expired_at'));
    }

    #[TestDox('コールバック: GitHubの表示名が未設定ならユーザー名を使い、HTTPS以外のアバターは保存しない')]
    public function testCallbackFallsBackToLogin(): void
    {
        $state = $this->startLogin('github');
        $this->providerReturns(
            ['id' => 1, 'login' => 'octocat', 'name' => null, 'avatar_url' => 'javascript:alert(1)']
        );

        $this->callCallback('github', ['code' => 'c', 'state' => $state]);

        $this->assertSame('octocat', $this->db->externalUsers['gh-1']['display_name']);
        $this->assertSame('', $this->db->externalUsers['gh-1']['avatar_url']);
    }

    #[TestDox('コールバック: Googleの初回ログインはランダムなID、2回目以降は同じIDで表示名を上書きする')]
    public function testCallbackKeepsGoogleUserId(): void
    {
        $state = $this->startLogin('google');
        $this->providerReturns(['sub' => '110248495921238986420', 'name' => 'Before', 'picture' => '']);
        $this->callCallback('google', ['code' => 'c', 'state' => $state]);

        $this->assertCount(1, $this->db->externalUsers);
        $id = array_key_first($this->db->externalUsers);
        $this->assertMatchesRegularExpression('/^gg-[a-z0-9]{16}\z/', $id);

        // 2回目
        $state = $this->startLogin('google');
        $this->providerReturns(['sub' => '110248495921238986420', 'name' => 'After', 'picture' => '']);
        $this->callCallback('google', ['code' => 'c', 'state' => $state]);

        $this->assertSame([$id], array_keys($this->db->externalUsers));
        $this->assertSame('After', $this->db->externalUsers[$id]['display_name']);
    }

    public static function invalidStateProvider(): array
    {
        return [
            'stateなし' => [''],
            '形式が不正' => ['invalid'],
            '存在しない' => [str_repeat('a', 43)],
        ];
    }

    #[TestDox('コールバック: stateが無効なら400（ブログへ戻さず、プロバイダーとも通信しない）')]
    #[DataProvider('invalidStateProvider')]
    public function testCallbackRejectsInvalidState(string $state): void
    {
        $this->startLogin('github');

        $this->callCallback('github', ['code' => 'secret-code', 'state' => $state]);

        $this->assertSame(400, $this->responseStatus());
        $this->assertNull(Flight::response()->getHeader('Location'));
        $this->assertCount(0, $this->http->requests);
        // レスポンスのapi_routeにクエリを含めない
        $this->assertStringNotContainsString('secret-code', Flight::response()->getBody());
    }

    #[TestDox('コールバック: 別のプロバイダーのstateは400')]
    public function testCallbackRejectsOtherProvidersState(): void
    {
        $state = $this->startLogin('google');

        $this->callCallback('github', ['code' => 'c', 'state' => $state]);

        $this->assertSame(400, $this->responseStatus());
    }

    #[TestDox('コールバック: stateは1回しか使えない')]
    public function testCallbackStateIsSingleUse(): void
    {
        $state = $this->startLogin('github');
        $this->callCallback('github', ['error' => 'access_denied', 'state' => $state]);
        $this->assertCount(0, $this->db->states);

        $this->http->requests = [];
        $this->providerReturns(['id' => 1, 'login' => 'octocat']);
        $this->callCallback('github', ['code' => 'c', 'state' => $state]);

        $this->assertCount(0, $this->http->requests);
        $this->assertCount(0, $this->db->externalTokens);
    }

    public static function providerErrorProvider(): array
    {
        $token = ['status' => 200, 'body' => '{"access_token":"t"}'];
        return [
            'ユーザーが拒否' => [['error' => 'access_denied'], [], 'access_denied'],
            'その他のエラー' => [['error' => 'server_error'], [], 'provider_error'],
            '認可コードなし' => [[], [], 'provider_error'],
            '通信エラー' => [['code' => 'c'], [], 'provider_error'],
            'GitHubは200でエラーを返す' => [
                ['code' => 'c'],
                [['status' => 200, 'body' => '{"error":"bad_verification_code"}']],
                'provider_error'
            ],
            'JSONではない' => [['code' => 'c'], [['status' => 200, 'body' => '<html>']], 'provider_error'],
            'ユーザー情報の取得に失敗' => [
                ['code' => 'c'],
                [$token, ['status' => 403, 'body' => '{}']],
                'provider_error'
            ],
            'ユーザー情報にIDがない' => [
                ['code' => 'c'],
                [$token, ['status' => 200, 'body' => '{"login":"a"}']],
                'provider_error'
            ],
        ];
    }

    #[TestDox('コールバック: 失敗した場合は理由を付けてブログへ戻す（トークンは発行しない）')]
    #[DataProvider('providerErrorProvider')]
    public function testCallbackReturnsErrorToBlog(array $query, array $responses, string $reason): void
    {
        $state = $this->startLogin('github');
        $this->http->responses = $responses;

        $this->callCallback('github', [...$query, 'state' => $state]);

        $this->assertSame(302, $this->responseStatus());
        $this->assertSame([self::BLOG, ['error' => $reason]], $this->location());
        $this->assertCount(0, $this->db->externalUsers);
        $this->assertCount(0, $this->db->externalTokens);
    }

    #[TestDox('コールバック: 停止中のユーザーはsuspendedで戻す（表示名も上書きしない）')]
    public function testCallbackRejectsStoppedUser(): void
    {
        $this->loginAsExternal('2026-11-24 10:00:00');
        $state = $this->startLogin('github');
        $this->providerReturns(['id' => 12345, 'login' => 'octocat', 'name' => 'Changed']);
        $tokens = $this->db->externalTokens;

        $this->callCallback('github', ['code' => 'c', 'state' => $state]);

        $this->assertSame([self::BLOG, ['error' => 'suspended']], $this->location());
        $this->assertSame('Octo', $this->db->externalUsers['gh-12345']['display_name']);
        $this->assertSame($tokens, $this->db->externalTokens);
    }

    #[TestDox('コールバック: リダイレクト先にクエリがあれば&でつなぐ')]
    public function testCallbackKeepsExistingQuery(): void
    {
        $state = $this->startLogin('github', 'https://blog.example.com/?page=login');

        $this->callCallback('github', ['error' => 'access_denied', 'state' => $state]);

        $this->assertSame(
            'https://blog.example.com/?page=login&error=access_denied',
            Flight::response()->getHeader('Location')
        );
    }

    // ==================================================
    // POST /auth/exchange — 一時コードの交換
    // ==================================================

    #[TestDox('交換: 正しいcode_verifierなら200、トークンとユーザー情報を返す')]
    public function testExchangeReturnsToken(): void
    {
        $code = $this->prepareCode();

        $this->callExchange(['code' => $code, 'code_verifier' => self::VERIFIER]);

        $this->assertSame(200, $this->responseStatus());
        $data = $this->json()['data'];
        $this->assertSame(['token', 'user_id', 'provider', 'display_name', 'avatar_url'], array_keys($data));
        $this->assertSame('gh-12345', $data['user_id']);
        $this->assertArrayHasKey(HashTools::expandShortUuidV4($data['token']), $this->db->externalTokens);
    }

    #[TestDox('交換: 一時コードは1回しか使えない')]
    public function testExchangeIsSingleUse(): void
    {
        $code = $this->prepareCode();
        $this->callExchange(['code' => $code, 'code_verifier' => self::VERIFIER]);
        $this->assertSame(200, $this->responseStatus());

        Flight::response()->clear();
        $this->callExchange(['code' => $code, 'code_verifier' => self::VERIFIER]);

        $this->assertSame(401, $this->responseStatus());
    }

    #[TestDox('交換: code_verifierが一致しなければ401（一時コードも使用済みになる）')]
    public function testExchangeRejectsWrongVerifier(): void
    {
        $code = $this->prepareCode();

        $this->callExchange(['code' => $code, 'code_verifier' => str_repeat('x', 43)]);

        $this->assertSame(401, $this->responseStatus());
        $this->assertSame([], $this->json()['data']);
        $this->assertArrayNotHasKey($code, $this->db->codes);
    }

    #[TestDox('交換: 停止中のユーザーは401')]
    public function testExchangeRejectsStoppedUser(): void
    {
        $code = $this->prepareCode('2026-11-24 10:00:00');

        $this->callExchange(['code' => $code, 'code_verifier' => self::VERIFIER]);

        $this->assertSame(401, $this->responseStatus());
    }

    public static function invalidExchangeProvider(): array
    {
        return [
            'codeなし' => [['code_verifier' => self::VERIFIER], 400],
            'code_verifierなし' => [['code' => str_repeat('a', 43)], 400],
            '存在しないcode' => [['code' => str_repeat('a', 43), 'code_verifier' => self::VERIFIER], 401],
            '形式が不正なcode' => [['code' => 'あ', 'code_verifier' => self::VERIFIER], 401],
        ];
    }

    #[TestDox('交換: パラメーター不足は400、無効な一時コードは401')]
    #[DataProvider('invalidExchangeProvider')]
    public function testExchangeRejectsInvalidParams(array $body, int $status): void
    {
        $this->callExchange($body);

        $this->assertSame($status, $this->responseStatus());
    }

    // ==================================================
    // 既存のエンドポイントでの外部トークン
    // ==================================================

    #[TestDox('外部トークンで認証が必要なAPIを呼ぶと403（有効期限は延長しない）')]
    public function testExternalTokenIsForbidden(): void
    {
        $this->request('GET', '/api/v1/users', token: $this->loginAsExternal());

        (new \User())->getList();

        $this->assertSame(403, $this->responseStatus());
        $this->assertCount(0, $this->db->executed('UPDATE'));
    }

    #[TestDox('外部トークンでは自分のIDでもパスワードを設定できない（403）')]
    public function testExternalTokenCannotSetPassword(): void
    {
        $this->request('PUT', '/api/v1/users/gh-12345', ['password' => 'Passw0rd!'], token: $this->loginAsExternal());

        (new \User())->execUpdate('gh-12345');

        $this->assertSame(403, $this->responseStatus());
    }

    #[TestDox('停止中の外部ユーザーのトークンは401')]
    public function testStoppedExternalTokenIsUnauthorized(): void
    {
        $this->request('GET', '/api/v1/users', token: $this->loginAsExternal('2026-11-24 10:00:00'));

        (new \User())->getList();

        $this->assertSame(401, $this->responseStatus());
    }
}
