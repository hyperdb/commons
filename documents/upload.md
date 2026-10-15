# 本番環境へのアップロード

## アップロード対象

- `📁app`
- `📁enums`
- `📁shared`
- `📁vendor`
- `index.php`
- `.htaccess`
- `composer.json`
- `composer.lock`

※`📁logs`は空のディレクトリを作成する

## 確認手順

### 第一段階

ダミーの`.env`をアップロードして遮断されているか確認する

- `/`に`commons ver.1.0.0`が表示される
- 以下がどれも JSON の 404 になる
  - `/.env`
  - `/composer.json`
  - `/vendor/autoload.php`
  - `/app/controllers/User.php`
- 存在しないパス（`/abc`）も JSON の 404 になる

### 第二段階

本番用の `.env` に置き換えてAPIの動作を確認

- ログイン認証から一覧取得までを、ローカルと同じ順に試す
