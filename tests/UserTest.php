<?php

namespace App\Phpunit;

use App\Shared\Utils\HashTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * テストクラス: ユーザー（/users）
 *
 * 各テストは「準備 → 実行 → 確認」の順で書いている
 * ログインユーザーは`alice`、操作対象の他人は`bob`とする
 */
class UserTest extends ApiTestCase
{
    /** テスト用パスワード（仕様のルールを満たすもの） */
    private const PASSWORD = 'Passw0rd!';

    protected function setUp(): void
    {
        parent::setUp();

        // 準備: alice（自分）とbob（他人）を登録しておく
        // テストを速くするため、bcryptのコストを最小にしている
        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->db->addUser('alice', $hash, '001');
        $this->db->addUser('bob', $hash, '001');
    }

    // ==================================================
    // 認証（トークンチェック）
    // ==================================================

    #[TestDox('Authorizationヘッダーがないと401')]
    public function testNoTokenReturns401(): void
    {
        $this->request('GET', '/api/v1/users');

        (new \User())->getList();

        $this->assertSame(401, $this->responseStatus());
    }

    #[TestDox('形式が正しくないトークンは401')]
    public function testMalformedTokenReturns401(): void
    {
        $this->request('GET', '/api/v1/users', token: 'invalid-token');

        (new \User())->getList();

        $this->assertSame(401, $this->responseStatus());
    }

    #[TestDox('存在しない・期限切れのトークンは401（期限は延長しない）')]
    public function testUnknownTokenReturns401(): void
    {
        $this->db->tokenOwner = null;
        $this->request('GET', '/api/v1/users', token: HashTools::getShortUuidV4());

        (new \User())->getList();

        $this->assertSame(401, $this->responseStatus());
        $this->assertFalse($this->tokenExtended());
    }

    #[TestDox('権限レベル000のGETは403、ただしトークンの期限は延長する')]
    public function testLevel000Returns403AndExtendsToken(): void
    {
        $this->request('GET', '/api/v1/users', token: $this->loginAs('000'));

        (new \User())->getList();

        $this->assertSame(403, $this->responseStatus());
        $this->assertTrue($this->tokenExtended());
    }

    /**
     * 権限レベルごとの登録・削除の可否
     *
     * @return array<string, array{string, int}>
     */
    public static function updateLevelProvider(): array
    {
        return [
            '001は不可' => ['001', 403],
            '010は可' => ['010', 200],
            '111は可' => ['111', 200],
        ];
    }

    #[DataProvider('updateLevelProvider')]
    #[TestDox('削除（DELETE）は権限レベル010以上: $level → $expected')]
    public function testDeleteRequiresLevel010(string $level, int $expected): void
    {
        $this->request('DELETE', '/api/v1/users/bob', token: $this->loginAs($level));

        (new \User())->execDelete('bob');

        $this->assertSame($expected, $this->responseStatus());
    }

    // ==================================================
    // POST /users/auth/{id} — ログイン認証
    // ==================================================

    #[TestDox('ログイン認証: 正しいパスワードなら22文字の短縮トークンを返す')]
    public function testAuthSuccessReturnsToken(): void
    {
        $this->request('POST', '/api/v1/users/auth/alice', ['password' => self::PASSWORD]);

        (new \User())->execAuth('alice');

        $this->assertSame(200, $this->responseStatus());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $this->json()['data']['token']);
        // トークンはaliceの所有として登録される
        $insert = $this->db->executed('INSERT INTO tokens')[0];
        $this->assertSame('alice', $insert['params'][1]);
    }

    #[TestDox('ログイン認証: パスワード違いと存在しないIDは同じ401応答')]
    public function testAuthFailureLooksTheSame(): void
    {
        $this->request('POST', '/api/v1/users/auth/alice', ['password' => 'Wrong0pass!']);
        (new \User())->execAuth('alice');
        $wrongPassword = [$this->responseStatus(), $this->json()['message'], $this->json()['data']];

        // 2回目のリクエストのためにFlightを作り直す
        $this->setUp();
        $this->request('POST', '/api/v1/users/auth/nobody', ['password' => self::PASSWORD]);
        (new \User())->execAuth('nobody');
        $unknownUser = [$this->responseStatus(), $this->json()['message'], $this->json()['data']];

        $this->assertSame(401, $wrongPassword[0]);
        $this->assertSame($wrongPassword, $unknownUser);
        $this->assertCount(0, $this->db->executed('INSERT INTO tokens'));
    }

    // ==================================================
    // GET /users — 一覧取得
    // ==================================================

    #[TestDox('一覧: 権限レベル001で取得でき、password_hashを含まない')]
    public function testListReturnsUsersWithoutPasswordHash(): void
    {
        $this->request('GET', '/api/v1/users', token: $this->loginAs('001'));

        (new \User())->getList();

        $this->assertSame(200, $this->responseStatus());
        $this->assertCount(2, $this->json()['data']);
        foreach ($this->json()['data'] as $user)
        {
            $this->assertArrayNotHasKey('password_hash', $user);
        }
    }

    #[TestDox('一覧: 不正なpage/rowsは補正される（page=-1→0、rows=0→10）')]
    public function testListCorrectsPagingParams(): void
    {
        $this->request('GET', '/api/v1/users', query: ['page' => '-1', 'rows' => '0'], token: $this->loginAs('001'));

        (new \User())->getList();

        // LIMIT ?, ? に渡される値は [開始位置, 件数]
        $select = $this->db->executed('SELECT id, user_level')[0];
        $this->assertSame([0, 10], $select['params']);
    }

    #[TestDox('一覧: page=2&rows=5なら6件目（開始位置10）から5件')]
    public function testListPaging(): void
    {
        $this->request('GET', '/api/v1/users', query: ['page' => '2', 'rows' => '5'], token: $this->loginAs('001'));

        (new \User())->getList();

        $select = $this->db->executed('SELECT id, user_level')[0];
        $this->assertSame([10, 5], $select['params']);
    }

    // ==================================================
    // GET /users/{id} — 詳細取得
    // ==================================================

    #[TestDox('詳細: 存在するユーザーは200、password_hashを含まない')]
    public function testDetailReturnsUser(): void
    {
        $this->request('GET', '/api/v1/users/bob', token: $this->loginAs('001'));

        (new \User())->getDetail('bob');

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame('bob', $this->json()['data']['id']);
        $this->assertArrayNotHasKey('password_hash', $this->json()['data']);
    }

    #[TestDox('詳細: 存在しないユーザーは404')]
    public function testDetailNotFound(): void
    {
        $this->request('GET', '/api/v1/users/nobody', token: $this->loginAs('001'));

        (new \User())->getDetail('nobody');

        $this->assertSame(404, $this->responseStatus());
    }

    // ==================================================
    // POST /users — 登録
    // ==================================================

    #[TestDox('登録: 成功すると201、権限レベルは001で登録される')]
    public function testAddCreatesUserWithDefaultLevel(): void
    {
        // user_levelを送っても無視される
        $body = ['id' => 'carol', 'password' => self::PASSWORD, 'user_level' => '111'];
        $this->request('POST', '/api/v1/users', $body, token: $this->loginAs('010'));

        (new \User())->execAdd();

        $this->assertSame(201, $this->responseStatus());
        $this->assertSame('carol', $this->json()['data']['id']);
        $this->assertSame('001', $this->json()['data']['user_level']);
        $this->assertArrayNotHasKey('password_hash', $this->json()['data']);
        // パスワードはハッシュ化して保存される
        $this->assertTrue(password_verify(self::PASSWORD, $this->db->users['carol']['password_hash']));
    }

    #[TestDox('登録: 権限レベル001は403')]
    public function testAddRequiresLevel010(): void
    {
        $body = ['id' => 'carol', 'password' => self::PASSWORD];
        $this->request('POST', '/api/v1/users', $body, token: $this->loginAs('001'));

        (new \User())->execAdd();

        $this->assertSame(403, $this->responseStatus());
        $this->assertArrayNotHasKey('carol', $this->db->users);
    }

    #[TestDox('登録: パスワードがないと400、レスポンスにパスワードを含めない')]
    public function testAddWithoutPasswordReturns400(): void
    {
        $this->request('POST', '/api/v1/users', ['id' => 'carol'], token: $this->loginAs('010'));

        (new \User())->execAdd();

        $this->assertSame(400, $this->responseStatus());
        $this->assertStringNotContainsString('password', \Flight::response()->getBody());
    }

    // ==================================================
    // PUT /users/{id} — パスワード変更
    // ==================================================

    /**
     * パスワード変更の権限（自分は001以上、他人は111のみ）
     *
     * @return array<string, array{string, string, int}>
     */
    public static function passwordChangeProvider(): array
    {
        return [
            '001が自分を変更 → 可' => ['001', 'alice', 200],
            '001が他人を変更 → 不可' => ['001', 'bob', 403],
            '010が他人を変更 → 不可' => ['010', 'bob', 403],
            '111が他人を変更 → 可' => ['111', 'bob', 200],
        ];
    }

    #[DataProvider('passwordChangeProvider')]
    #[TestDox('パスワード変更: 権限$level・対象$target → $expected')]
    public function testPasswordChangePermission(string $level, string $target, int $expected): void
    {
        $body = ['password' => 'NewPassw0rd!'];
        $this->request('PUT', "/api/v1/users/$target", $body, token: $this->loginAs($level));

        (new \User())->execUpdate($target);

        $this->assertSame($expected, $this->responseStatus());
        $this->assertCount($expected === 200 ? 1 : 0, $this->db->executed('UPDATE users SET password_hash'));
    }

    #[TestDox('パスワード変更: パスワードがないと400')]
    public function testPasswordChangeWithoutPasswordReturns400(): void
    {
        $this->request('PUT', '/api/v1/users/alice', [], token: $this->loginAs('001'));

        (new \User())->execUpdate('alice');

        $this->assertSame(400, $this->responseStatus());
    }

    // ==================================================
    // PUT /users/{id}/level — 権限レベルの変更
    // ==================================================

    #[TestDox('権限変更: システム管理者が他人の権限を変更できる')]
    public function testLevelChangeByAdmin(): void
    {
        $this->request('PUT', '/api/v1/users/bob/level', ['user_level' => '010'], token: $this->loginAs('111'));

        (new \User())->execUpdateLevel('bob');

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame('010', $this->json()['data']['user_level']);
        $this->assertSame('010', $this->db->users['bob']['user_level']);
    }

    #[TestDox('権限変更: システム管理者以外（010）は403')]
    public function testLevelChangeRequiresAdmin(): void
    {
        $this->request('PUT', '/api/v1/users/bob/level', ['user_level' => '010'], token: $this->loginAs('010'));

        (new \User())->execUpdateLevel('bob');

        $this->assertSame(403, $this->responseStatus());
        $this->assertSame('001', $this->db->users['bob']['user_level']);
    }

    #[TestDox('権限変更: 自分自身の権限は変更できない（403）')]
    public function testLevelChangeOfSelfIsForbidden(): void
    {
        $this->request('PUT', '/api/v1/users/alice/level', ['user_level' => '001'], token: $this->loginAs('111'));

        (new \User())->execUpdateLevel('alice');

        $this->assertSame(403, $this->responseStatus());
        $this->assertCount(0, $this->db->executed('UPDATE users SET user_level'));
    }

    /**
     * 不正な権限レベルの値
     *
     * @return array<string, array{mixed}>
     */
    public static function invalidLevelProvider(): array
    {
        return [
            '未定義の値' => ['999'],
            '桁数違い' => ['1'],
            '4桁' => ['1111'],
            '空文字' => [''],
            '配列' => [['111']],
        ];
    }

    #[DataProvider('invalidLevelProvider')]
    #[TestDox('権限変更: 不正な値は400（data.errors.user_level にエラー）')]
    public function testLevelChangeWithInvalidValue(mixed $value): void
    {
        $this->request('PUT', '/api/v1/users/bob/level', ['user_level' => $value], token: $this->loginAs('111'));

        (new \User())->execUpdateLevel('bob');

        $this->assertSame(400, $this->responseStatus());
        $this->assertArrayHasKey('user_level', $this->json()['data']['errors']);
        $this->assertCount(0, $this->db->executed('UPDATE users SET user_level'));
    }

    #[TestDox('権限変更: 存在しないユーザーは404')]
    public function testLevelChangeNotFound(): void
    {
        $this->request('PUT', '/api/v1/users/nobody/level', ['user_level' => '010'], token: $this->loginAs('111'));

        (new \User())->execUpdateLevel('nobody');

        $this->assertSame(404, $this->responseStatus());
    }
}
