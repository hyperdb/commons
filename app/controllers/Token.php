<?php

require_once __DIR__ . "/Base.php";
require_once __DIR__ . "/../models/TokenModel.php";

/**
 * コントローラー: トークン
 * 
 * トークンはAPI経由では登録・更新しないため、BaseMethodはimplimentsしない
 * （ユーザークラスの認証で発行される）
 */
class Token
{
    /**
     * ルーティング登録
     *
     * @return void
     */
    public static function registerRoutes(): void
    {
        Flight::group('/tokens', function ()
        {
            Flight::route('POST /valid', [self::class, 'valid']);
        });
    }

    /**
     * トークンの有効性の確認
     *
     * @return void
     */
    public function valid()
    {
        $route = "PUT /api/v1/tokens/valid";
        // POSTデータ取得
        $token = Flight::request()->data->token ?? '';

        if (empty($token))
        {
            // 送信前に確認済みだが念のためどちらかでも入力されていなければBAD REQUEST
            $this->resultJson(
                [],
                400,
                $route,
                "パラメーターが正しくありません"
            );
        }
        else
        {
            $expand_token = $this->expandID($token);
            $row = TokenModel::validToken($expand_token);

            if (count($row) === 0)
            {
                $result = "NG";
                $message = "認証できませんでした";
            }
            else
            {
                TokenModel::expandToken($expand_token);

                $result = "OK";
                $message = "認証OKです";
            }

            $this->resultJson(
                [
                    "valid" => $result
                ],
                200,
                $route,
                $message
            );
        }
    }
}
