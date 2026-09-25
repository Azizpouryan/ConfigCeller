<?php

return [
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code varchar(100) NOT NULL UNIQUE,
        name varchar(200) NOT NULL,
        status varchar(32) NOT NULL DEFAULT 'active',
        limits JSON NOT NULL,
        features JSON NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
        SQL,
];
