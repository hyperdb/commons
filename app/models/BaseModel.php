<?php

use flight\util\Collection;


/**
 * 基底クラス
 * 
 * @version 1.0.0
 */
class BaseModel
{
    /**
     * 更新クエリーの実行
     *
     * @param string $sql
     * @param mixed $params
     * @return int
     */
    public static function query(string $sql, mixed $params = []): int
    {
        $affected_rows = Flight::db()->runQuery($sql, $params);

        return $affected_rows->rowCount() ?? 0;
    }

    /**
     * 選択クエリーの実行（1行）
     *
     * @param string $sql
     * @param mixed $params
     * @return ?Collection
     */
    public static function fetchRow(string $sql, mixed $params = []): ?Collection
    {
        return Flight::db()->fetchRow($sql, $params);
    }

    /**
     * 選択クエリーの実行（全行）
     *
     * @param string $sql
     * @param mixed $params
     * @return array<int,Collection|array<string,mixed>>
     */
    public static function fetchAll(string $sql, mixed $params = []): array
    {
        return Flight::db()->fetchAll($sql, $params);
    }

    /**
     * 選択クエリーの実行（1列）
     *
     * @param string $sql
     * @param mixed $params
     * @return array<int,mixed>
     */
    public static function fetchColumn(string $sql, mixed $params = []): array
    {
        return Flight::db()->fetchColumn($sql, $params);
    }

    /**
     * 選択クエリーの実行（Key-Value列）
     *
     * @param string $sql
     * @param mixed $params
     * @return array<string|int,mixed>
     */
    public static function fetchKeyValue(string $sql, mixed $params = []): array
    {
        return Flight::db()->fetchPairs($sql, $params);
    }

    /**
     * 追加時のIDを返却
     * 元の`lastInsertId`がreturn stringなのでそのまま継承
     *
     * @return string
     */
    public static function getInsertId(): string
    {
        return Flight::db()->lastInsertId();
    }
}
