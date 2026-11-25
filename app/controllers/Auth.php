<?php

require_once __DIR__ . "/Base.php";
require_once __DIR__ . "/../models/AuthFlowModel.php";
require_once __DIR__ . "/../models/ExternalTokenModel.php";
require_once __DIR__ . "/../models/ExternalUserModel.php";
require_once __DIR__ . "/../utils/OAuthProvider.php";

/**
 * コントローラー: 外部ログイン（GitHub／Google）
 *
 * 流れは`/documents/api/auth.md`を参照
 */
class Auth extends Base
{
    /** state・一時コード・code_challengeの形式（Base64URL・43文字） */
    private const RANDOM_PATTERN = '/^[A-Za-z0-9_-]{43}\z/';
    /** code_verifierの形式（RFC 7636） */
    private const VERIFIER_PATTERN = '/^[A-Za-z0-9._~-]{43,128}\z/';

    /**
     * 共通の準備
     */
    public function __construct()
    {
        parent::__construct();
        // クエリ（code・stateなど）をレスポンスに含めない
        $this->route = explode('?', $this->route, 2)[0];
        // コード・トークンを含む応答をキャッシュさせない
        Flight::response()->header('Cache-Control', 'no-store');
    }

    /**
     * ルーティング登録
     *
     * @return void
     */
    public static function registerRoutes(): void
    {
        Flight::group('/auth', function ()
        {
            Flight::route('GET /@provider/login',    [self::class, 'login']);
            Flight::route('GET /@provider/callback', [self::class, 'callback']);
            Flight::route('POST /exchange',          [self::class, 'exchange']);
        });
    }

    /**
     * ログイン開始（プロバイダーの認可画面へリダイレクト）
     *
     * @param string $provider
     * @return void
     */
    public function login(string $provider): void
    {
        if (!OAuthProvider::isSupported($provider))
        {
            $this->resultJson([], 404, $this->route, "見つかりませんでした");
            return;
        }

        $redirect_uri = $this->getStringParam("redirect_uri");
        $code_challenge = $this->getStringParam("code_challenge");
        $errors = [];
        if (!in_array($redirect_uri, $this->getAllowedRedirects(), true))
        {
            $errors["redirect_uri"] = ["リダイレクト先は許可されていません"];
        }
        if (!preg_match(self::RANDOM_PATTERN, $code_challenge))
        {
            $errors["code_challenge"] = ["code_challengeは形式が正しくありません"];
        }
        if (count($errors) > 0)
        {
            $this->resultJson(["errors" => $errors], 400, $this->route, "パラメーターが正しくありません");
            return;
        }

        try
        {
            $oauth = new OAuthProvider($provider);
        }
        catch (RuntimeException $e)
        {
            Flight::logger()->error("auth: " . $e->getMessage());
            $this->resultJson([], 500, $this->route, "サーバー内部でエラーが発生しました");
            return;
        }

        $state = OAuthProvider::randomString();
        $code_verifier = OAuthProvider::randomString();
        AuthFlowModel::clearExpired();
        AuthFlowModel::addState($state, $provider, $code_verifier, $code_challenge, $redirect_uri);

        Flight::redirect($oauth->authorizeUrl($state, $code_verifier), 302);
    }

    /**
     * コールバック（プロバイダーからのリダイレクトで呼ばれる）
     *
     * @param string $provider
     * @return void
     */
    public function callback(string $provider): void
    {
        if (!OAuthProvider::isSupported($provider))
        {
            $this->resultJson([], 404, $this->route, "見つかりませんでした");
            return;
        }

        $state = $this->getStringParam("state");
        $flow = preg_match(self::RANDOM_PATTERN, $state) ? AuthFlowModel::takeState($state, $provider) : [];
        if (count($flow) === 0)
        {
            // リダイレクト先を信用できないため、ブログへは戻さない
            $this->resultJson([], 400, $this->route, "ログインの有効期限が切れたか、リクエストが正しくありません");
            return;
        }

        $error = $this->getStringParam("error");
        $code = $this->getStringParam("code");
        if ($error !== "" || $code === "")
        {
            $reason = $error === "access_denied" ? "access_denied" : "provider_error";
            $this->redirectToBlog($flow["redirect_uri"], ["error" => $reason]);
            return;
        }

        try
        {
            $profile = (new OAuthProvider($provider))->fetchUser($code, $flow["code_verifier"]);
        }
        // PDOExceptionはRuntimeExceptionの子クラスだが、ここではDBを使わない
        catch (RuntimeException $e)
        {
            Flight::logger()->error("auth: " . $e->getMessage());
            $this->redirectToBlog($flow["redirect_uri"], ["error" => "provider_error"]);
            return;
        }

        $user_id = $this->saveUser($provider, $profile);
        if ($user_id === "")
        {
            $this->redirectToBlog($flow["redirect_uri"], ["error" => "suspended"]);
            return;
        }

        $this->redirectToBlog($flow["redirect_uri"], [
            "code" => $this->issueCode($user_id, $flow["code_challenge"])
        ]);
    }

    /**
     * 一時コードを外部トークンと交換する
     *
     * @return void
     */
    public function exchange(): void
    {
        $code = $this->getBodyString("code");
        $code_verifier = $this->getBodyString("code_verifier");
        if ($code === "" || $code_verifier === "")
        {
            $this->resultJson([], 400, $this->route, "パラメーターが正しくありません");
            return;
        }

        $auth_code = preg_match(self::RANDOM_PATTERN, $code) ? AuthFlowModel::takeCode($code) : [];
        $owner = [];
        if (
            count($auth_code) > 0
            && preg_match(self::VERIFIER_PATTERN, $code_verifier)
            && hash_equals($auth_code["code_challenge"], OAuthProvider::challenge($code_verifier))
        )
        {
            // 停止中のユーザー・期限切れのトークンはここで除外される
            $owner = ExternalTokenModel::findOwner($auth_code["token"]);
        }
        if (count($owner) === 0)
        {
            $this->resultJson([], 401, $this->route, "認証できませんでした");
            return;
        }

        $this->resultJson(
            [
                "token" => $this->shortenID($auth_code["token"]),
                "user_id" => $owner["owner"],
                "provider" => $owner["provider"],
                "display_name" => $owner["display_name"],
                "avatar_url" => $owner["avatar_url"],
            ],
            200,
            $this->route,
            "認証OKです"
        );
    }

    /**
     * 外部ユーザーを登録・上書きする
     *
     * @param string $provider
     * @param array{id: string, display_name: string, avatar_url: string} $profile
     * @return string 外部ユーザーのID（停止中の場合は空文字）
     */
    private function saveUser(string $provider, array $profile): string
    {
        $user = ExternalUserModel::findByProvider($provider, $profile["id"]);
        if (isset($user["stopped_at"]))
        {
            return "";
        }

        ExternalUserModel::save(
            $user["id"] ?? $this->newUserId($provider, $profile["id"]),
            $provider,
            $profile["id"],
            $profile["display_name"],
            $profile["avatar_url"]
        );
        // 同時に初回ログインした場合は先に登録された方のIDになるため、登録後に取得し直す
        return ExternalUserModel::findByProvider($provider, $profile["id"])["id"];
    }

    /**
     * 外部ユーザーのIDを発行する
     *
     * @param string $provider
     * @param string $provider_user_id
     * @return string
     */
    private function newUserId(string $provider, string $provider_user_id): string
    {
        if ($provider === "github")
        {
            return "gh-" . $provider_user_id;
        }
        $chars = "0123456789abcdefghijklmnopqrstuvwxyz";
        $random = "";
        for ($i = 0; $i < 16; $i++)
        {
            $random .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return "gg-" . $random;
    }

    /**
     * 外部トークンと一時コードを発行する
     *
     * @param string $user_id
     * @param string $code_challenge
     * @return string 一時コード
     */
    private function issueCode(string $user_id, string $code_challenge): string
    {
        AuthFlowModel::clearExpired();
        ExternalTokenModel::clearExpired();

        $uuid = $this->generateUUID();
        $code = OAuthProvider::randomString();
        ExternalTokenModel::add($user_id, $uuid);
        AuthFlowModel::addCode($code, $uuid, $code_challenge);
        return $code;
    }

    /**
     * ブログへリダイレクトする
     *
     * @param string $redirect_uri
     * @param array<string, string> $params
     * @return void
     */
    private function redirectToBlog(string $redirect_uri, array $params): void
    {
        $separator = str_contains($redirect_uri, "?") ? "&" : "?";
        Flight::redirect($redirect_uri . $separator . http_build_query($params), 302);
    }

    /**
     * リダイレクト先の許可リストを.envの`AUTH_REDIRECT_ALLOWLIST`（","区切り）から取得する
     *
     * @return array<int, string> (未設定の場合は空配列)
     */
    private function getAllowedRedirects(): array
    {
        $urls = array_map('trim', explode(",", strval($_ENV['AUTH_REDIRECT_ALLOWLIST'] ?? "")));
        return array_values(array_filter($urls, fn(string $url): bool => $url !== ""));
    }
}
