<?php

/**
 * 外部ログインのプロバイダー（GitHub／Google）
 *
 * プロバイダーごとの差異（URL・スコープ・ユーザー情報の項目名）を吸収する
 */
class OAuthProvider
{
    /** プロバイダーごとの設定 */
    private const CONFIG = [
        'github' => [
            'env' => 'GITHUB',
            'authorize_url' => 'https://github.com/login/oauth/authorize',
            'token_url' => 'https://github.com/login/oauth/access_token',
            'user_url' => 'https://api.github.com/user',
            'scope' => '',
        ],
        'google' => [
            'env' => 'GOOGLE',
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'user_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scope' => 'openid profile',
        ],
    ];

    private string $name;
    private string $client_id;
    private string $client_secret;
    private string $callback_url;

    /**
     * 対応しているプロバイダーか
     *
     * @param string $name
     * @return boolean
     */
    public static function isSupported(string $name): bool
    {
        return isset(self::CONFIG[$name]);
    }

    /**
     * @param string $name プロバイダー（isSupported()で確認済みのもの）
     * @throws RuntimeException .envの設定値が不足している場合
     */
    public function __construct(string $name)
    {
        $this->name = $name;
        $this->client_id = $this->env('CLIENT_ID');
        $this->client_secret = $this->env('CLIENT_SECRET');
        $this->callback_url = $this->env('CALLBACK_URL');
    }

    /**
     * 認可画面のURLを作成する
     *
     * @param string $state
     * @param string $code_verifier
     * @return string
     */
    public function authorizeUrl(string $state, string $code_verifier): string
    {
        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->callback_url,
            'response_type' => 'code',
            'state' => $state,
            'code_challenge' => self::challenge($code_verifier),
            'code_challenge_method' => 'S256',
        ];
        $scope = self::CONFIG[$this->name]['scope'];
        if ($scope !== '')
        {
            $params['scope'] = $scope;
        }
        return self::CONFIG[$this->name]['authorize_url'] . '?' . http_build_query($params);
    }

    /**
     * 認可コードからユーザー情報を取得する（アクセストークンは保存しない）
     *
     * @param string $code
     * @param string $code_verifier
     * @return array{id: string, display_name: string, avatar_url: string}
     * @throws RuntimeException 通信エラー・想定外の応答の場合
     */
    public function fetchUser(string $code, string $code_verifier): array
    {
        $headers = [
            'Accept: application/json',
            // GitHubのAPIはUser-Agentが必須
            'User-Agent: php-client',
        ];
        $token = $this->decode(Flight::httpClient()->post(
            self::CONFIG[$this->name]['token_url'],
            [
                'grant_type' => 'authorization_code',
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'code' => $code,
                'redirect_uri' => $this->callback_url,
                'code_verifier' => $code_verifier,
            ],
            $headers
        ));
        $access_token = $token['access_token'] ?? null;
        // GitHubはエラーでも200を返すため、アクセストークンの有無で判定する
        if (!is_string($access_token) || $access_token === '')
        {
            throw new RuntimeException("{$this->name}: access_token is missing");
        }

        $user = $this->decode(Flight::httpClient()->get(
            self::CONFIG[$this->name]['user_url'],
            [...$headers, 'Authorization: Bearer ' . $access_token]
        ));
        return $this->name === 'github' ? $this->githubUser($user) : $this->googleUser($user);
    }

    /**
     * PKCEのcode_challenge（SHA-256のBase64URL）を計算する
     *
     * @param string $code_verifier
     * @return string 43文字
     */
    public static function challenge(string $code_verifier): string
    {
        return self::base64Url(hash('sha256', $code_verifier, true));
    }

    /**
     * 推測できないランダム文字列を生成する（state・code_verifier・一時コード用）
     *
     * @return string 43文字
     */
    public static function randomString(): string
    {
        return self::base64Url(random_bytes(32));
    }

    /**
     * Base64URL（パディングなし）に変換する
     *
     * @param string $binary
     * @return string
     */
    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /**
     * .envの設定値を取得する
     *
     * @param string $key
     * @return string
     * @throws RuntimeException
     */
    private function env(string $key): string
    {
        $name = self::CONFIG[$this->name]['env'] . '_' . $key;
        $value = strval($_ENV[$name] ?? '');
        if ($value === '')
        {
            throw new RuntimeException("{$name} is not set in .env");
        }
        return $value;
    }

    /**
     * 応答のJSONを配列にする
     * （例外のメッセージには、秘密情報を含む可能性がある応答の本文を入れない）
     *
     * @param array{status: int, body: string} $response
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    private function decode(array $response): array
    {
        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($data))
        {
            throw new RuntimeException("{$this->name}: unexpected response (status {$response['status']})");
        }
        return $data;
    }

    /**
     * GitHubのユーザー情報
     *
     * @param array<string, mixed> $user
     * @return array{id: string, display_name: string, avatar_url: string}
     * @throws RuntimeException
     */
    private function githubUser(array $user): array
    {
        $id = $user['id'] ?? null;
        if (!is_int($id) || $id <= 0)
        {
            throw new RuntimeException('github: user id is invalid');
        }
        // 表示名が未設定ならユーザー名を使う
        $name = self::displayName($user['name'] ?? null);
        return [
            'id' => strval($id),
            'display_name' => $name !== '' ? $name : self::displayName($user['login'] ?? null),
            'avatar_url' => self::avatarUrl($user['avatar_url'] ?? null),
        ];
    }

    /**
     * Googleのユーザー情報
     *
     * @param array<string, mixed> $user
     * @return array{id: string, display_name: string, avatar_url: string}
     * @throws RuntimeException
     */
    private function googleUser(array $user): array
    {
        $sub = $user['sub'] ?? null;
        if (!is_string($sub) || !preg_match('/^[\x21-\x7e]{1,255}\z/', $sub))
        {
            throw new RuntimeException('google: sub is invalid');
        }
        return [
            'id' => $sub,
            'display_name' => self::displayName($user['name'] ?? null),
            'avatar_url' => self::avatarUrl($user['picture'] ?? null),
        ];
    }

    /**
     * 表示名を整える（制御文字を除き、列の長さに収める）
     *
     * @param mixed $value
     * @return string
     */
    private static function displayName(mixed $value): string
    {
        $name = is_string($value) ? preg_replace('/\p{Cc}+/u', ' ', $value) : '';
        return mb_substr(trim($name ?? ''), 0, 255);
    }

    /**
     * アバターURLを整える（HTTPSのURLで列に収まるもの以外は空にする）
     *
     * @param mixed $value
     * @return string
     */
    private static function avatarUrl(mixed $value): string
    {
        return is_string($value) && preg_match('/^https:\/\/[\x21-\x7e]{1,2000}\z/', $value) ? $value : '';
    }
}
