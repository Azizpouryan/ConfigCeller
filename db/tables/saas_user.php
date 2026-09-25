<?php

return [
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_id char(36) NOT NULL UNIQUE,
        legacy_admin_id varchar(191) NULL UNIQUE,
        username varchar(200) NOT NULL,
        email varchar(320) NULL,
        email_hash char(64) NULL UNIQUE,
        password_hash varchar(255) NOT NULL,
        is_master_admin TINYINT(1) NOT NULL DEFAULT 0,
        status varchar(32) NOT NULL DEFAULT 'active',
        last_login_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
        SQL,
];
