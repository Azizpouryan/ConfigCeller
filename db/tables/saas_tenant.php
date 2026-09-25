<?php

return [
    'create' => <<<SQL
        id char(36) PRIMARY KEY NOT NULL,
        tenant_key varchar(100) NOT NULL UNIQUE,
        name varchar(200) NOT NULL,
        status varchar(32) NOT NULL DEFAULT 'active',
        legacy_key varchar(100) NULL UNIQUE,
        metadata JSON NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        suspended_at DATETIME NULL
        SQL,
];
