# API: 都道府県（`/prefectures`）

共通ルールは`/documents/api-conventions.md`を参照。項目の意味は`/documents/Entities.md`を参照。

※都道府県はGETのみ提供
※実装上必要なものは空の関数で対応
※データ更新はAPI外で対応

## GET /prefectures — 一覧取得

- 認証: 不要
- クエリ: `page`, `rows`（共通ルール参照）, `order`
  - `order=kana`が指定された場合はコード順ではなくかな順で返す
- 成功: `200` `data: [都道府県情報, ...]`

## GET /prefectures/{cd} — 詳細取得

- 認証: 不要
- 成功: `200` `data: [都道府県情報]`
- 該当なし: `404`
