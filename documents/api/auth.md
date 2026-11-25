# API: 外部ログイン（/auth）

共通ルールは`/documents/api-conventions.md`を参照。項目の意味は`/documents/Entities.md`（外部ユーザー・外部トークン・OAuthの状態・一時コード）を参照。

GitHub／Googleのアカウントでログインしたユーザー（外部ユーザー）に、外部トークンを発行する。

## 設定値（.env）

| 項目 | 内容 |
| --- | --- |
| `GITHUB_CLIENT_ID` / `GITHUB_CLIENT_SECRET` | GitHub OAuth Appの設定値 |
| `GITHUB_CALLBACK_URL` | GitHubのコールバックURL（`.../api/v1/auth/github/callback`） |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | Google OAuthクライアントの設定値 |
| `GOOGLE_CALLBACK_URL` | GoogleのコールバックURL（`.../api/v1/auth/google/callback`） |
| `AUTH_REDIRECT_ALLOWLIST` | ログイン後のリダイレクト先として許可するURL（`,`区切り・完全一致・フラグメントは使用不可） |

- OAuthアプリは本番用と開発用を別々に作成する
- ブログのオリジンは`CORS_ALLOWED_ORIGINS`にも追加する（`POST /auth/exchange`をブラウザから呼ぶため）

## 全体の流れ

1. ブログのJS: `code_verifier`（乱数）を作って`sessionStorage`に保存し、`code_challenge`（ハッシュ値）を計算する
2. ブログ → `GET /auth/{provider}/login?redirect_uri=...&code_challenge=...`（画面遷移）
3. commons → プロバイダーの認可画面へリダイレクト
4. プロバイダー → `GET /auth/{provider}/callback?code=...&state=...`
5. commons → `redirect_uri?code=<一時コード>`へリダイレクト
6. ブログのJS → `POST /auth/exchange`に一時コードと`code_verifier`を送り、外部トークンと交換
7. ブログ → `writers`へ外部トークンを付けてコメントを投稿（`writers`は`POST /tokens/valid`に`allow_external`を付けて確認）

### ブログ側のPKCE

ログインを始めたブラウザだけが一時コードを交換できるようにする（他人のログイン途中のURLを開かされて、別人としてログインしてしまうのを防ぐ）。

- `code_verifier`: 43〜128文字のランダム文字列（半角英数字と`-._~`）。ログインのたびに作り直す
- `code_challenge`: `code_verifier`のSHA-256をBase64URL（パディングなし）にした43文字
- プロバイダーとcommonsの間のPKCEとは別のもの
- 一時コードを受け取ったら、ブログは`history.replaceState`などでURLから`code`を取り除く

## GET /auth/{provider}/login — ログイン開始

- 認証: 不要
- パス: `provider`は`github`／`google`（それ以外は`404`）
- クエリ: `redirect_uri`（ログイン後のリダイレクト先）, `code_challenge`（ブログ側のPKCE）
- 成功: `302`でプロバイダーの認可画面へリダイレクトする
  - `state`と`code_verifier`を生成してDBに保存する（5分で失効）
  - プロバイダーとの間でもPKCE（`code_challenge_method=S256`）を使う
  - スコープ: GitHubは指定なし、Googleは`openid profile`
- `redirect_uri`の指定なし・許可リストにない: `400`（JSONで返す）
- `code_challenge`の指定なし・形式が正しくない: `400`（JSONで返す）
- 設定値（.env）の不足: `500`（JSONで返す）

## GET /auth/{provider}/callback — コールバック

- 認証: 不要（プロバイダーからのリダイレクトで呼ばれる）
- クエリ: `code`, `state`（拒否された場合は`error`）
- `state`がない・無効・期限切れ・`provider`が一致しない: `400`（JSONで返す）
  - 保存したリダイレクト先が使えないため、ブログへは戻さない
- 上記以外は、保存した`redirect_uri`へ`302`でリダイレクトする
  - 成功: `?code=<一時コード>`
  - 失敗: `?error=<理由>`
    - `access_denied`: ユーザーが認可画面で拒否した
    - `suspended`: 停止中の外部ユーザー
    - `provider_error`: プロバイダーとの通信エラー・想定外の応答
- 処理
  1. `state`を照合し、使用したレコードを削除する
  2. 認可コードを`code_verifier`とともにアクセストークンと交換する
  3. ユーザー情報を取得する（アクセストークンは保存せず破棄する）
  4. 外部ユーザーを登録する（2回目以降は表示名・アバターURLを上書きする）
  5. 外部トークン（10分）と一時コード（60秒）を発行する
- 備考
  - 期限切れの外部トークン・`state`・一時コードを削除する
  - 停止中の外部ユーザーの表示名・アバターURLは上書きしない
  - `/auth`のアクセスログにはクエリ（`code`・`state`など）を残さない

## POST /auth/exchange — 一時コードの交換

- 認証: 不要
- ボディ: `{ "code": "<一時コード>", "code_verifier": "..." }`
- 成功: `200`
  - `data: { "token": "<短縮トークン22文字>", "user_id": "gh-12345", "provider": "github", "display_name": "...", "avatar_url": "..." }`
- パラメーター不足: `400`
- 一時コードが無効・期限切れ・使用済み、`code_verifier`が一致しない、停止中の外部ユーザー: `401`
- 備考: 一時コードは1回限り。`code_verifier`が一致しない場合も使用済みになる
