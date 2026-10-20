<?php

namespace App\Phpunit;

use App\Shared\Utils\HashTools;
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
