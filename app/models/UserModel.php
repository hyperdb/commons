<?php

require_once __DIR__ . '/BaseModel.php';


/**
 * モデル: ユーザー
 */
class UserModel
{
    /**
     * 指定したIDのユーザーを取得
     * 
     * @param string $username
     * @return array
     */
    public static function detail(string $username): array
    {
        $sql = "SELECT * FROM users WHERE username = ?";
        $row = BaseModel::fetchRow($sql, [
            $username
        ]);
        return $row?->getData() ?? [];
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
        $sql = "SELECT * FROM users LIMIT ?, ?";
        return BaseModel::fetchAll($sql, [
            $index,
            $perPage
        ]);
    }

    /**
     * ユーザー更新（実質はパスワード変更）
     *
     * @param string $username
     * @param string $password_hash
     * @return integer
     */
    public static function update(string $username, string $password_hash): int
    {
        $sql = "UPDATE users SET password_hash = ? WHERE username = ?";
        return BaseModel::query($sql, [
            $password_hash,
            $username
        ]);
    }

    /**
     * ユーザー削除
     *
     * @param string $username
     * @return integer
     */
    public static function delete(string $username): int
    {
        $sql = "DELETE FROM users WHERE username = ?";
        return BaseModel::query($sql, [
            $username
        ]);
    }

    /**
     * ユーザー追加
     *
     * @param string $username
     * @param string $password_hash
     * @return integer
     */
    public static function add(string $username, string $password_hash): int
    {
        $sql = "INSERT INTO users ( username, password_hash) VALUES ( ?, ? )";
        return BaseModel::query($sql, [
            $username,
            $password_hash
        ]);
    }
}
