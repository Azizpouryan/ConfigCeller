<?php

return [
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_id char(36) NOT NULL UNIQUE,
        tenant_id char(36) NOT NULL,
        legacy_botsaz_id INT UNSIGNED NULL UNIQUE,
        username varchar(200) NOT NULL,
        token_hash char(64) NULL UNIQUE,
        token_ciphertext TEXT NULL,
        webhook_secret_ciphertext TEXT NULL,
        status varchar(32) NOT NULL DEFAULT 'MIGRATION_REQUIRED',
        last_seen_at DATETIME NULL,
        last_health_check_at DATETIME NULL,
        last_error TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        deleted_at DATETIME NULL,
        KEY idx_saas_bot_tenant_status (tenant_id, status),
        CONSTRAINT fk_saas_bot_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenant(id)
        SQL,
];
