<?php

return [
    'create' => <<<SQL
        migration varchar(255) PRIMARY KEY NOT NULL,
        checksum char(64) NOT NULL,
        applied_at DATETIME NOT NULL
        SQL,
];
