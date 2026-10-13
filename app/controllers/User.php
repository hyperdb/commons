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
        // クエリパラメータの取得（不正な値は補正済み）
        $paging = $this->getPagingParams();
        // 一覧の取得
        $list = UserModel::list(
            $paging["page"] * $paging["rows"],
            $paging["rows"]
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
     * @param string $user_id
     * @param string $password
     * @return integer
     */
    public function add(string $user_id, string $password): int
    {
        return UserModel::add(
            $user_id,
            $this->generateHash($password)
        );
    }

    /**
     * ユーザー詳細の取得
     *
     * @param string $user_id
     * @return void
     */
    public function getDetail(string $user_id): void
    {
        // 詳細の取得
        $user = UserModel::detail($user_id);
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
     * @param string $user_id
     * @return void
     */
    public function execUpdate(string $user_id): void
    {
        // POSTデータ取得
        $password = $this->getBodyString("password", "");

        if (empty($user_id) || empty($password))
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
                $user_id,
                $this->generateHash($password)
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
     * @param string $user_id
     * @return void
     */
    public function execDelete(string $user_id): void
    {
        if (empty($user_id))
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
            UserModel::delete($user_id);
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
        // POSTデータ取得
        $id = $this->getBodyString("id", "");
        $password = $this->getBodyString("password", "");

        if (empty($id) || empty($password))
        {
            // 送信前に確認済みだが念のためどちらかでも入力されていなければBAD REQUEST
            // （パスワードはレスポンスに含めない）
            $this->resultJson(
                [],
                400,
                $this->route,
                "パラメーターが正しくありません"
            );
        }
        else
        {
            UserModel::add(
                $id,
                $this->generateHash($password)
            );
            // 登録したユーザー情報を返す（password_hashは含まない）
            $this->resultJson(
                UserModel::detail($id),
                201,
                $this->route,
                "登録できました"
            );
        }
    }

    /**
     * ユーザートークンを追加する
     * 
     * @param string $user_id
     * @return string
     */
    public function addToken(string $user_id): string
    {
        $uuid = $this->generateUUID();

        TokenModel::clearExpiredToken();
        TokenModel::addToken($user_id, $uuid);

        return $this->shortenID($uuid);
    }

    /**
     * ユーザー認証
     *
     * @param string $user_id
     * @return void
     */
    public function execAuth(string $user_id): void
    {
        // POSTデータ取得
        $password = $this->getBodyString("password", "");

        // パスワードハッシュを取得（ユーザーが存在しなければ空文字）
        $password_hash = UserModel::findPasswordHash($user_id);
        // 入力されたパスワードでVerifyする
        if ($password_hash !== "" && password_verify($password, $password_hash))
        {
            $token = $this->addToken($user_id);
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
