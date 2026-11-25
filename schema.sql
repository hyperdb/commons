/*
 * テーブル定義の正本
 * 各エンティティの意味・業務ルールは`/documents/Entities.md`を参照のこと
 */

/*
 * エンティティ: ユーザー
 * パスワードハッシュは現行60桁だが将来の拡張を考慮してVARCHAR(255)とした
 */
CREATE TABLE IF NOT EXISTS users (
    `id` VARCHAR(32) NOT NULL COMMENT 'ユーザーID',
    `password_hash` VARCHAR(255) NOT NULL COMMENT 'パスワードハッシュ',
    `user_level` CHAR(3) NOT NULL DEFAULT '000' COMMENT '権限レベル',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ユーザー';

/*
 * エンティティ: トークン
 *
 * token: UUIDv4（ハイフン付き36文字）
 * owner: users.id（ユーザー削除時はトークンも削除）
 * expired_at: 有効期限の日時をUNIXタイムで保存。最終認証の時間 + 60*60秒
 */
CREATE TABLE IF NOT EXISTS tokens (
    `token` VARCHAR(255) NOT NULL COMMENT 'トークン',
    `owner` VARCHAR(32) NOT NULL COMMENT '所有ユーザーID',
    `expired_at` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '有効期限',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',
    PRIMARY KEY (`token`),
    KEY `idx_tokens_owner` (`owner`),
    KEY `idx_tokens_expired_at` (`expired_at`),
    CONSTRAINT `fk_tokens_owner` FOREIGN KEY (`owner`)
        REFERENCES users (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='トークン';

/*
 * エンティティ: 外部ユーザー（GitHub／Googleでログインしたユーザー）
 *
 * id: `gh-<GitHubの数値ID>`／`gg-<ランダム文字列>`
 * provider_user_id: GitHubの`id`／Googleの`sub`（英数字のみのためascii。インデックスの長さ制限対策も兼ねる）
 * stopped_at: 停止日時（NULLなら利用可能）
 */
CREATE TABLE IF NOT EXISTS external_users (
    `id` VARCHAR(32) NOT NULL COMMENT 'ユーザーID',
    `provider` VARCHAR(16) NOT NULL COMMENT 'プロバイダー',
    `provider_user_id` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'プロバイダー側のユーザーID',
    `display_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '表示名',
    `avatar_url` VARCHAR(2048) NOT NULL DEFAULT '' COMMENT 'アバターURL',
    `stopped_at` TIMESTAMP NULL DEFAULT NULL COMMENT '停止日時',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_external_users_provider` (`provider`, `provider_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='外部ユーザー';

/*
 * エンティティ: 外部トークン（外部ユーザー用のトークン）
 *
 * token: UUIDv4（ハイフン付き36文字）
 * owner: external_users.id（外部ユーザー削除時はトークンも削除）
 * expired_at: 有効期限の日時をUNIXタイムで保存。発行時間 + 10*60秒（延長しない）
 */
CREATE TABLE IF NOT EXISTS external_tokens (
    `token` VARCHAR(255) NOT NULL COMMENT 'トークン',
    `owner` VARCHAR(32) NOT NULL COMMENT '所有ユーザーID',
    `expired_at` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '有効期限',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',
    PRIMARY KEY (`token`),
    KEY `idx_external_tokens_owner` (`owner`),
    KEY `idx_external_tokens_expired_at` (`expired_at`),
    CONSTRAINT `fk_external_tokens_owner` FOREIGN KEY (`owner`)
        REFERENCES external_users (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='外部トークン';

/*
 * エンティティ: OAuthの状態（ログイン開始からコールバックまでの間だけ保持）
 *
 * state: ランダム文字列（Base64URL・43文字。大文字と小文字を区別するためascii_bin）
 * code_verifier: プロバイダーとの間で使うPKCEの検証用文字列（Base64URL・43文字）
 * code_challenge: ブログとの間で使うPKCEのハッシュ値（Base64URL・43文字）
 * expired_at: 発行時間 + 5*60秒（UNIXタイム）
 */
CREATE TABLE IF NOT EXISTS oauth_states (
    `state` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'state',
    `provider` VARCHAR(16) NOT NULL COMMENT 'プロバイダー',
    `code_verifier` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'PKCEの検証用文字列（プロバイダー用）',
    `code_challenge` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'PKCEのハッシュ値（ブログ用）',
    `redirect_uri` VARCHAR(2048) NOT NULL COMMENT 'ログイン後のリダイレクト先',
    `expired_at` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '有効期限',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    PRIMARY KEY (`state`),
    KEY `idx_oauth_states_expired_at` (`expired_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OAuthの状態';

/*
 * エンティティ: 一時コード（外部トークンとの交換用・1回限り）
 *
 * code: ランダム文字列（Base64URL・43文字。大文字と小文字を区別するためascii_bin）
 * token: external_tokens.token（トークン削除時は一時コードも削除）
 * code_challenge: oauth_states.code_challengeを引き継ぐ（交換時に照合する）
 * expired_at: 発行時間 + 60秒（UNIXタイム）
 */
CREATE TABLE IF NOT EXISTS auth_codes (
    `code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '一時コード',
    `token` VARCHAR(255) NOT NULL COMMENT '交換するトークン',
    `code_challenge` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'PKCEのハッシュ値（ブログ用）',
    `expired_at` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '有効期限',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    PRIMARY KEY (`code`),
    KEY `idx_auth_codes_expired_at` (`expired_at`),
    CONSTRAINT `fk_auth_codes_token` FOREIGN KEY (`token`)
        REFERENCES external_tokens (`token`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='一時コード';

CREATE TABLE IF NOT EXISTS prefectures (
    `cd` CHAR(2) NOT NULL COMMENT '都道府県コード',
    `name` VARCHAR(8) NOT NULL COMMENT '都道府県名',
    `kana` VARCHAR(16) NOT NULL COMMENT '都道府県名かな',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',
    PRIMARY KEY (`cd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='都道府県';

CREATE TABLE IF NOT EXISTS municipalities (
    `cd` CHAR(5) NOT NULL COMMENT '市区町村コード',
    `pref_cd` CHAR(2) NOT NULL COMMENT '都道府県コード',
    `name` VARCHAR(16) NOT NULL COMMENT '市区町村名',
    `kana` VARCHAR(32) NOT NULL COMMENT '市区町村名かな',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',
    PRIMARY KEY (`cd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='市区町村';
