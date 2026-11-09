<?php

namespace App\Phpunit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Scripts\AreaImporter;

require_once __DIR__ . '/../scripts/AreaImporter.php';

/**
 * テストクラス: 都道府県・市区町村の取り込み（scripts/AreaImporter.php）
 *
 * DBへの反映（import）は実DBが必要なため対象外
 */
class AreaImporterTest extends TestCase
{
    /** 見出し行（実データと同じく1行目は見出し） */
    private const HEADER = ['A' => '団体コード', 'B' => '都道府県名', 'C' => '市区町村名', 'D' => 'カナ', 'E' => 'カナ'];

    /**
     * 実データと同じ形のシートを作る
     *
     * @param list<array<string, string>> $mainRows 「現在の団体」シートの行
     * @param list<array<string, string>> $cityRows 「政令指定都市」シートの行
     * @return array<string, list<array<string, string>>>
     */
    private function sheets(array $mainRows, array $cityRows): array
    {
        return [
            'R6.1.1現在の団体' => array_merge([self::HEADER], $mainRows),
            'R6.1.1政令指定都市' => array_merge([self::HEADER], $cityRows),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function row(string $code, string $pref, string $name, string $prefKana, string $kana): array
    {
        return ['A' => $code, 'B' => $pref, 'C' => $name, 'D' => $prefKana, 'E' => $kana];
    }

    /**
     * 札幌市（政令指定都市）・函館市・北海道を含む最小限のデータ
     *
     * @return array<string, list<array<string, string>>>
     */
    private function hokkaidoSheets(): array
    {
        return $this->sheets(
            [
                $this->row('010006', '北海道', '', 'ﾎｯｶｲﾄﾞｳ', ''),
                $this->row('011002', '北海道', '札幌市', 'ﾎｯｶｲﾄﾞｳ', 'ｻｯﾎﾟﾛｼ'),
                $this->row('012025', '北海道', '函館市', 'ﾎｯｶｲﾄﾞｳ', 'ﾊｺﾀﾞﾃｼ'),
            ],
            [
                $this->row('011002', '北海道', '札幌市', 'ﾎｯｶｲﾄﾞｳ', 'ｻｯﾎﾟﾛｼ'),
                $this->row('011011', '北海道', '札幌市中央区', 'ﾎｯｶｲﾄﾞｳ', 'ｻｯﾎﾟﾛｼﾁｭｳｵｳｸ'),
            ]
        );
    }

    #[TestDox('半角カタカナを全角ひらがなに変換する（濁点・半濁点・小さい文字を含む）')]
    public function testToHiragana(): void
    {
        $this->assertSame('さっぽろしちゅうおうく', AreaImporter::toHiragana('ｻｯﾎﾟﾛｼﾁｭｳｵｳｸ'));
        $this->assertSame('ほっかいどう', AreaImporter::toHiragana('ﾎｯｶｲﾄﾞｳ'));
        $this->assertSame('はこだてし', AreaImporter::toHiragana('ハコダテシ'));
    }

    #[TestDox('市区町村名が空の行は都道府県として取り込む（コードは先頭2桁）')]
    public function testBuildPrefectures(): void
    {
        $data = AreaImporter::build($this->hokkaidoSheets());

        $this->assertSame(['01' => ['name' => '北海道', 'kana' => 'ほっかいどう']], $data['prefectures']);
    }

    #[TestDox('市区町村コードは検査数字を除いた5桁になり、政令指定都市は区のみ取り込む')]
    public function testBuildMunicipalities(): void
    {
        $data = AreaImporter::build($this->hokkaidoSheets());

        $this->assertSame(
            [
                '01101' => ['pref_cd' => '01', 'name' => '札幌市中央区', 'kana' => 'さっぽろしちゅうおうく'],
                '01202' => ['pref_cd' => '01', 'name' => '函館市', 'kana' => 'はこだてし'],
            ],
            array_combine(array_map('strval', array_keys($data['municipalities'])), $data['municipalities'])
        );
        $this->assertArrayNotHasKey('01100', $data['municipalities'], '札幌市そのものは除外');
    }

    #[TestDox('コードが数値として保存され先頭の0が落ちていても補って読む')]
    public function testBuildPadsNumericCode(): void
    {
        $sheets = $this->hokkaidoSheets();
        $sheets['R6.1.1現在の団体'][3]['A'] = '12025';

        $data = AreaImporter::build($sheets);

        $this->assertArrayHasKey('01202', $data['municipalities']);
    }

    #[TestDox('区の名前が市の名前で始まらない場合はエラー')]
    public function testBuildRejectsUnknownWard(): void
    {
        $sheets = $this->hokkaidoSheets();
        $sheets['R6.1.1政令指定都市'][2]['C'] = '中央区';

        $this->expectException(\RuntimeException::class);
        AreaImporter::build($sheets);
    }

    #[TestDox('必要なシートがない場合はエラー')]
    public function testBuildRequiresSheets(): void
    {
        $sheets = $this->hokkaidoSheets();
        unset($sheets['R6.1.1政令指定都市']);

        $this->expectException(\RuntimeException::class);
        AreaImporter::build($sheets);
    }

    /**
     * 47都道府県＋1件の市区町村を持つ、検証を通るデータ
     *
     * @return array{prefectures: array, municipalities: array}
     */
    private function validData(): array
    {
        $prefectures = [];
        for ($i = 1; $i <= 47; $i++) {
            $prefectures[sprintf('%02d', $i)] = ['name' => '北海道', 'kana' => 'ほっかいどう'];
        }
        return [
            'prefectures' => $prefectures,
            'municipalities' => ['01202' => ['pref_cd' => '01', 'name' => '函館市', 'kana' => 'はこだてし']],
        ];
    }

    #[TestDox('問題のないデータは検証を通る')]
    public function testValidatePasses(): void
    {
        AreaImporter::validate($this->validData());

        $this->addToAssertionCount(1);
    }

    #[TestDox('都道府県が47件でない場合は検証エラー')]
    public function testValidateRejectsPrefectureCount(): void
    {
        $data = $this->validData();
        unset($data['prefectures']['47']);

        $this->expectException(\RuntimeException::class);
        AreaImporter::validate($data);
    }

    #[TestDox('名前が列の長さ（市区町村は16文字）を超える場合は検証エラー')]
    public function testValidateRejectsTooLongName(): void
    {
        $data = $this->validData();
        $data['municipalities']['01202']['name'] = str_repeat('長', 17);

        $this->expectException(\RuntimeException::class);
        AreaImporter::validate($data);
    }

    #[TestDox('かなにひらがな以外の文字がある場合は検証エラー')]
    public function testValidateRejectsNonHiragana(): void
    {
        $data = $this->validData();
        $data['municipalities']['01202']['kana'] = 'ハコダテシ';

        $this->expectException(\RuntimeException::class);
        AreaImporter::validate($data);
    }

    #[TestDox('xlsxを読み込み、ふりがな（rPh）は文字列に含めない')]
    public function testReadXlsx(): void
    {
        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $files = [
            'xl/workbook.xml' => '<workbook xmlns="' . $main . '"'
                . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="テスト" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships'
                . ' xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/sharedStrings.xml' => '<sst xmlns="' . $main . '">'
                . '<si><t>熊本市西区</t><rPh sb="0" eb="5"><t>クマモトシニシク</t></rPh></si>'
                . '<si><r><t>札幌</t></r><r><t>市</t></r></si></sst>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="' . $main . '"><sheetData>'
                . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="s"><v>1</v></c></row>'
                . '<row r="2"><c r="A2"><v>11002</v></c><c r="B2" t="inlineStr"><is><t>北海道</t></is></c></row>'
                . '</sheetData></worksheet>',
        ];
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $xml) {
            $zip->addFromString($name, '<?xml version="1.0" encoding="UTF-8"?>' . $xml);
        }
        $zip->close();

        try {
            $sheets = AreaImporter::readXlsx($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(
            ['テスト' => [['A' => '熊本市西区', 'C' => '札幌市'], ['A' => '11002', 'B' => '北海道']]],
            $sheets
        );
    }
}
