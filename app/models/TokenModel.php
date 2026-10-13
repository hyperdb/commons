<?php

require_once __DIR__ . '/BaseModel.php';


/**
 * モデル: トークン
 */
class TokenModel
{
    /**
     * トークンの有効性をチェック
     * 
     * @oaram string $uuid (短縮化したものではない)
     * @return int
     */
    public static function checkToken(string $uuid): int
    {
        $sql = <<< EOS
SELECT COUNT(*) cnt
  FROM tokens
 WHERE token = ?
   AND expired_at >= UNIX_TIMESTAMP()
EOS;
        $row = BaseModel::fetchRow($sql, [
            $uuid
        ]);
        return $row ? $row['cnt'] : 0;
    }

    /**
     * ユーザートークンを追加する
     * 
     * @param string $user_id
     * @param string $uuid (短縮化したものではない)
     * @return int
     */
    public static function addToken(string $user_id, string $uuid): int
    {
        $sql = <<< EOS
INSERT INTO tokens (
    token
   ,owner
   ,expired_at
   ,created_at
   ,updated_at
) VALUES (
    ?
   ,?
   ,UNIX_TIMESTAMP() + 60 * 60
   ,NOW()
   ,NOW()
)
EOS;
        return BaseModel::query($sql, [
            $uuid,
            $user_id
        ]);
    }

    /**
     * トークンの有効期限を延長する（現時点 + 60 * 60秒）
     *
     * @param string $uuid (短縮化したものではない)
     * @return int
     */
    public static function extendToken(string $uuid): int
    {
        $sql = "UPDATE tokens SET expired_at = UNIX_TIMESTAMP() + 60 * 60 WHERE token = ?";
        return BaseModel::query($sql, [
            $uuid
        ]);
    }

    /**
     * 期限切れのトークンをクリアする
     *
     * @return void
     */
    public static function clearExpiredToken(): void
    {
        $sql = "DELETE FROM tokens WHERE expired_at <= UNIX_TIMESTAMP()";
        BaseModel::query($sql, []);
    }
}
