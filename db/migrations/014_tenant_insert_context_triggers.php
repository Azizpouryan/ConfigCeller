<?php

/**
 * Make legacy INSERT statements inherit the trusted TenantContext connection
 * variable. This keeps the procedural bot backward-compatible while its write
 * paths are migrated one by one; it never accepts tenant_id from request data.
 */
return static function (PDO $pdo, Schema $schema): void {
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
        $exists = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?'
        );
        $exists->execute([$trigger]);
        if ((int) $exists->fetchColumn() > 0) {
            continue;
        }

        assertSqlIdentifier($trigger);
        assertSqlIdentifier($table);
        $sql = "CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `{$table}`
                FOR EACH ROW
                BEGIN
                    IF @mirza_tenant_id IS NOT NULL AND (NEW.tenant_id IS NULL OR NEW.tenant_id = '') THEN
                        SET NEW.tenant_id = @mirza_tenant_id;
                    END IF;
                END";
        $pdo->exec($sql);
    }
};
