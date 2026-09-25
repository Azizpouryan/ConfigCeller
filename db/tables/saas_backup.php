<?php

return [
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_id char(36) NOT NULL UNIQUE,
        tenant_id char(36) NOT NULL,
        version varchar(32) NOT NULL,
        mode varchar(32) NOT NULL,
        status varchar(32) NOT NULL DEFAULT 'created',
        storage_path varchar(1000) NULL,
        manifest JSON NULL,
        checksum char(64) NULL,
        encrypted TINYINT(1) NOT NULL DEFAULT 1,
        initiated_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        completed_at DATETIME NULL,
        KEY idx_saas_backup_tenant_created (tenant_id, created_at),
        CONSTRAINT fk_saas_backup_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenant(id),
        CONSTRAINT fk_saas_backup_initiator FOREIGN KEY (initiated_by) REFERENCES saas_user(id)
        SQL,
];
