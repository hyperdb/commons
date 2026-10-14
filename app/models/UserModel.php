<?php

require_once __DIR__ . '/BaseModel.php';


/**
 * モデル: ユーザー
 */
class UserModel
{
    /**
     * レスポンスに含める列（password_hashは絶対に含めない）
     */
    private const PUBLIC_COLUMNS = "id, user_level, created_at, updated_at";

    /**
     * 指定したIDのユーザーを取得
     *
     * @param string $id
     * @return array
     */
    public static function detail(string $id): array
    {
        $sql = "SELECT " . self::PUBLIC_COLUMNS . " FROM users WHERE id = ?";
        $row = BaseModel::fetchRow($sql, [
            $id
        ]);
        return $row?->getData() ?? [];
    }

    /**
     * 指定したIDのパスワードハッシュを取得（ログイン認証専用）
     *
     * @param string $id
     * @return string (該当なしの場合は空文字)
     */
    public static function findPasswordHash(string $id): string
    {
        $sql = "SELECT password_hash FROM users WHERE id = ?";
        $row = BaseModel::fetchRow($sql, [
            $id
        ]);
        return $row['password_hash'] ?? '';
    }

    /**
     * ユーザー一覧を取得
     * 
     * @param int $index (一行目の行番号: ページ番号✕ページあたり行数)
     * @param int $perPage
     * @return array
     */
    public static function list(int $index, int $perPage): array
    {
        $sql = "SELECT " . self::PUBLIC_COLUMNS . " FROM users LIMIT ?, ?";
        return BaseModel::fetchAll($sql, [
            $index,
            $perPage
        ]);
    }

    /**
     * ユーザー更新（パスワード変更）
     *
     * @param string $id
     * @param string $password_hash
     * @return integer
     */
    public static function update(string $id, string $password_hash): int
    {
        $sql = "UPDATE users SET password_hash = ? WHERE id = ?";
        return BaseModel::query($sql, [
            $password_hash,
            $id
        ]);
    }

    /**
     * 権限レベルの変更
     *
     * @param string $id
     * @param string $user_level
     * @return integer
     */
    public static function updateLevel(string $id, string $user_level): int
    {
        $sql = "UPDATE users SET user_level = ? WHERE id = ?";
        return BaseModel::query($sql, [
            $user_level,
            $id
        ]);
    }

    /**
     * ユーザー削除
     *
     * @param string $id
     * @return integer
     */
    public static function delete(string $id): int
    {
        $sql = "DELETE FROM users WHERE id = ?";
        return BaseModel::query($sql, [
            $id
        ]);
    }

    /**
     * ユーザー追加
     *
     * @param string $id
     * @param string $password_hash
     * @param string $user_level
     * @return integer
     */
    public static function add(string $id, string $password_hash, string $user_level): int
    {
        $sql = "INSERT INTO users ( id, password_hash, user_level) VALUES ( ?, ?, ? )";
        return BaseModel::query($sql, [
            $id,
            $password_hash,
            $user_level
        ]);
    }
}
