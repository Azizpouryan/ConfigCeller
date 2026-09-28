<?php

/**
 * Remove the most dangerous global Legacy identifiers. Tenant-scoped query
 * guards already protect reads/writes; this migration also allows two tenants
 * to use the same Telegram user id or the same configuration key.
 *
 * The migration refuses to continue while any migrated table still has an
 * unassigned row. It never silently assigns a row to a tenant.
 */
return static function (PDO $pdo, Schema $schema): void {
    $primaryKeys = [
        'admin' => 'id_admin',
        'user' => 'id',
        'invoice' => 'id_invoice',
        'topicid' => 'report',
        'PaySetting' => 'NamePay',
        'shopSetting' => 'Namevalue',
        'card_number' => 'cardnumber',
        'Requestagent' => 'id',
    ];

    foreach ($primaryKeys as $table => $column) {
        if (!$schema->tableExists($table) || !$schema->hasColumn($table, 'tenant_id') || !$schema->hasColumn($table, $column)) {
            continue;
        }
        $missing = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE tenant_id IS NULL OR tenant_id = ''");
        $missing->execute();
        if ((int) $missing->fetchColumn() !== 0) {
            throw new RuntimeException("Cannot scope {$table}: rows without tenant_id remain.");
        }

        $part = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' AND COLUMN_NAME = 'tenant_id'"
        );
        $part->execute([$table]);
        if ((int) $part->fetchColumn() > 0) {
            continue;
        }

        assertSqlIdentifier($table);
        assertSqlIdentifier($column);
        $pdo->exec("ALTER TABLE `{$table}` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`, `{$column}`)");
    }

    if ($schema->tableExists('reagent_report') && $schema->hasColumn('reagent_report', 'tenant_id')) {
        $missing = $pdo->query("SELECT COUNT(*) FROM reagent_report WHERE tenant_id IS NULL OR tenant_id = ''")->fetchColumn();
        if ((int) $missing !== 0) {
            throw new RuntimeException('Cannot scope reagent_report: rows without tenant_id remain.');
        }
        $indexes = $pdo->query('SHOW INDEX FROM `reagent_report`')->fetchAll(PDO::FETCH_ASSOC);
        $hasScopedUnique = false;
        $toDrop = [];
        foreach ($indexes as $index) {
            if ((string) ($index['Key_name'] ?? '') === 'PRIMARY') {
                continue;
            }
            if ((int) ($index['Non_unique'] ?? 1) === 0) {
                if ((string) ($index['Key_name'] ?? '') === 'uniq_reagent_tenant_user') {
                    $hasScopedUnique = true;
                } else {
                    $toDrop[(string) $index['Key_name']] = true;
                }
            }
        }
        foreach (array_keys($toDrop) as $index) {
            assertSqlIdentifier($index);
            $pdo->exec("ALTER TABLE reagent_report DROP INDEX `{$index}`");
        }
        if (!$hasScopedUnique) {
            $pdo->exec('ALTER TABLE reagent_report ADD UNIQUE KEY `uniq_reagent_tenant_user` (`tenant_id`, `user_id`)');
        }
    }

    if ($schema->tableExists('departman') && $schema->hasColumn('departman', 'tenant_id')) {
        $index = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departman' AND INDEX_NAME = 'uniq_departman_entry'");
        $index->execute();
        if ((int) $index->fetchColumn() > 0) {
            $pdo->exec('ALTER TABLE departman DROP INDEX `uniq_departman_entry`');
        }
        $pdo->exec('ALTER TABLE departman ADD UNIQUE KEY `uniq_departman_entry` (`tenant_id`, `idsupport`(100), `name_departman`(150))');
    }
};
