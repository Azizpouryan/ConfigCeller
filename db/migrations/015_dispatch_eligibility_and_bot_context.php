<?php

/**
 * Make dispatch eligibility explicit and make trusted bot context propagate to
 * legacy INSERTs alongside tenant context. A Tenant is not eligible for the
 * legacy Telegram core until its core_dispatch_status is set to ready by a
 * reviewed migration step.
 */
return static function (PDO $pdo, Schema $schema): void {
    if ($schema->tableExists('saas_tenant')) {
        $schema->addColumn(
            'saas_tenant',
            'core_dispatch_status',
            null,
            "VARCHAR(32) NOT NULL DEFAULT 'pending'"
        );
        $pdo->exec("UPDATE saas_tenant SET core_dispatch_status = 'ready' WHERE legacy_key = 'legacy' AND (core_dispatch_status IS NULL OR core_dispatch_status = 'pending')");
        $pdo->exec("UPDATE saas_tenant SET core_dispatch_status = 'pending' WHERE legacy_key IS NULL AND (core_dispatch_status IS NULL OR core_dispatch_status = '')");
    }

    $tables = [
        'admin', 'user', 'help', 'setting', 'channels', 'marzban_panel', 'product',
        'invoice', 'Payment_report', 'Discount', 'Giftcodeconsumed', 'PaySetting',
        'DiscountSell', 'affiliates', 'shopSetting', 'cancel_service', 'service_other',
        'card_number', 'Requestagent', 'topicid', 'manualsell', 'departman',
        'support_message', 'wheel_list', 'botsaz', 'app', 'logs_api', 'category',
        'reagent_report',
    ];

    foreach ($tables as $table) {
        if (!$schema->tableExists($table) || !$schema->hasColumn($table, 'tenant_id')) {
            continue;
        }
        $trigger = 'trg_mirza_tenant_insert_' . strtolower($table);
        assertSqlIdentifier($trigger);
        assertSqlIdentifier($table);

        // Migration 014 may already have installed the tenant-only trigger.
        // Recreating it is required because MySQL does not support ALTER TRIGGER.
        $pdo->exec("DROP TRIGGER IF EXISTS `{$trigger}`");
        $body = "IF @mirza_tenant_id IS NOT NULL AND (NEW.tenant_id IS NULL OR NEW.tenant_id = '') THEN
                    SET NEW.tenant_id = @mirza_tenant_id;
                END IF;";
        if ($schema->hasColumn($table, 'saas_bot_id')) {
            $body .= "
                IF @mirza_saas_bot_id IS NOT NULL AND (NEW.saas_bot_id IS NULL OR NEW.saas_bot_id = 0) THEN
                    SET NEW.saas_bot_id = @mirza_saas_bot_id;
                END IF;";
        }
        $pdo->exec("CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `{$table}`
                    FOR EACH ROW
                    BEGIN
                        {$body}
                    END");
    }
};
