<?php

require_once __DIR__ . "/Base.php";
require_once __DIR__ . "/BaseMethod.php";
require_once __DIR__ . "/../models/UserModel.php";
require_once __DIR__ . "/../models/TokenModel.php";
require_once __DIR__ . "/../validators/UserValidator.php";

/**
 * ユーザー管理クラス
 */
class User extends Base implements BaseMethod
{
    /**
     * 登録時に設定する権限レベル（一般ユーザー）
     * 権限レベルの変更はシステム管理者が`PUT /users/{id}/level`で行う
     */
    private const DEFAULT_USER_LEVEL = "001";

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
            Flight::route('PUT /@id/level', [self::class, 'execUpdateLevel']);
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
        // 認証・権限チェック（取得系: 001以上）
        if (!$this->authorize("001"))
        {
            return;
        }
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
     * ユーザー追加（権限レベルは既定値で登録する）
     *
     * @param string $user_id
     * @param string $password
     * @return integer
     */
    public function add(string $user_id, string $password): int
    {
        return UserModel::add(
            $user_id,
            $this->generateHash($password),
            self::DEFAULT_USER_LEVEL
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
        // 認証・権限チェック（取得系: 001以上）
        if (!$this->authorize("001"))
        {
            return;
        }
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
        // 認証・権限チェック（自分のパスワード: 001以上、自分以外: 111のみ）
        if (
            !$this->authenticate()
            || !$this->checkLevel($user_id === $this->login_user_id ? "001" : "111")
        )
        {
            return;
        }
        // POSTデータ取得・バリデーション（エラーメッセージにパスワード自体は含まれない）
        $validator = new UserValidator();
        $validator->password = $this->getBodyString("password", "");
        $errors = $validator->valid(["password"]);
        if (count($errors) > 0)
        {
            $this->resultJson(["errors" => $errors], 400, $this->route, "パラメーターが正しくありません");
            return;
        }
        // 対象の存在確認（同じ値でUPDATEすると影響行数が0になるため、更新結果では判定しない）
        if (count(UserModel::detail($user_id)) === 0)
        {
            $this->resultJson([], 404, $this->route, "見つかりませんでした");
            return;
        }

        UserModel::update(
            $user_id,
            $this->generateHash($validator->password)
        );

        $this->resultJson(
            [],
            200,
            $this->route,
            "更新できました"
        );
    }

    /**
     * 権限レベルの変更（システム管理者のみ）
     *
     * @param string $user_id
     * @return void
     */
    public function execUpdateLevel(string $user_id): void
    {
        // 認証・権限チェック（システム管理者: 111のみ）
        if (!$this->authorize("111"))
        {
            return;
        }
        // 自分自身の権限レベルは変更できない（管理者が不在になるのを防ぐ）
        if ($user_id === $this->login_user_id)
        {
            $this->resultJson([], 403, $this->route, "自分自身の権限レベルは変更できません");
            return;
        }
        // POSTデータ取得・バリデーション
        $validator = new UserValidator();
        $validator->user_level = $this->getBodyString("user_level", "");
        $errors = $validator->valid(["user_level"]);
        if (count($errors) > 0)
        {
            $this->resultJson(["errors" => $errors], 400, $this->route, "パラメーターが正しくありません");
            return;
        }
        // 対象の存在確認（同じ値でUPDATEすると影響行数が0になるため、更新結果では判定しない）
        if (count(UserModel::detail($user_id)) === 0)
        {
            $this->resultJson([], 404, $this->route, "見つかりませんでした");
            return;
        }

        UserModel::updateLevel($user_id, $validator->user_level);
        // 他の更新系と揃えてユーザー情報は返さない
        $this->resultJson(
            [],
            200,
            $this->route,
            "権限レベルを変更しました"
        );
    }

    /**
     * ユーザーの削除
     *
     * @param string $user_id
     * @return void
     */
    public function execDelete(string $user_id): void
    {
        // 認証・権限チェック（更新系: 010以上）
        if (!$this->authorize("010"))
        {
            return;
        }
        // 対象の存在確認
        if (count(UserModel::detail($user_id)) === 0)
        {
            $this->resultJson([], 404, $this->route, "見つかりませんでした");
            return;
        }

        UserModel::delete($user_id);
        $this->resultJson(
            [],
            200,
            $this->route,
            "削除できました"
        );
    }

    /**
     * ユーザーの追加
     *
     * @return void
     */
    public function execAdd(): void
    {
        // 認証・権限チェック（更新系: 010以上）
        if (!$this->authorize("010"))
        {
            return;
        }
        // POSTデータ取得・バリデーション（エラーメッセージにパスワード自体は含まれない）
        $validator = new UserValidator();
        $validator->id = $this->getBodyString("id", "");
        $validator->password = $this->getBodyString("password", "");
        $errors = $validator->valid(["id", "password"]);
        if (count($errors) > 0)
        {
            $this->resultJson(["errors" => $errors], 400, $this->route, "パラメーターが正しくありません");
            return;
        }
        // ID重複チェック
        if (count(UserModel::detail($validator->id)) > 0)
        {
            $this->resultJson([], 409, $this->route, "このユーザーIDは既に登録されています");
            return;
        }

        // 権限レベルは既定値（001）で登録する
        $this->add($validator->id, $validator->password);
        // 登録したユーザー情報を返す（password_hashは含まない）
        $this->resultJson(
            UserModel::detail($validator->id),
            201,
            $this->route,
            "登録できました"
        );
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
