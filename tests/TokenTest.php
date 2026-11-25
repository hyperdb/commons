<?php

namespace App\Phpunit;

use App\Shared\Utils\HashTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * テストクラス: トークン（POST /tokens/valid）
 *
 * 各テストは「準備 → 実行 → 確認」の順で書いている
 */
class TokenTest extends ApiTestCase
{
    /**
     * テスト対象のAPIを呼び出す
     *
     * @param array<string, mixed> $body
     */
    private function callValid(array $body): void
    {
        $this->request('POST', '/api/v1/tokens/valid', $body);
        (new \Token())->valid();
    }

    #[TestDox('トークンを指定しないと400')]
    public function testMissingTokenReturns400(): void
    {
        $this->callValid([]);

        $this->assertSame(400, $this->responseStatus());
    }

    #[TestDox('形式が正しくないトークンは200でNG（DBは検索しない）')]
    public function testMalformedTokenIsNg(): void
    {
        $this->callValid(['token' => 'invalid-token']);

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame('NG', $this->json()['data']['valid']);
        $this->assertArrayNotHasKey('user_id', $this->json()['data']);
        $this->assertCount(0, $this->db->queries);
    }

    #[TestDox('存在しない・期限切れのトークンは200でNG（期限は延長しない）')]
    public function testUnknownTokenIsNg(): void
    {
        // 準備: DBに有効なトークンがない状態
        $this->db->tokenOwner = null;

        $this->callValid(['token' => HashTools::getShortUuidV4()]);

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame('NG', $this->json()['data']['valid']);
        $this->assertArrayNotHasKey('user_id', $this->json()['data']);
        $this->assertFalse($this->tokenExtended());
    }

    #[TestDox('有効なトークンは200でOK、所有ユーザーIDを返し、有効期限を延長する')]
    public function testValidTokenIsOkAndExtended(): void
    {
        $token = $this->loginAs('001', 'bob');

        $this->callValid(['token' => $token]);

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame('OK', $this->json()['data']['valid']);
        $this->assertSame('bob', $this->json()['data']['user_id']);
        $this->assertTrue($this->tokenExtended());
        // 延長対象は、短縮トークンを復元したUUID
        $extend = $this->db->executed('UPDATE tokens SET expired_at')[0];
        $this->assertSame([HashTools::expandShortUuidV4($token)], $extend['params']);
    }

    #[TestDox('有効なトークン（パスワードユーザー）には外部ユーザー用の項目を含めない')]
    public function testPasswordUserHasNoExternalItems(): void
    {
        $this->callValid(['token' => $this->loginAs('001', 'bob')]);

        $this->assertSame(['valid', 'user_id'], array_keys($this->json()['data']));
    }

    #[TestDox('外部ユーザーの有効なトークンは200でOK、表示名などを返し、有効期限は延長しない')]
    public function testExternalTokenIsOkAndNotExtended(): void
    {
        $this->callValid(['token' => $this->loginAsExternal(), 'allow_external' => true]);

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame([
            'valid' => 'OK',
            'user_id' => 'gh-12345',
            'provider' => 'github',
            'display_name' => 'Octo',
            'avatar_url' => 'https://example.com/a.png',
        ], $this->json()['data']);
        $this->assertFalse($this->tokenExtended());
        $this->assertCount(0, $this->db->executed('UPDATE'));
    }

    public static function externalNotAllowedProvider(): array
    {
        return [
            '指定なし' => [[]],
            'false' => [['allow_external' => false]],
            '文字列のtrue' => [['allow_external' => 'true']],
            '数値の1' => [['allow_external' => 1]],
            '配列' => [['allow_external' => [true]]],
        ];
    }

    #[TestDox('allow_externalがtrueでなければ、外部ユーザーの有効なトークンでもNG（ユーザー情報も返さない）')]
    #[DataProvider('externalNotAllowedProvider')]
    public function testExternalTokenIsNgUnlessAllowed(array $body): void
    {
        $this->callValid(['token' => $this->loginAsExternal(), ...$body]);

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame(['valid' => 'NG'], $this->json()['data']);
    }

    #[TestDox('allow_externalを指定しても、パスワードユーザーの応答は変わらない')]
    public function testAllowExternalDoesNotAffectPasswordUser(): void
    {
        $this->callValid(['token' => $this->loginAs('001', 'bob'), 'allow_external' => true]);

        $this->assertSame(['valid' => 'OK', 'user_id' => 'bob'], $this->json()['data']);
        $this->assertTrue($this->tokenExtended());
    }

    #[TestDox('停止中の外部ユーザーのトークンは200でNG')]
    public function testStoppedExternalTokenIsNg(): void
    {
        $this->callValid(['token' => $this->loginAsExternal('2026-11-24 10:00:00'), 'allow_external' => true]);

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame(['valid' => 'NG'], $this->json()['data']);
    }

    #[TestDox('レスポンスは共通形式（api_version / api_route / send_at / message / data）')]
    public function testResponseHasCommonFormat(): void
    {
        $this->callValid(['token' => $this->loginAs('001')]);

        $json = $this->json();
        foreach (['api_version', 'api_route', 'send_at', 'message', 'data'] as $key)
        {
            $this->assertArrayHasKey($key, $json);
        }
        $this->assertSame('/api/v1/tokens/valid', $json['api_route']);
    }
}
