<?php

/**
 * Introduce the SaaS control plane without changing the legacy runtime yet.
 *
 * All legacy rows are assigned to one explicit tenant. The new tenant_id and
 * saas_bot_id columns stay nullable in this phase so the existing bot remains
 * backward compatible while each flow is migrated behind a scoped repository.
 */
return static function (PDO $pdo, Schema $schema): void {
    $now = date('Y-m-d H:i:s');

    $uuid = static function (): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    };

    // Every existing table receives a nullable column first. It is backfilled
    // before indexes are applied by db/bootstrap.php. NOT NULL is intentionally
    // deferred until all write paths use TenantContext.
    $tenantTables = [
        'admin',
        'user',
        'help',
        'setting',
        'channels',
        'marzban_panel',
        'product',
        'invoice',
        'Payment_report',
        'Discount',
        'Giftcodeconsumed',
        'PaySetting',
        'DiscountSell',
        'affiliates',
        'shopSetting',
        'cancel_service',
        'service_other',
        'card_number',
        'Requestagent',
        'topicid',
        'manualsell',
        'departman',
        'support_message',
        'wheel_list',
        'botsaz',
        'app',
        'logs_api',
        'category',
        'reagent_report',
    ];

    foreach ($tenantTables as $table) {
        if ($schema->tableExists($table)) {
            $schema->addColumn($table, 'tenant_id', null, 'CHAR(36) NULL');
        }
    }

    foreach (['user', 'invoice', 'Payment_report', 'service_other', 'Requestagent', 'support_message', 'wheel_list', 'reagent_report', 'botsaz'] as $table) {
        if ($schema->tableExists($table)) {
            $schema->addColumn($table, 'saas_bot_id', null, 'BIGINT UNSIGNED NULL');
        }
    }
    if ($schema->tableExists('logs_api')) {
        $schema->addColumn('logs_api', 'actor_user_id', null, 'BIGINT UNSIGNED NULL');
        $schema->addColumn('logs_api', 'saas_bot_id', null, 'BIGINT UNSIGNED NULL');
    }

    $legacyTenant = $pdo->query("SELECT id FROM saas_tenant WHERE legacy_key = 'legacy' LIMIT 1")->fetchColumn();
    if (!is_string($legacyTenant) || $legacyTenant === '') {
        $legacyTenant = $uuid();
        $statement = $pdo->prepare(
            'INSERT INTO saas_tenant (id, tenant_key, name, status, legacy_key, metadata, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $legacyTenant,
            'legacy',
            'Legacy Tenant',
            'active',
            'legacy',
            json_encode(['source' => 'single-tenant-migration'], JSON_UNESCAPED_UNICODE),
            $now,
            $now,
        ]);
    }

    foreach ($tenantTables as $table) {
        if ($schema->tableExists($table) && $schema->hasColumn($table, 'tenant_id')) {
            $statement = $pdo->prepare("UPDATE `{$table}` SET tenant_id = ? WHERE tenant_id IS NULL");
            $statement->execute([$legacyTenant]);
        }
    }

    // The old admin record is copied into the new identity model. A legacy
    // plaintext password is hashed before it crosses the boundary.
    $admins = $pdo->query('SELECT id_admin, username, password, rule FROM admin')->fetchAll(PDO::FETCH_ASSOC);
    $findUser = $pdo->prepare('SELECT id FROM saas_user WHERE legacy_admin_id = ? LIMIT 1');
    $insertUser = $pdo->prepare(
        'INSERT INTO saas_user
            (public_id, legacy_admin_id, username, password_hash, is_master_admin, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertMembership = $pdo->prepare(
        'INSERT IGNORE INTO saas_membership
            (tenant_id, user_id, role, permissions, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($admins as $admin) {
        $findUser->execute([(string) $admin['id_admin']]);
        $userId = $findUser->fetchColumn();
        if ($userId === false) {
            $password = (string) $admin['password'];
            $passwordInfo = password_get_info($password);
            if (($passwordInfo['algo'] ?? 0) === 0) {
                $password = password_hash($password, PASSWORD_DEFAULT);
            }
            $insertUser->execute([
                $uuid(),
                (string) $admin['id_admin'],
                (string) $admin['username'],
                $password,
                strtolower((string) $admin['rule']) === 'administrator' ? 1 : 0,
                'active',
                $now,
                $now,
            ]);
            $userId = $pdo->lastInsertId();
        }

        $role = strtolower((string) $admin['rule']) === 'administrator' ? 'TENANT_OWNER' : 'TENANT_STAFF';
        $insertMembership->execute([
            $legacyTenant,
            (int) $userId,
            $role,
            json_encode([], JSON_UNESCAPED_UNICODE),
            'active',
            $now,
            $now,
        ]);
    }

    // The default plan is metadata only in this phase. Subscription enforcement
    // will be enabled after Auth Context and the Tenant Panel are migrated.
    $planExists = $pdo->query("SELECT id FROM saas_plan WHERE code = 'legacy' LIMIT 1")->fetchColumn();
    if ($planExists === false) {
        $statement = $pdo->prepare(
            'INSERT INTO saas_plan (code, name, status, limits, features, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            'legacy',
            'Legacy Plan',
            'active',
            json_encode(['tenants' => 1, 'bots' => null, 'customers' => null, 'panels' => null], JSON_UNESCAPED_UNICODE),
            json_encode(['legacy_compatibility' => true], JSON_UNESCAPED_UNICODE),
            $now,
            $now,
        ]);
    }

    // SecretBox is deliberately optional during the compatibility migration.
    // If the external key is not configured, the old runtime keeps working and
    // the new Bot Manager marks the imported bot for secret re-encryption.
    require_once __DIR__ . '/../../src/SaaS/SecretBox.php';
    $secretBox = null;
    if (\MirzaBot\SaaS\SecretBox::isConfigured()) {
        $secretBox = \MirzaBot\SaaS\SecretBox::fromEnvironment();
    } else {
        error_log('[saas:foundation] MIRZABOT_SECRET_KEY is not configured; imported bot credentials require re-encryption before managed use.');
    }

    $encrypt = static function (?string $value) use ($secretBox): ?string {
        $value = trim((string) $value);
        if ($value === '' || $secretBox === null) {
            return null;
        }
        return $secretBox->encrypt($value);
    };

    $findBot = $pdo->prepare('SELECT id FROM saas_bot WHERE legacy_botsaz_id = ? LIMIT 1');
    $insertBot = $pdo->prepare(
        'INSERT INTO saas_bot
            (public_id, tenant_id, legacy_botsaz_id, username, token_hash, token_ciphertext,
             webhook_secret_ciphertext, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $updateLegacyBot = $pdo->prepare('UPDATE botsaz SET saas_bot_id = ? WHERE id = ?');
    $botMappings = [];

    $legacyBots = $pdo->query('SELECT id, bot_token, username, webhook_secret FROM botsaz')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($legacyBots as $bot) {
        $findBot->execute([(int) $bot['id']]);
        $saasBotId = $findBot->fetchColumn();
        if ($saasBotId === false) {
            $token = trim((string) $bot['bot_token']);
            $tokenHash = $token === '' ? null : hash('sha256', $token);
            $tokenCiphertext = $encrypt($token);
            $status = $tokenCiphertext !== null ? 'active' : 'MIGRATION_REQUIRED';
            $insertBot->execute([
                $uuid(),
                $legacyTenant,
                (int) $bot['id'],
                (string) $bot['username'],
                $tokenHash,
                $tokenCiphertext,
                $encrypt((string) ($bot['webhook_secret'] ?? '')),
                $status,
                $now,
                $now,
            ]);
            $saasBotId = $pdo->lastInsertId();
        }
        $updateLegacyBot->execute([(int) $saasBotId, (int) $bot['id']]);
        $botMappings[] = [
            (int) $saasBotId,
            trim((string) $bot['bot_token']),
        ];
    }

    // Register the root bot without copying its plaintext token. Placeholder
    // values from an unconfigured install are ignored.
    global $APIKEY, $usernamebot;
    $rootToken = trim((string) ($APIKEY ?? ''));
    if ($rootToken !== '' && !str_contains($rootToken, '{')) {
        $rootHash = hash('sha256', $rootToken);
        $findRoot = $pdo->prepare('SELECT id FROM saas_bot WHERE tenant_id = ? AND token_hash = ? LIMIT 1');
        $findRoot->execute([$legacyTenant, $rootHash]);
        if ($findRoot->fetchColumn() === false) {
            $insertBot->execute([
                $uuid(),
                $legacyTenant,
                null,
                trim((string) ($usernamebot ?? 'root')) ?: 'root',
                $rootHash,
                $encrypt($rootToken),
                null,
                $secretBox === null ? 'MIGRATION_REQUIRED' : 'active',
                $now,
                $now,
            ]);
        }
    }

    // Preserve the existing bottype mapping while the legacy handlers are still
    // active. This is deliberately done only inside the already-created legacy
    // tenant and never accepts a tenant or bot id from request input.
    $legacyBotUpdates = [
        'user' => 'bottype',
        'invoice' => 'bottype',
        'Payment_report' => 'bottype',
    ];
    foreach ($legacyBotUpdates as $table => $bottypeColumn) {
        foreach ($botMappings as [$saasBotId, $token]) {
            if ($token === '') {
                continue;
            }
            $statement = $pdo->prepare(
                "UPDATE `{$table}` SET saas_bot_id = ? WHERE tenant_id = ? AND `{$bottypeColumn}` = ?"
            );
            $statement->execute([$saasBotId, $legacyTenant, $token]);
        }
    }
};
