<?php

/**
 * 都道府県・市区町村の取り込み（CLI専用）
 *
 * 使い方:
 *   php scripts/import-areas.php <xlsxファイル> [--dry-run]
 *
 *   <xlsxファイル>  総務省「全国地方公共団体コード」の「都道府県コード及び市区町村コード」
 *                   https://www.soumu.go.jp/denshijiti/code.html
 *   --dry-run       DBには書き込まず、読み込んだ件数と内容の一部を表示する
 *
 * DBの接続先はプロジェクト直下の.envから取得する。
 */

declare(strict_types=1);

use Scripts\AreaImporter;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/AreaImporter.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$files = array_values(array_filter($args, fn (string $arg) => !str_starts_with($arg, '--')));
if (count($files) !== 1) {
    fwrite(STDERR, "使い方: php scripts/import-areas.php <xlsxファイル> [--dry-run]\n");
    exit(1);
}

try {
    $data = AreaImporter::build(AreaImporter::readXlsx($files[0]));
    AreaImporter::validate($data);
    printf("読み込み: 都道府県 %d件 / 市区町村 %d件\n", count($data['prefectures']), count($data['municipalities']));

    if ($dryRun) {
        // 先頭（北海道・札幌市の区を含む）と末尾を数件ずつ表示
        foreach (['prefectures', 'municipalities'] as $table) {
            echo "\n[{$table}]\n";
            $rows = $data[$table];
            $samples = array_slice($rows, 0, 5, true) + array_slice($rows, -2, 2, true);
            foreach ($samples as $cd => $row) {
                printf("  %s %s %s\n", $cd, $row['name'], $row['kana']);
            }
        }
        echo "\n--dry-runのためDBには書き込んでいません。\n";
        exit(0);
    }

    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'], $_ENV['DB_NAME']),
        $_ENV['DB_USER'],
        $_ENV['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );

    foreach (AreaImporter::import($pdo, $data) as $table => $result) {
        printf(
            "%s: 追加 %d件 / 更新 %d件 / 変更なし %d件\n",
            $table,
            $result['inserted'],
            $result['updated'],
            $result['unchanged']
        );
        if ($result['missing'] !== []) {
            // 廃止された団体などの扱いは未決定のため、削除はせず表示のみ
            printf("  元データにないコード（削除していません）: %s\n", implode(', ', $result['missing']));
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'エラー: ' . $e->getMessage() . "\n");
    exit(1);
}
