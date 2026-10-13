<?php

require_once __DIR__ . "/Base.php";
require_once __DIR__ . "/BaseMethod.php";
require_once __DIR__ . "/../models/UserModel.php";
require_once __DIR__ . "/../models/TokenModel.php";

/**
 * ユーザー管理クラス
 */
class User extends Base implements BaseMethod
{
    /**
     * ルーティング
     *
     * @return void
     */
    public static function registerRoutes(): void
    {
        Flight::group('/users', function ()
        {
            Flight::route('POST /auth/@id', [self::class, 'execAuth']);
            Flight::route('GET /@id',       [self::class, 'getDetail']);
            Flight::route('PUT /@id',       [self::class, 'execUpdate']);
            Flight::route('DELETE /@id',    [self::class, 'execDelete']);
            Flight::route('GET /',          [self::class, 'getList']);
            Flight::route('POST /',         [self::class, 'execAdd']);
        });
    }

    /**
     * ユーザー一覧の取得
     *
     * @return void
     */
    public function getList(): void
    {
        // クエリパラメータの取得
        $pageNo = $this->getIntParam("page", 0);
        $rows = $this->getIntParam("rows", 10);
        // 一覧の取得
        $list = UserModel::list(
            $pageNo * $rows,
            $rows
        );
        // 取得件数が0件でもOKにする
        $this->resultJson(
            $list,
            200,
            $this->route,
            "一覧が取得できました"
        );
    }

    /**
     * ユーザー追加
     *
     * @param string $username
     * @param string $password
     * @return integer
     */
    public function add(string $username, string $password): int
    {
        return UserModel::add(
            $username,
            $this->generateHash($password)
        );
    }

    /**
     * ユーザー詳細の取得
     *
     * @param string $username
     * @return void
     */
    public function getDetail(string $username): void
    {
        // 詳細の取得
        $user = UserModel::detail($username);
        if (count($user) > 0)
        {
            $this->resultJson(
                $user,
                200,
                $this->route,
                "取得できました"
            );
        }
        else
        {
            // IDを指定しているので取得できなければ404エラー
            $this->resultJson(
                [],
                404,
                $this->route,
                "見つかりませんでした"
            );
        }
    }

    /**
     * ユーザーの更新
     *
     * @param string $username
     * @return void
     */
    public function execUpdate(string $username): void
    {
        // POSTデータ取得
        $password = $this->getStringParam("password", "");

        if (empty($username) || empty($password))
        {
            // 送信前に確認済みだが念のためどちらかでも入力されていなければBAD REQUEST
            $this->resultJson(
                [],
                400,
                $this->route,
                "パラメーターが正しくありません"
            );
        }
        else
        {
            UserModel::update(
                $username,
                User::generateHash($password)
            );

            $this->resultJson(
                [],
                200,
                $this->route,
                "更新できました"
            );
        }
    }

    /**
     * ユーザーの削除
     *
     * @param string $username
     * @return void
     */
    public function execDelete(string $username): void
    {
        if (empty($username))
        {
            // 送信前に確認済みだが念のため入力されていなければBAD REQUEST
            $this->resultJson(
                [],
                400,
                $this->route,
                "パラメーターが正しくありません"
            );
        }
        else
        {
            UserModel::delete($username);
            $this->resultJson(
                [],
                200,
                $this->route,
                "削除できました"
            );
        }
    }

    /**
     * ユーザーの追加
     *
     * @return void
     */
    public function execAdd(): void
    {
        $route = "POST /api/v1/users/";
        // POSTデータ取得
        $username = $this->getStringParam("username", "");
        $password = $this->getStringParam("password", "");

        $data = [
            "username" => $username ?? '',
            "password" => $password ?? ''
        ];

        if (empty($username) || empty($password))
        {
            // 送信前に確認済みだが念のためどちらかでも入力されていなければBAD REQUEST
            $this->resultJson(
                $data,
                400,
                $this->route,
                "パラメーターが正しくありません"
            );
        }
        else
        {
            UserModel::add(
                $username,
                $this->generateHash($password)
            );
            $this->resultJson(
                $data,
                200,
                $this->route,
                "登録できました"
            );
        }
    }

    /**
     * ユーザートークンを追加する
     * 
     * @param string $username
     * @return string
     */
    public function addToken(string $username): string
    {
        $uuid = $this->generateUUID();

        TokenModel::clearExpiredToken();
        TokenModel::addToken($username, $uuid);

        return $this->shortenID($uuid);
    }

    /**
     * ユーザー認証
     *
     * @param string $username
     * @return void
     */
    public function execAuth(string $username): void
    {
        $route = "POST /api/v1/users/auth/" . $username;
        // POSTデータ取得
        $password = $this->getStringParam("password", "");

        // ユーザーデータを取得
        $user = UserModel::detail($username);
        // 入力されたパスワードでVerifyする
        if (count($user) > 0 && password_verify($password, $user["password_hash"]))
        {
            $token = $this->addToken($username);
            $this->resultJson(
                [
                    "token" => $token
                ],
                200,
                $this->route,
                "認証OKです"
            );
        }
        else
        {
            // 認証エラー
            $this->resultJson(
                [],
                401,
                $this->route,
                "認証できませんでした"
            );
        }
    }
}
