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
        // 対象がなければfalse
        if (empty($shortenToken))
        {
            return false;
        }
        // 復元してUUID V4に戻す
        $uuid = Base::expandID($shortenToken);
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
        if ($this->isNullString(Flight::request()->query[$key]))
        {
            return $default;
        }
        return strval(Flight::request()->query[$key]);
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
        if ($this->isNullString(Flight::request()->query[$key]))
        {
            return $default;
        }
        return intval(Flight::request()->query[$key]);
    }

    /**
     * Null or Empty の判定
     *
     * @param string $value
     * @return boolean
     */
    public function isNullString(string $value)
    {
        return $value === null || $value === "";
    }
}
