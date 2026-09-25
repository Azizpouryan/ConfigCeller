<?php

return [
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id char(36) NULL,
        actor_user_id BIGINT UNSIGNED NULL,
        bot_id BIGINT UNSIGNED NULL,
        action varchar(100) NOT NULL,
        resource_type varchar(100) NULL,
        resource_id varchar(255) NULL,
        metadata JSON NULL,
        ip_address varchar(45) NULL,
        request_id char(36) NULL,
        created_at DATETIME NOT NULL,
        KEY idx_saas_audit_tenant_created (tenant_id, created_at),
        KEY idx_saas_audit_actor_created (actor_user_id, created_at),
        CONSTRAINT fk_saas_audit_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenant(id),
        CONSTRAINT fk_saas_audit_actor FOREIGN KEY (actor_user_id) REFERENCES saas_user(id),
        CONSTRAINT fk_saas_audit_bot FOREIGN KEY (bot_id) REFERENCES saas_bot(id)
        SQL,
];
