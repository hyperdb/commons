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
