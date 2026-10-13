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
   AND expired_time >= UNIX_TIMESTAMP()
EOS;
        $row = BaseModel::fetchRow($sql, [
            $uuid
        ]);
        return $row ? $row['cnt'] : 0;
    }

    /**
     * ユーザートークンを追加する
     * 
     * @param string $username
     * @return int
     */
    public static function addToken(string $username, string $uuid): int
    {
        $sql = <<< EOS
INSERT INTO tokens (
    token
   ,owner
   ,expired_time
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
            $username
        ]);
    }

    /**
     * 期限切れのトークンをクリアする
     *
     * @return void
     */
    public static function clearExpiredToken(): void
    {
        $sql = "DELETE FROM tokens WHERE expired_time <= UNIX_TIMESTAMP()";
        BaseModel::query($sql, []);
    }
}
