<?php

require_once __DIR__ . '/BaseModel.php';


/**
 * モデル: 外部ユーザー
 */
class ExternalUserModel
{
    /**
     * プロバイダー側のIDから外部ユーザーを取得
     *
     * @param string $provider
     * @param string $provider_user_id
     * @return array{id?: string, stopped_at?: ?string} (該当なしの場合は空配列)
     */
    public static function findByProvider(string $provider, string $provider_user_id): array
    {
        $sql = "SELECT id, stopped_at FROM external_users WHERE provider = ? AND provider_user_id = ?";
        $row = BaseModel::fetchRow($sql, [
            $provider,
            $provider_user_id
        ]);
        return $row?->getData() ?? [];
    }

    /**
     * 指定したIDの外部ユーザーを取得
     *
     * @param string $id
     * @return array{id?: string, display_name?: string, stopped_at?: ?string} (該当なしの場合は空配列)
     */
    public static function detail(string $id): array
    {
        $sql = "SELECT id, display_name, stopped_at FROM external_users WHERE id = ?";
        $row = BaseModel::fetchRow($sql, [
            $id
        ]);
        return $row?->getData() ?? [];
    }

    /**
     * 外部ユーザーを登録する（登録済みなら表示名・アバターURLだけを上書きする）
     *
     * @param string $id (登録済みの場合は使われない)
     * @param string $provider
     * @param string $provider_user_id
     * @param string $display_name
     * @param string $avatar_url
     * @return int
     */
    public static function save(
        string $id,
        string $provider,
        string $provider_user_id,
        string $display_name,
        string $avatar_url
    ): int {
        $sql = <<< EOS
INSERT INTO external_users (
    id
   ,provider
   ,provider_user_id
   ,display_name
   ,avatar_url
) VALUES (
    ?
   ,?
   ,?
   ,?
   ,?
)
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name)
   ,avatar_url = VALUES(avatar_url)
EOS;
        return BaseModel::query($sql, [
            $id,
            $provider,
            $provider_user_id,
            $display_name,
            $avatar_url
        ]);
    }

    /**
     * 外部ユーザーを停止する
     *
     * @param string $id
     * @return int (存在しない・停止済みの場合は0)
     */
    public static function stop(string $id): int
    {
        $sql = "UPDATE external_users SET stopped_at = NOW() WHERE id = ? AND stopped_at IS NULL";
        return BaseModel::query($sql, [
            $id
        ]);
    }
}
