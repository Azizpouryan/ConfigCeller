<?php

return [
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id char(36) NOT NULL,
        hostname varchar(255) NOT NULL,
        hostname_hash char(64) NOT NULL UNIQUE,
        domain_type varchar(32) NOT NULL DEFAULT 'tenant',
        verification_status varchar(32) NOT NULL DEFAULT 'pending',
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        verification_token_hash char(64) NULL,
        verified_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_saas_domain_tenant_status (tenant_id, verification_status),
        CONSTRAINT fk_saas_domain_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenant(id)
        SQL,
];
