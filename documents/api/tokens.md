# API: トークン（/tokens）

共通ルールは`/documents/api-conventions.md`を参照。トークンの発行は`POST /users/auth/{id}`で行う。

## POST /tokens/valid — 有効性の確認

- 認証: 不要（チェック対象のトークンをボディで渡すため）
- ボディ: `{ "token": "<短縮トークン>" }`
- 成功: `200` `data: { "valid": "OK", "user_id": "<トークンの所有ユーザーID>" }` または `data: { "valid": "NG" }`
  - `user_id`は有効（OK）の場合のみ含める（NGの場合は項目自体を含めない）
- パラメータ不足: `400`
- 備考: 有効（OK）の場合は有効期限を延長する
