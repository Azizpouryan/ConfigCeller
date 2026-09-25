<?php

return [
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_id char(36) NOT NULL UNIQUE,
        tenant_id char(36) NULL,
        bot_id BIGINT UNSIGNED NULL,
        job_type varchar(100) NOT NULL,
        payload JSON NOT NULL,
        status varchar(32) NOT NULL DEFAULT 'pending',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
        available_at DATETIME NOT NULL,
        locked_at DATETIME NULL,
        locked_by varchar(100) NULL,
        last_error TEXT NULL,
        idempotency_key varchar(255) NULL,
        idempotency_key_hash char(64) NULL UNIQUE,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_saas_job_claim (status, available_at),
        KEY idx_saas_job_tenant_status (tenant_id, status),
        CONSTRAINT fk_saas_job_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenant(id),
        CONSTRAINT fk_saas_job_bot FOREIGN KEY (bot_id) REFERENCES saas_bot(id)
        SQL,
];
