<?php

/**
 * 外部ユーザーの停止（CLI専用）
 *
 * 使い方:
 *   php scripts/stop-external-user.php <外部ユーザーID>
 *
 *   <外部ユーザーID>  gh-12345 や gg-abcdefghijklmnop の形式
 *
 * 停止したユーザーはログインできなくなり、発行済みのトークンも削除される。
 * コメントは`writers`側で削除すること。
 *
 * DBの接続先はプロジェクト直下の.envから取得する。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/models/ExternalUserModel.php';
require __DIR__ . '/../app/models/ExternalTokenModel.php';

if ($argc !== 2 || !preg_match('/^(gh|gg)-[a-z0-9]+\z/', $argv[1])) {
    fwrite(STDERR, "使い方: php scripts/stop-external-user.php <外部ユーザーID>\n");
    exit(1);
}
$id = $argv[1];

try {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
    Flight::register('db', \flight\database\SimplePdo::class, [
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'], $_ENV['DB_NAME']),
        $_ENV['DB_USER'],
        $_ENV['DB_PASS'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    ]);

    $user = ExternalUserModel::detail($id);
    if ($user === []) {
        fwrite(STDERR, "外部ユーザーが見つかりません: {$id}\n");
        exit(1);
    }
    if (ExternalUserModel::stop($id) === 0) {
        printf("すでに停止しています: %s（停止日時: %s）\n", $id, $user['stopped_at']);
    } else {
        printf("停止しました: %s（%s）\n", $id, $user['display_name']);
    }
    // 停止済みの場合も、残っているトークンがあれば削除する
    printf("削除したトークン: %d件\n", ExternalTokenModel::deleteByOwner($id));
} catch (Throwable $e) {
    fwrite(STDERR, 'エラー: ' . $e->getMessage() . "\n");
    exit(1);
}
