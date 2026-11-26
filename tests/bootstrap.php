<?php

/**
 * テストの共通準備（phpunit.xmlのbootstrapで読み込まれる）
 *
 * - DBには接続せず、StubDb（偽のDB）で応答を差し替える
 * - テストごとにFlightのエンジンを作り直し、前のテストの状態を残さない
 */

namespace App\Phpunit;

use App\Shared\Utils\HashTools;
use flight\Engine;
use flight\util\Collection;
use Flight;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../vendor/autoload.php';

// index.phpで定義している定数（テストではindex.phpを読み込まないため、グローバル定数として定義）
if (!defined('API_VERSION'))
{
    define('API_VERSION', 'test');
}

require_once __DIR__ . '/../app/controllers/User.php';
require_once __DIR__ . '/../app/controllers/Token.php';

/**
 * 偽のDB（Flight::db()の代わり）
 *
 * 実行されたSQLを記録し、users・tokensの内容を配列で模擬する
 * ※SQL自体は実行しないため、SQLの誤字などは検出できない
 */
class StubDb
{
    /** @var array<int, array{sql: string, params: array}> 実行されたSQLの記録 */
    public array $queries = [];

    /** @var array<string, array<string, string>> ユーザー（キーはユーザーID） */
    public array $users = [];

    /** @var array{owner: string, user_level: string}|null 有効なトークンの所有者（nullなら無効なトークン） */
    public ?array $tokenOwner = null;

    public function fetchRow(string $sql, array $params = []): ?Collection
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        // TokenModel::findOwner
        if (str_contains($sql, 'FROM tokens t'))
        {
            return $this->tokenOwner === null ? null : new Collection($this->tokenOwner);
        }
        // UserModel::detail / findPasswordHash
        if (str_contains($sql, 'FROM users WHERE id = ?'))
        {
            $user = $this->users[$params[0]] ?? null;
            if ($user === null)
            {
                return null;
            }
            if (str_starts_with($sql, 'SELECT password_hash'))
            {
                return new Collection(['password_hash' => $user['password_hash']]);
            }
            unset($user['password_hash']);
            return new Collection($user);
        }
        return null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        // UserModel::list（password_hashを除いて返す）
        return array_values(array_map(function ($user)
        {
            unset($user['password_hash']);
            return $user;
        }, $this->users));
    }

    public function runQuery(string $sql, array $params = []): object
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        // UserModel::add
        if (str_starts_with($sql, 'INSERT INTO users'))
        {
            $this->addUser($params[0], $params[1], $params[2]);
        }
        // UserModel::updateLevel
        if (str_starts_with($sql, 'UPDATE users SET user_level'))
        {
            $this->users[$params[1]]['user_level'] = $params[0];
        }

        return new class
        {
            public function rowCount(): int
            {
                return 1;
            }
        };
    }

    /**
     * ユーザーを登録する（テストの準備用）
     */
    public function addUser(string $id, string $passwordHash, string $userLevel): void
    {
        $this->users[$id] = [
            'id' => $id,
            'password_hash' => $passwordHash,
            'user_level' => $userLevel,
            'created_at' => '2026-10-14 10:00:00',
            'updated_at' => '2026-10-14 10:00:00',
        ];
    }

    /**
     * 指定した文字列で始まるSQLの記録を取得する
     *
     * @return array<int, array{sql: string, params: array}>
     */
    public function executed(string $sqlPrefix): array
    {
        return array_values(array_filter(
            $this->queries,
            fn($query) => str_starts_with(trim($query['sql']), $sqlPrefix)
        ));
    }
}

/**
 * APIテストの基底クラス
 */
abstract class ApiTestCase extends TestCase
{
    protected StubDb $db;

    /**
     * 各テストの前に実行される（Flightと偽のDBを作り直す）
     */
    protected function setUp(): void
    {
        Flight::setEngine(new Engine());
        $this->db = new StubDb();
        Flight::map('db', fn() => $this->db);

        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * リクエストを準備する（コントローラーを生成する前に呼ぶこと）
     *
     * @param array<string, mixed> $body JSONボディ
     * @param array<string, string> $query クエリパラメータ
     * @param string|null $token Bearerトークン（nullならAuthorizationヘッダーなし）
     */
    protected function request(
        string $method,
        string $uri,
        array $body = [],
        array $query = [],
        ?string $token = null
    ): void {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_GET = $query;
        if ($token !== null)
        {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        Flight::request()->data->setData($body);
    }

    /**
     * 指定した権限レベルのユーザーでログインしている状態にする
     *
     * @return string 有効な短縮トークン
     */
    protected function loginAs(string $userLevel, string $userId = 'alice'): string
    {
        $this->db->tokenOwner = ['owner' => $userId, 'user_level' => $userLevel];
        return HashTools::getShortUuidV4();
    }

    /**
     * レスポンスのHTTPステータス
     */
    protected function responseStatus(): int
    {
        return Flight::response()->status();
    }

    /**
     * レスポンスのJSONを配列にしたもの
     *
     * @return array<string, mixed>
     */
    protected function json(): array
    {
        return json_decode(Flight::response()->getBody(), true) ?? [];
    }

    /**
     * トークンの有効期限が延長されたか
     */
    protected function tokenExtended(): bool
    {
        return count($this->db->executed('UPDATE tokens SET expired_at')) > 0;
    }
}
