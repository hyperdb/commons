# API: トークン（/tokens）

共通ルールは`/documents/api-conventions.md`を参照。トークンの発行は`POST /users/auth/{id}`（パスワードユーザー）と`POST /auth/exchange`（外部ユーザー）で行う。

## POST /tokens/valid — 有効性の確認

- 認証: 不要（チェック対象のトークンをボディで渡すため）
- ボディ: `{ "token": "<短縮トークン>", "allow_external": true }`
  - `allow_external`（省略可）: 外部ユーザーのトークンも認める場合だけ、真偽値の`true`を指定する（`"true"`や`1`は不可）
  - 外部ユーザーに許可してよい操作（コメントの投稿など）でだけ指定すること
- 成功: `200`
  - パスワードユーザーのトークンが有効: `data: { "valid": "OK", "user_id": "<ユーザーID>" }`
  - 外部ユーザーのトークンが有効（`allow_external`が`true`の場合のみ）: `data: { "valid": "OK", "user_id": "<外部ユーザーID>", "provider": "github", "display_name": "...", "avatar_url": "..." }`
  - 無効: `data: { "valid": "NG" }`
    - `allow_external`の指定がない場合、外部ユーザーのトークンは有効でもNGになる
  - `user_id`は有効（OK）の場合のみ含める（NGの場合は項目自体を含めない）
  - `provider`・`display_name`・`avatar_url`は外部ユーザーの場合のみ含める（パスワードユーザーの場合は項目自体を含めない）
- パラメータ不足: `400`
- 備考
  - `tokens`を検索し、見つからず`allow_external`が`true`の場合は`external_tokens`も検索する
  - パスワードユーザーのトークンが有効（OK）の場合は有効期限を延長する。外部ユーザーのトークンは延長しない
  - 停止中の外部ユーザーのトークンはNGになる（停止時に削除し、検索時にも除外する）
