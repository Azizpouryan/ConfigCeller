<?php

return [
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id char(36) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        role varchar(64) NOT NULL,
        permissions JSON NULL,
        status varchar(32) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uniq_saas_membership_tenant_user (tenant_id, user_id),
        KEY idx_saas_membership_tenant_status (tenant_id, status),
        CONSTRAINT fk_saas_membership_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenant(id),
        CONSTRAINT fk_saas_membership_user FOREIGN KEY (user_id) REFERENCES saas_user(id)
        SQL,
];
