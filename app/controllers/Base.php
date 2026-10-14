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
    /** ログインユーザーのID（authenticate()成功時に設定） */
    protected string $login_user_id = "";
    /** ログインユーザーの権限レベル（authenticate()成功時に設定） */
    protected string $login_user_level = "000";

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
        return count($this->findTokenOwner($shortenToken)) > 0;
    }

    /**
     * 短縮トークンから有効なトークンの所有ユーザーを取得する
     *
     * @param string $shortenToken
     * @return array{owner?: string, user_level?: string} (無効なトークンの場合は空配列)
     */
    protected function findTokenOwner(string $shortenToken): array
    {
        // 短縮トークンの形式（Base64URL・22文字）でなければ無効（復元処理での例外を防ぐ）
        if (!preg_match('/^[A-Za-z0-9_-]{22}$/', $shortenToken))
        {
            return [];
        }
        // 復元してUUID V4に戻し、期限内のトークンを検索
        return TokenModel::findOwner($this->expandID($shortenToken));
    }

    /**
     * Bearerトークンで認証する（無効な場合は401を返す）
     * 有効な場合は有効期限を延長し、ログインユーザーの情報を保持する
     *
     * @return boolean 認証できなければfalse（呼び出し元は処理を中断すること）
     */
    public function authenticate(): bool
    {
        $owner = $this->findTokenOwner($this->bearer_token);
        if (count($owner) === 0)
        {
            $this->resultJson([], 401, $this->route, "認証できませんでした");
            return false;
        }
        // トークンが有効なら有効期限を延長する（権限レベル不足の場合も延長する）
        TokenModel::extendToken($this->expandID($this->bearer_token));

        $this->login_user_id = strval($owner["owner"]);
        $this->login_user_level = strval($owner["user_level"]);
        return true;
    }

    /**
     * ログインユーザーの権限レベルを確認する（不足している場合は403を返す）
     * authenticate()の成功後に呼び出すこと
     *
     * @param string $required 必要な権限レベル
     * @return boolean 権限レベルが不足していればfalse（呼び出し元は処理を中断すること）
     */
    public function checkLevel(string $required): bool
    {
        // 000 < 001 < 010 < 111 の単純な大小比較（同じ桁数の数字文字列なので文字列比較で判定できる）
        if (strcmp($this->login_user_level, $required) < 0)
        {
            $this->resultJson([], 403, $this->route, "権限がありません");
            return false;
        }
        return true;
    }

    /**
     * 認証と権限レベルの確認をまとめて行う
     *
     * @param string $required 必要な権限レベル
     * @return boolean 認証できない・権限レベル不足ならfalse（呼び出し元は処理を中断すること）
     */
    public function authorize(string $required): bool
    {
        return $this->authenticate() && $this->checkLevel($required);
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
