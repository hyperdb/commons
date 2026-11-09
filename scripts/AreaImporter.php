<?php

declare(strict_types=1);

namespace Scripts;

/**
 * 都道府県・市区町村の取り込み処理
 *
 * 総務省「全国地方公共団体コード」のExcel（xlsx）を読み込み、
 * prefectures / municipalities テーブルに反映する。
 * xlsxはパッケージを使わず、ZipArchiveとSimpleXMLで直接読む。
 */
final class AreaImporter
{
    /** 市区町村・東京23区のシート（シート名に含まれる文字列） */
    public const MAIN_SHEET = '現在の団体';
    /** 政令指定都市とその区のシート（シート名に含まれる文字列） */
    public const CITY_SHEET = '政令指定都市';

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const NS_DOC_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** 列の最大文字数（schema.sqlに合わせる） */
    private const MAX_LENGTH = [
        'prefectures' => ['name' => 8, 'kana' => 16],
        'municipalities' => ['name' => 16, 'kana' => 32],
    ];

    /**
     * xlsxを読み込み、シートごとの行データを返す
     *
     * @return array<string, list<array<string, string>>> [シート名 => [[列記号 => 値], ...]]
     */
    public static function readXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException("xlsxファイルを開けません: {$path}");
        }

        try {
            $strings = self::readSharedStrings($zip);
            $sheets = [];
            foreach (self::readSheetEntries($zip) as $name => $entry) {
                $sheets[$name] = self::readSheet($zip, $entry, $strings);
            }
            return $sheets;
        } finally {
            $zip->close();
        }
    }

    /**
     * シートの行データから登録用のデータを作る
     *
     * - 都道府県: 「現在の団体」シートで市区町村名が空の行
     * - 市区町村: 「現在の団体」シートの残りの行＋「政令指定都市」シートの区
     *   （政令指定都市そのものは除外する）
     *
     * @param array<string, list<array<string, string>>> $sheets readXlsx()の戻り値
     * @return array{
     *     prefectures: array<string, array{name: string, kana: string}>,
     *     municipalities: array<string, array{pref_cd: string, name: string, kana: string}>
     * }
     */
    public static function build(array $sheets): array
    {
        $prefectures = [];
        $municipalities = [];
        foreach (self::dataRows(self::findSheet($sheets, self::MAIN_SHEET)) as [$cd, $row]) {
            if (($row['C'] ?? '') === '') {
                $prefectures[substr($cd, 0, 2)] = [
                    'name' => $row['B'] ?? '',
                    'kana' => self::toHiragana($row['D'] ?? ''),
                ];
                continue;
            }
            $municipalities[$cd] = self::municipality($cd, $row);
        }

        // 「政令指定都市」シートのうち、「現在の団体」シートにもあるコードは市そのもの
        $cities = [];
        $wards = [];
        foreach (self::dataRows(self::findSheet($sheets, self::CITY_SHEET)) as [$cd, $row]) {
            if (isset($municipalities[$cd])) {
                $cities[$cd] = $municipalities[$cd]['name'];
                unset($municipalities[$cd]);
                continue;
            }
            $wards[$cd] = self::municipality($cd, $row);
        }

        // 区の名前は必ず市の名前で始まるはず（シートの形式が変わった場合の検知）
        foreach ($wards as $cd => $ward) {
            $parent = array_filter($cities, fn (string $city) => str_starts_with($ward['name'], $city));
            if ($parent === []) {
                throw new \RuntimeException("政令指定都市の区として扱えない行があります: {$cd} {$ward['name']}");
            }
        }
        if ($cities === [] || $wards === []) {
            throw new \RuntimeException('政令指定都市のシートから市・区を読み取れませんでした');
        }

        $municipalities += $wards;
        ksort($prefectures);
        ksort($municipalities);

        return ['prefectures' => $prefectures, 'municipalities' => $municipalities];
    }

    /**
     * 登録用データの内容をチェックし、問題があれば例外を投げる
     *
     * @param array{prefectures: array, municipalities: array} $data build()の戻り値
     */
    public static function validate(array $data): void
    {
        $errors = [];
        if (count($data['prefectures']) !== 47) {
            $errors[] = '都道府県が47件ではありません（' . count($data['prefectures']) . '件）';
        }
        foreach (self::MAX_LENGTH as $table => $limits) {
            foreach ($data[$table] as $cd => $row) {
                foreach ($limits as $column => $max) {
                    if ($row[$column] === '' || mb_strlen($row[$column]) > $max) {
                        $errors[] = "{$table}.{$column}が空、または{$max}文字を超えています: {$cd} {$row[$column]}";
                    }
                }
                if (!preg_match('/\A[\p{Hiragana}ー]+\z/u', $row['kana'])) {
                    $errors[] = "{$table}.kanaにひらがな以外の文字があります: {$cd} {$row['kana']}";
                }
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException("データに問題があります。\n" . implode("\n", $errors));
        }
    }

    /**
     * DBに反映する（追加・更新のみ。元データにないコードは削除しない）
     *
     * @param array{prefectures: array, municipalities: array} $data build()の戻り値
     * @return array<string, array{inserted: int, updated: int, unchanged: int, missing: list<string>}>
     *     テーブルごとの件数と、DBにあって元データにないコードの一覧
     */
    public static function import(\PDO $pdo, array $data): array
    {
        $sqls = [
            'prefectures' => 'INSERT INTO prefectures (cd, name, kana) VALUES (:cd, :name, :kana)'
                . ' ON DUPLICATE KEY UPDATE name = VALUES(name), kana = VALUES(kana)',
            'municipalities' => 'INSERT INTO municipalities (cd, pref_cd, name, kana)'
                . ' VALUES (:cd, :pref_cd, :name, :kana)'
                . ' ON DUPLICATE KEY UPDATE pref_cd = VALUES(pref_cd), name = VALUES(name), kana = VALUES(kana)',
        ];

        $report = [];
        $pdo->beginTransaction();
        try {
            foreach ($sqls as $table => $sql) {
                $existing = $pdo->query("SELECT cd FROM {$table}")->fetchAll(\PDO::FETCH_COLUMN);
                $stmt = $pdo->prepare($sql);
                $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
                foreach ($data[$table] as $cd => $row) {
                    $stmt->execute(['cd' => (string) $cd] + $row);
                    // ON DUPLICATE KEY UPDATEの影響行数: 1=追加、2=更新、0=変更なし
                    $key = [1 => 'inserted', 2 => 'updated'][$stmt->rowCount()] ?? 'unchanged';
                    $counts[$key]++;
                }
                $missing = array_values(array_diff(
                    array_map('strval', $existing),
                    array_map('strval', array_keys($data[$table]))
                ));
                $report[$table] = $counts + ['missing' => $missing];
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $report;
    }

    /**
     * 半角カタカナ（全角カタカナも可）を全角ひらがなに変換する
     */
    public static function toHiragana(string $kana): string
    {
        // K: 半角カタカナ→全角カタカナ、V: 濁点・半濁点を1文字にまとめる、c: 全角カタカナ→ひらがな
        return mb_convert_kana(mb_convert_kana($kana, 'KV', 'UTF-8'), 'c', 'UTF-8');
    }

    /**
     * @param array<string, string> $row
     * @return array{pref_cd: string, name: string, kana: string}
     */
    private static function municipality(string $cd, array $row): array
    {
        return [
            'pref_cd' => substr($cd, 0, 2),
            'name' => $row['C'] ?? '',
            'kana' => self::toHiragana($row['E'] ?? ''),
        ];
    }

    /**
     * 見出し行などを除いたデータ行を [5桁コード, 行] の形で返す
     *
     * @param list<array<string, string>> $rows
     * @return \Generator<array{string, array<string, string>}>
     */
    private static function dataRows(array $rows): \Generator
    {
        foreach ($rows as $row) {
            $code = $row['A'] ?? '';
            // 数値として保存されている場合に先頭の0が落ちるため補う
            if (ctype_digit($code) && strlen($code) < 6) {
                $code = str_pad($code, 6, '0', STR_PAD_LEFT);
            }
            if (preg_match('/\A\d{6}\z/', $code)) {
                // 6桁目は検査数字なので除く
                yield [substr($code, 0, 5), $row];
            }
        }
    }

    /**
     * シート名に指定の文字列を含むシートを1つだけ探す
     *
     * @param array<string, list<array<string, string>>> $sheets
     * @return list<array<string, string>>
     */
    private static function findSheet(array $sheets, string $needle): array
    {
        $found = array_filter($sheets, fn ($name) => str_contains((string) $name, $needle), ARRAY_FILTER_USE_KEY);
        if (count($found) !== 1) {
            throw new \RuntimeException("「{$needle}」を含むシートが1つに特定できません: " . implode(', ', array_keys($sheets)));
        }
        return array_values($found)[0];
    }

    /**
     * @return list<string> 共有文字列の一覧（セルからは番号で参照される）
     */
    private static function readSharedStrings(\ZipArchive $zip): array
    {
        $doc = self::loadXml($zip, 'xl/sharedStrings.xml', false);
        if ($doc === null) {
            return [];
        }
        return array_map(fn ($si) => self::textOf($si), self::xpath($doc, '/m:sst/m:si'));
    }

    /**
     * @return array<string, string> [シート名 => zip内のパス]
     */
    private static function readSheetEntries(\ZipArchive $zip): array
    {
        $rels = self::loadXml($zip, 'xl/_rels/workbook.xml.rels');
        $rels->registerXPathNamespace('r', self::NS_PKG_REL);
        $targets = [];
        foreach ($rels->xpath('/r:Relationships/r:Relationship') as $rel) {
            $target = (string) $rel['Target'];
            $targets[(string) $rel['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        }

        $entries = [];
        foreach (self::xpath(self::loadXml($zip, 'xl/workbook.xml'), '/m:workbook/m:sheets/m:sheet') as $sheet) {
            $id = (string) $sheet->attributes(self::NS_DOC_REL)['id'];
            if (isset($targets[$id])) {
                $entries[(string) $sheet['name']] = $targets[$id];
            }
        }
        return $entries;
    }

    /**
     * @param list<string> $strings 共有文字列
     * @return list<array<string, string>> [[列記号 => 値], ...]
     */
    private static function readSheet(\ZipArchive $zip, string $entry, array $strings): array
    {
        $rows = [];
        foreach (self::xpath(self::loadXml($zip, $entry), '/m:worksheet/m:sheetData/m:row') as $row) {
            $cells = [];
            foreach (self::xpath($row, 'm:c') as $cell) {
                $type = (string) $cell['t'];
                if ($type === 'inlineStr') {
                    $is = self::xpath($cell, 'm:is');
                    $value = $is === [] ? '' : self::textOf($is[0]);
                } else {
                    $v = self::xpath($cell, 'm:v');
                    $value = $v === [] ? '' : (string) $v[0];
                    if ($type === 's') {
                        $value = $strings[(int) $value] ?? '';
                    }
                }
                $cells[rtrim((string) $cell['r'], '0123456789')] = trim($value);
            }
            $rows[] = $cells;
        }
        return $rows;
    }

    /**
     * 文字列要素（si / is）の文字列を返す（ふりがな＝rPh要素は含めない）
     */
    private static function textOf(\SimpleXMLElement $node): string
    {
        $text = '';
        foreach (self::xpath($node, 'm:t | m:r/m:t') as $t) {
            $text .= (string) $t;
        }
        return $text;
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private static function xpath(\SimpleXMLElement $node, string $path): array
    {
        $node->registerXPathNamespace('m', self::NS_MAIN);
        return $node->xpath($path) ?: [];
    }

    /**
     * zip内のXMLを読み込む
     *
     * @param bool $required trueの場合、存在しなければ例外を投げる
     */
    private static function loadXml(\ZipArchive $zip, string $entry, bool $required = true): ?\SimpleXMLElement
    {
        $xml = $zip->getFromName($entry);
        if ($xml === false) {
            if ($required) {
                throw new \RuntimeException("xlsxの形式が想定と異なります（{$entry}がありません）");
            }
            return null;
        }
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            throw new \RuntimeException("XMLを読み込めません: {$entry}");
        }
        return $doc;
    }
}
