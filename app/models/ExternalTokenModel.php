<?php

require_once __DIR__ . '/BaseModel.php';


/**
 * モデル: 外部トークン（外部ユーザー用のトークン。有効期限は延長しない）
 */
class ExternalTokenModel
{
    /**
     * 有効期限内のトークンの所有ユーザーを取得（停止中のユーザーは除く）
     *
     * @param string $uuid (短縮化したものではない)
     * @return array{owner?: string, provider?: string, display_name?: string, avatar_url?: string}
     *         (無効なトークンの場合は空配列)
     */
    public static function findOwner(string $uuid): array
    {
        $sql = <<< EOS
SELECT t.owner
      ,u.provider
      ,u.display_name
      ,u.avatar_url
  FROM external_tokens t
 INNER JOIN external_users u
    ON u.id = t.owner
 WHERE t.token = ?
   AND t.expired_at > UNIX_TIMESTAMP()
   AND u.stopped_at IS NULL
EOS;
        $row = BaseModel::fetchRow($sql, [
            $uuid
        ]);
        return $row?->getData() ?? [];
    }

    /**
     * 外部トークンを追加する（発行から10分で失効）
     *
     * @param string $owner
     * @param string $uuid (短縮化したものではない)
     * @return int
     */
    public static function add(string $owner, string $uuid): int
    {
        $sql = "INSERT INTO external_tokens (token, owner, expired_at) VALUES (?, ?, UNIX_TIMESTAMP() + 10 * 60)";
        return BaseModel::query($sql, [
            $uuid,
            $owner
        ]);
    }

    /**
     * 指定した外部ユーザーのトークンをすべて削除する
     *
     * @param string $owner
     * @return int
     */
    public static function deleteByOwner(string $owner): int
    {
        $sql = "DELETE FROM external_tokens WHERE owner = ?";
        return BaseModel::query($sql, [
            $owner
        ]);
    }

    /**
     * 期限切れの外部トークンをクリアする
     *
     * @return void
     */
    public static function clearExpired(): void
    {
        $sql = "DELETE FROM external_tokens WHERE expired_at <= UNIX_TIMESTAMP()";
        BaseModel::query($sql, []);
    }
}
