# API: 市区町村（`/municipalities`）

共通ルールは`/documents/api-conventions.md`を参照。項目の意味は`/documents/Entities.md`を参照。

※市区町村はGETのみ提供
※実装上必要なものは空の関数で対応
※データ更新はAPI外で対応

## GET /municipalities — 一覧取得

- 認証: 不要
- クエリ: `page`, `rows`（共通ルール参照）, `pref`
  - `pref={都道府県CD}`が指定された場合は都道府県でフィルター（通常はこちらを使用）
- 成功: `200` `data: [市区町村情報, ...]`

## GET /municipalities/{cd} — 詳細取得

- 認証: 不要
- 成功: `200` `data: [市区町村情報]`
- 該当なし: `404`
