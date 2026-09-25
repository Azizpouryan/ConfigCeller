<?php

return [
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_id char(36) NOT NULL UNIQUE,
        tenant_id char(36) NOT NULL,
        plan_id BIGINT UNSIGNED NOT NULL,
        status varchar(32) NOT NULL DEFAULT 'trial',
        starts_at DATETIME NOT NULL,
        current_period_end DATETIME NOT NULL,
        grace_until DATETIME NULL,
        suspended_at DATETIME NULL,
        external_provider varchar(64) NULL,
        external_id varchar(255) NULL,
        metadata JSON NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_saas_subscription_tenant_status (tenant_id, status),
        KEY idx_saas_subscription_period (status, current_period_end),
        CONSTRAINT fk_saas_subscription_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenant(id),
        CONSTRAINT fk_saas_subscription_plan FOREIGN KEY (plan_id) REFERENCES saas_plan(id)
        SQL,
];
