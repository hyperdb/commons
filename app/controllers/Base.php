<?php

use App\Shared\Utils\HashTools;

require_once __DIR__ . "/../models/TokenModel.php";


/**
 * 基底クラス
 * 
 * @version 1.0.0
 */
class Base
{
    protected string $bearer_token;
    protected string $route;

    /**
     * Undocumented function
     */
    public function __construct()
    {
        $this->bearer_token = $this->getBearerToken();
        $this->route = Flight::request()->url;
    }

    /**
     * BEARERトークンをヘッダーから取得
     *
     * @return string
     */
    public function getBearerToken(): string
    {
        $authHeader = Flight::request()->getHeader('Authorization');

        $token = '';
        if (str_starts_with($authHeader, 'Bearer '))
        {
            $token = str_replace('Bearer ', '', $authHeader);
        }
        return $token ?? '';
    }

    /**
     * UUID V4を生成する
     *
     * @return string
     */
    public function generateUUID(): string
    {
        $tools = new HashTools();
        return $tools->generateUuidV4();
    }

    /**
     * UUID V4を短縮化する
     *
     * @param string $uuid
     * @return string
     */
    public function shortenID(string $uuid): string
    {
        $tools = new HashTools();
        return $tools->getShortUuidV4($uuid);
    }

    /**
     * 短縮化されたUUID V4を復元する
     *
     * @param string $shortenId
     * @return string
     */
    public function expandID(string $shortenId): string
    {
        $tools = new HashTools();
        return $tools->expandShortUuidV4($shortenId);
    }

    /**
     * パスワードのハッシュを生成する
     *
     * @param string $password
     * @return string
     */
    public function generateHash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * 結果をJSONで返却する
     *
     * @param array $data
     * @param integer $status
     * @param string $route
     * @return void
     */
    public function resultJson(array $data, int $status, string $route, string $message): void
    {
        Flight::json(
            [
                "api_version" => API_VERSION,
                "api_route" => $route,
                "send_at" => date("Y-m-d H:i:s"),
                "message" => $message,
                "data" => $data,
            ],
            $status,
            true,
            'utf-8',
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * トークンの有効性を確認する
     *
     * @param string $shortenToken
     * @return boolean
     */
    public function validToken(string $shortenToken = ""): bool
    {
        // 短縮トークンの形式（Base64URL・22文字）でなければfalse（復元処理での例外を防ぐ）
        if (!preg_match('/^[A-Za-z0-9_-]{22}$/', $shortenToken))
        {
            return false;
        }
        // 復元してUUID V4に戻す
        $uuid = $this->expandID($shortenToken);
        // 期限内のトークンを検索
        return TokenModel::checkToken($uuid) > 0 ? true : false;
    }

    /**
     * string型のクエリパラメータを取得
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    public function getStringParam(string $key, string $default = ""): string
    {
        $value = Flight::request()->query[$key] ?? null;
        // 未指定・配列などの文字列化できない値は既定値にする
        if (!is_scalar($value) || $this->isNullString(strval($value)))
        {
            return $default;
        }
        return strval($value);
    }

    /**
     * int型のクエリパラメータを取得
     *
     * @param string $key
     * @param integer $default
     * @return int
     */
    public function getIntParam(string $key, int $default = 0): int
    {
        $value = Flight::request()->query[$key] ?? null;
        // 未指定・配列などの数値化できない値は既定値にする
        if (!is_scalar($value) || $this->isNullString(strval($value)))
        {
            return $default;
        }
        return intval($value);
    }

    /**
     * 一覧取得のページングパラメータを取得（不正な値は補正する）
     *
     * @return array{page: int, rows: int}
     */
    public function getPagingParams(): array
    {
        $page = $this->getIntParam("page", 0);
        $rows = $this->getIntParam("rows", 10);

        return [
            // 負のページ番号は0として扱う
            "page" => max($page, 0),
            // 0以下の件数は既定値として扱う
            "rows" => $rows > 0 ? $rows : 10,
        ];
    }

    /**
     * string型のボディ（JSON）パラメータを取得（POST / PUT用）
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    public function getBodyString(string $key, string $default = ""): string
    {
        $value = Flight::request()->data[$key] ?? null;
        // 未指定・配列などの文字列化できない値は既定値にする
        if (!is_scalar($value) || strval($value) === "")
        {
            return $default;
        }
        return strval($value);
    }

    /**
     * Null or Empty の判定
     *
     * @param ?string $value
     * @return boolean
     */
    public function isNullString(?string $value): bool
    {
        return $value === null || $value === "";
    }
}
