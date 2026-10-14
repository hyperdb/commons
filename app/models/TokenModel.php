<?php

require_once __DIR__ . '/BaseModel.php';


/**
 * モデル: トークン
 */
class TokenModel
{
    /**
     * 有効期限内のトークンの所有ユーザーを取得
     *
     * @param string $uuid (短縮化したものではない)
     * @return array{owner?: string, user_level?: string} (無効なトークンの場合は空配列)
     */
    public static function findOwner(string $uuid): array
    {
        $sql = <<< EOS
SELECT t.owner
      ,u.user_level
  FROM tokens t
 INNER JOIN users u
    ON u.id = t.owner
 WHERE t.token = ?
   AND t.expired_at >= UNIX_TIMESTAMP()
EOS;
        $row = BaseModel::fetchRow($sql, [
            $uuid
        ]);
        return $row?->getData() ?? [];
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
