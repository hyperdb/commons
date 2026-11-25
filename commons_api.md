# commons API

**バージョン:** 1.0.0 / **OpenAPI:** 3.1.0

個人サイトで共通に利用するREST APIサーバー（FlightPHP + MySQL）。

共通ルールは`/documents/api-conventions.md`、エンドポイント仕様は`/documents/api/<リソース名>.md`を正本とする。

## 権限レベル

`000`（権限なし） < `001`（一般ユーザー） < `010`（編集可能ユーザー） < `111`（システム管理者）の単純な大小で比較する。

- 取得系（GET）: `001`以上
- 更新系（POST / PUT / DELETE）: `010`以上
- 個別に記載がある場合はそちらを優先

## トークン

- `POST /users/auth/{id}`で発行された短縮トークン（Base64URL・22文字）を`Authorization: Bearer <token>`で付与する
- 有効期限内であれば処理を行い、有効期限を延長する（現状は仮で1時間）。権限レベル不足（403）の場合も延長する

- ライセンス: MIT

## サーバー

| URL | 説明 |
| --- | --- |
| `https://commons.hyperdb.cloud/api/v1` | 本番 |
| `http://localhost:8001/api/v1` | ローカル開発環境 |

## 認証

| 名前 | タイプ | 詳細 | 説明 |
| --- | --- | --- | --- |
| `bearerAuth` | http | scheme: bearer | `POST /users/auth/{id}`で発行された短縮トークン（Base64URL・22文字） |

## エンドポイント一覧

| タグ | メソッド | パス | 概要 |
| --- | --- | --- | --- |
| users | `POST` | [`/users/auth/{id}`](#op-post-users-auth-id) | ログイン認証 |
| users | `GET` | [`/users`](#op-get-users) | 一覧取得 |
| users | `POST` | [`/users`](#op-post-users) | 登録 |
| users | `GET` | [`/users/{id}`](#op-get-users-id) | 詳細取得 |
| users | `PUT` | [`/users/{id}`](#op-put-users-id) | 更新（パスワード変更） |
| users | `DELETE` | [`/users/{id}`](#op-delete-users-id) | 削除 |
| users | `PUT` | [`/users/{id}/level`](#op-put-users-id-level) | 更新（権限変更） |
| tokens | `POST` | [`/tokens/valid`](#op-post-tokens-valid) | 有効性の確認 |

## エンドポイント

### users

ユーザー

<a id="op-post-users-auth-id"></a>

#### POST /users/auth/{id}

**ログイン認証**

パスワードを検証し、短縮トークンを発行する。認証時に期限切れトークンを削除する。
IDが存在しない場合もパスワード違いと同じ応答（401）にする。

- operationId: `authUser`
- 認証: 不要

##### パラメーター

| 名前 | 場所 | 型 | 必須 | 説明 |
| --- | --- | --- | --- | --- |
| `id` | path | [UserIdValue](#schema-useridvalue) | ✓ | ユーザーID<br>例: `abcd`<br>制約: 最小長 4, 最大長 32, パターン `^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$` |

##### リクエストボディ

- 必須: ✓

| Content-Type | スキーマ |
| --- | --- |
| `application/json` | object |

| プロパティ | 型 | 必須 | 説明 |
| --- | --- | --- | --- |
| `password` | [Password](#schema-password) | ✓ |  |

##### レスポンス

| ステータス | 説明 | Content-Type | スキーマ |
| --- | --- | --- | --- |
| `200` | 認証成功 | `application/json` | [ResponseBase](#schema-responsebase) & object |
| `401` | 認証失敗・トークンが無い／無効／期限切れ | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `500` | サーバー内部のエラー（詳細はログにのみ出力） | `application/json` | [ErrorResponse](#schema-errorresponse) |

**200 レスポンス (`application/json`)**

| プロパティ | 型 | 必須 | 説明 |
| --- | --- | --- | --- |
| `data` | object |  |  |
| `data.token` | [ShortToken](#schema-shorttoken) | ✓ |  |

<a id="op-get-users"></a>

#### GET /users

**一覧取得**

必要な権限レベル: `001`以上

- operationId: `listUsers`
- 認証: `bearerAuth`

##### パラメーター

| 名前 | 場所 | 型 | 必須 | 説明 |
| --- | --- | --- | --- | --- |
| `page` | query | integer |  | ページ番号（0始まり）。負の値は0として扱う。最終ページを超えた場合は空配列を返す<br>デフォルト: `0` |
| `rows` | query | integer |  | 1ページあたりの件数。0以下は既定値（10）として扱う<br>デフォルト: `10` |

##### レスポンス

| ステータス | 説明 | Content-Type | スキーマ |
| --- | --- | --- | --- |
| `200` | 取得成功（該当なしは空配列） | `application/json` | [ResponseBase](#schema-responsebase) & object |
| `401` | 認証失敗・トークンが無い／無効／期限切れ | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `403` | 権限レベル不足 | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `500` | サーバー内部のエラー（詳細はログにのみ出力） | `application/json` | [ErrorResponse](#schema-errorresponse) |

**200 レスポンス (`application/json`)**

| プロパティ | 型 | 必須 | 説明 |
| --- | --- | --- | --- |
| `data` | array&lt;[User](#schema-user)&gt; |  |  |

<a id="op-post-users"></a>

#### POST /users

**登録**

必要な権限レベル: `010`以上

権限レベルは`001`（一般ユーザー）で登録される。

- operationId: `addUser`
- 認証: `bearerAuth`

##### リクエストボディ

- 必須: ✓

| Content-Type | スキーマ |
| --- | --- |
| `application/json` | object |

| プロパティ | 型 | 必須 | 説明 |
| --- | --- | --- | --- |
| `id` | [UserIdValue](#schema-useridvalue) | ✓ |  |
| `password` | [Password](#schema-password) | ✓ |  |

##### レスポンス

| ステータス | 説明 | Content-Type | スキーマ |
| --- | --- | --- | --- |
| `201` | 成功（ユーザー情報を返す） | `application/json` | [ResponseBase](#schema-responsebase) & object |
| `400` | パラメータ不足・バリデーションエラー | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `401` | 認証失敗・トークンが無い／無効／期限切れ | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `403` | 権限レベル不足 | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `409` | 登録しようとしたIDが既に存在する | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `500` | サーバー内部のエラー（詳細はログにのみ出力） | `application/json` | [ErrorResponse](#schema-errorresponse) |

**201 レスポンス (`application/json`)**

| プロパティ | 型 | 必須 | 説明 |
| --- | --- | --- | --- |
| `data` | [User](#schema-user) |  |  |

<a id="op-get-users-id"></a>

#### GET /users/{id}

**詳細取得**

必要な権限レベル: `001`以上

- operationId: `getUser`
- 認証: `bearerAuth`

##### パラメーター

| 名前 | 場所 | 型 | 必須 | 説明 |
| --- | --- | --- | --- | --- |
| `id` | path | [UserIdValue](#schema-useridvalue) | ✓ | ユーザーID<br>例: `abcd`<br>制約: 最小長 4, 最大長 32, パターン `^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$` |

##### レスポンス

| ステータス | 説明 | Content-Type | スキーマ |
| --- | --- | --- | --- |
| `200` | 成功（ユーザー情報を返す） | `application/json` | [ResponseBase](#schema-responsebase) & object |
| `401` | 認証失敗・トークンが無い／無効／期限切れ | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `403` | 権限レベル不足 | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `404` | 指定したリソースが存在しない | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `500` | サーバー内部のエラー（詳細はログにのみ出力） | `application/json` | [ErrorResponse](#schema-errorresponse) |

**200 レスポンス (`application/json`)**

| プロパティ | 型 | 必須 | 説明 |
| --- | --- | --- | --- |
| `data` | [User](#schema-user) |  |  |

<a id="op-put-users-id"></a>

#### PUT /users/{id}

**更新（パスワード変更）**

必要な権限レベル（共通ルールの例外）

- 自分のパスワード: `001`以上
- 自分以外のユーザーのパスワード: `111`（システム管理者）のみ

- operationId: `updateUserPassword`
- 認証: `bearerAuth`

##### パラメーター

| 名前 | 場所 | 型 | 必須 | 説明 |
| --- | --- | --- | --- | --- |
| `id` | path | [UserIdValue](#schema-useridvalue) | ✓ | ユーザーID<br>例: `abcd`<br>制約: 最小長 4, 最大長 32, パターン `^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$` |

##### リクエストボディ

- 必須: ✓

| Content-Type | スキーマ |
| --- | --- |
| `application/json` | object |

| プロパティ | 型 | 必須 | 説明 |
| --- | --- | --- | --- |
| `password` | [Password](#schema-password) | ✓ |  |

##### レスポンス

| ステータス | 説明 | Content-Type | スキーマ |
| --- | --- | --- | --- |
| `200` | 成功（`data`は空配列） | `application/json` | [ResponseBase](#schema-responsebase) |
| `400` | パラメータ不足・バリデーションエラー | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `401` | 認証失敗・トークンが無い／無効／期限切れ | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `403` | 権限レベル不足 | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `404` | 指定したリソースが存在しない | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `500` | サーバー内部のエラー（詳細はログにのみ出力） | `application/json` | [ErrorResponse](#schema-errorresponse) |

<a id="op-delete-users-id"></a>

#### DELETE /users/{id}

**削除**

必要な権限レベル: `010`以上

そのユーザーのトークンも削除される。

- operationId: `deleteUser`
- 認証: `bearerAuth`

##### パラメーター

| 名前 | 場所 | 型 | 必須 | 説明 |
| --- | --- | --- | --- | --- |
| `id` | path | [UserIdValue](#schema-useridvalue) | ✓ | ユーザーID<br>例: `abcd`<br>制約: 最小長 4, 最大長 32, パターン `^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$` |

##### レスポンス

| ステータス | 説明 | Content-Type | スキーマ |
| --- | --- | --- | --- |
| `200` | 成功（`data`は空配列） | `application/json` | [ResponseBase](#schema-responsebase) |
| `401` | 認証失敗・トークンが無い／無効／期限切れ | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `403` | 権限レベル不足 | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `404` | 指定したリソースが存在しない | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `500` | サーバー内部のエラー（詳細はログにのみ出力） | `application/json` | [ErrorResponse](#schema-errorresponse) |

<a id="op-put-users-id-level"></a>

#### PUT /users/{id}/level

**更新（権限変更）**

必要な権限レベル（共通ルールの例外）

- 自分の権限レベル: 変更できない（403）
- 自分以外のユーザーの権限レベル: `111`（システム管理者）のみ

- operationId: `updateUserLevel`
- 認証: `bearerAuth`

##### パラメーター

| 名前 | 場所 | 型 | 必須 | 説明 |
| --- | --- | --- | --- | --- |
| `id` | path | [UserIdValue](#schema-useridvalue) | ✓ | ユーザーID<br>例: `abcd`<br>制約: 最小長 4, 最大長 32, パターン `^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$` |

##### リクエストボディ

- 必須: ✓

| Content-Type | スキーマ |
| --- | --- |
| `application/json` | object |

| プロパティ | 型 | 必須 | 説明 |
| --- | --- | --- | --- |
| `user_level` | [UserLevel](#schema-userlevel) | ✓ |  |

##### レスポンス

| ステータス | 説明 | Content-Type | スキーマ |
| --- | --- | --- | --- |
| `200` | 成功（`data`は空配列） | `application/json` | [ResponseBase](#schema-responsebase) |
| `400` | パラメータ不足・バリデーションエラー | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `401` | 認証失敗・トークンが無い／無効／期限切れ | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `403` | 権限レベル不足 | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `404` | 指定したリソースが存在しない | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `500` | サーバー内部のエラー（詳細はログにのみ出力） | `application/json` | [ErrorResponse](#schema-errorresponse) |

### tokens

トークン

<a id="op-post-tokens-valid"></a>

#### POST /tokens/valid

**有効性の確認**

チェック対象のトークンをボディで渡すため認証不要。
有効（OK）の場合は有効期限を延長する。

- operationId: `validToken`
- 認証: 不要

##### リクエストボディ

- 必須: ✓

| Content-Type | スキーマ |
| --- | --- |
| `application/json` | object |

| プロパティ | 型 | 必須 | 説明 |
| --- | --- | --- | --- |
| `token` | [ShortToken](#schema-shorttoken) | ✓ |  |

##### レスポンス

| ステータス | 説明 | Content-Type | スキーマ |
| --- | --- | --- | --- |
| `200` | 確認結果（`user_id`は有効（OK）の場合のみ含める） | `application/json` | [ResponseBase](#schema-responsebase) & object |
| `400` | パラメータ不足・バリデーションエラー | `application/json` | [ErrorResponse](#schema-errorresponse) |
| `500` | サーバー内部のエラー（詳細はログにのみ出力） | `application/json` | [ErrorResponse](#schema-errorresponse) |

**200 レスポンス (`application/json`)**

| プロパティ | 型 | 必須 | 説明 |
| --- | --- | --- | --- |
| `data` | object または object |  |  |

## スキーマ

<a id="schema-responsebase"></a>

### ResponseBase

共通レスポンス形式

| プロパティ | 型 | 必須 | 説明 |
| --- | --- | --- | --- |
| `api_version` | string | ✓ | APIのバージョン<br>例: `1.0.0` |
| `api_route` | string | ✓ | リクエストされたURL<br>例: `/api/v1/users/abcd` |
| `send_at` | string | ✓ | レスポンス送信日時（`Y-m-d H:i:s`、日本時間）<br>例: `2026-10-13 17:00:00` |
| `message` | string | ✓ | 利用者向けの日本語メッセージ |
| `data` | any | ✓ | 結果データ。一覧はオブジェクトの配列、該当なしは空配列 |

<a id="schema-errorresponse"></a>

### ErrorResponse

- 継承: [ResponseBase](#schema-responsebase)

| プロパティ | 型 | 必須 | 説明 |
| --- | --- | --- | --- |
| `data` | object または array&lt;any&gt; |  | バリデーションエラーの場合は`errors`に項目名をキーとしたエラーメッセージの配列を入れる。それ以外は空配列 |
| `data.errors` | map&lt;string, array&lt;string&gt;&gt; |  |  |

<a id="schema-user"></a>

### User

ユーザー情報（`password_hash`は返さない）

| プロパティ | 型 | 必須 | 説明 |
| --- | --- | --- | --- |
| `id` | [UserIdValue](#schema-useridvalue) | ✓ |  |
| `user_level` | [UserLevel](#schema-userlevel) | ✓ |  |
| `created_at` | string | ✓ | データ作成日時<br>例: `2026-10-13 17:00:00` |
| `updated_at` | string | ✓ | データ更新日時<br>例: `2026-10-13 17:00:00` |

<a id="schema-useridvalue"></a>

### UserIdValue

ユーザーID（半角の英小文字と数字と一部の記号（-_.）、4文字以上32文字以内。先頭と末尾に記号は使用不可）

- 型: string
- 例: `abcd`
- 制約: 最小長 4, 最大長 32, パターン `^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$`

<a id="schema-password"></a>

### Password

パスワード（8文字以上72文字以内、半角の英数字・記号のみ。英大文字・英小文字・数字・記号をそれぞれ1文字以上含む）

- 型: string (password)
- 制約: 最小長 8, 最大長 72, パターン `^[\x21-\x7e]+$`

<a id="schema-userlevel"></a>

### UserLevel

権限レベル（`000`: 権限なし / `001`: 一般ユーザー / `010`: 編集可能ユーザー / `111`: システム管理者）

- 型: string
- 列挙値: `000`, `001`, `010`, `111`

<a id="schema-shorttoken"></a>

### ShortToken

短縮トークン（Base64URL・22文字）

- 型: string
- 例: `AbCdEfGhIjKlMnOpQrStUv`
- 制約: パターン `^[A-Za-z0-9_-]{22}$`

