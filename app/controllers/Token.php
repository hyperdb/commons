<?php

require_once __DIR__ . "/Base.php";
require_once __DIR__ . "/../models/TokenModel.php";

/**
 * コントローラー: トークン
 * 
 * トークンはAPI経由では登録・更新しないため、BaseMethodはimplimentsしない
 * （ユーザークラスの認証で発行される）
 */
class Token extends Base
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
    public function valid(): void
    {
        // POSTデータ取得
        $token = $this->getBodyString("token", "");

        if (empty($token))
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
            $owner = $this->findTokenOwner($token);
            if (count($owner) > 0)
            {
                // 有効な場合は有効期限を延長する
                TokenModel::extendToken($this->expandID($token));

                // 有効な場合のみ所有ユーザーIDを返す
                $data = [
                    "valid" => "OK",
                    "user_id" => strval($owner["owner"])
                ];
                $message = "認証OKです";
            }
            else
            {
                $data = [
                    "valid" => "NG"
                ];
                $message = "認証できませんでした";
            }

            $this->resultJson(
                $data,
                200,
                $this->route,
                $message
            );
        }
    }
}
