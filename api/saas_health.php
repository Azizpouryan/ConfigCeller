<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../panel/inc/saas_auth.php';

header('Content-Type: application/json; charset=utf-8');
$auth = saas_current_auth();
if ($auth === null || !$auth->isMasterAdmin()) {
    sendJsonResponse(false, 'master admin required', [], 403);
}
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    sendJsonResponse(false, 'method not allowed', [], 405);
}

$checks = [];
$requiredTables = [
    'saas_tenant', 'saas_user', 'saas_membership', 'saas_subscription',
    'saas_bot', 'saas_job', 'saas_backup', 'schema_migrations',
];
try {
    $checks['database'] = ['ok' => (int) $pdo->query('SELECT 1')->fetchColumn() === 1];
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $statement = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})"
    );
    $statement->execute($requiredTables);
    $present = array_map('strtolower', $statement->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_filter($requiredTables, static fn(string $table): bool => !in_array(strtolower($table), $present, true)));
    $migrationNames = ['013_saas_foundation', '014_tenant_insert_context_triggers', '015_dispatch_eligibility_and_bot_context', '016_tenant_scoped_legacy_keys'];
    $migrationStatement = $pdo->query('SELECT migration FROM schema_migrations');
    $appliedMigrations = array_map('strval', $migrationStatement->fetchAll(PDO::FETCH_COLUMN));
    $missingMigrations = array_values(array_diff($migrationNames, $appliedMigrations));
    $checks['schema'] = ['ok' => $missing === [] && $missingMigrations === [], 'missing' => $missing, 'missing_migrations' => $missingMigrations];

    $legacyTables = [
        'admin', 'user', 'help', 'setting', 'channels', 'marzban_panel', 'product',
        'invoice', 'Payment_report', 'Discount', 'Giftcodeconsumed', 'PaySetting',
        'DiscountSell', 'affiliates', 'shopSetting', 'cancel_service', 'service_other',
        'card_number', 'Requestagent', 'topicid', 'manualsell', 'departman',
        'support_message', 'wheel_list', 'botsaz', 'app', 'logs_api', 'category',
        'reagent_report',
    ];
    $nullTenantRows = [];
    foreach ($legacyTables as $table) {
        if (!in_array(strtolower($table), $present, true)) {
            continue;
        }
        $nullTenantRows[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE tenant_id IS NULL OR tenant_id = ''")->fetchColumn();
    }
    $checks['tenant_backfill'] = [
        'ok' => array_sum($nullTenantRows) === 0,
        'null_rows' => $nullTenantRows,
    ];

    $checks['secret_key'] = ['ok' => \MirzaBot\SaaS\SecretBox::isConfigured()];
    $baseUrl = trim((string) getenv('MIRZABOT_WEBHOOK_BASE_URL'));
    $parts = parse_url($baseUrl);
    $checks['webhook_base_url'] = [
        'ok' => is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']),
    ];
    $checks['extensions'] = [
        'ok' => extension_loaded('pdo_mysql') && extension_loaded('sodium') && extension_loaded('curl'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'sodium' => extension_loaded('sodium'),
        'curl' => extension_loaded('curl'),
    ];

    $root = getenv('MIRZABOT_BACKUP_PATH') ?: dirname(__DIR__) . '/storage/backups';
    $checks['backup_storage'] = [
        'ok' => is_dir($root) ? is_writable($root) : is_writable(dirname($root)),
    ];
    $checks['queue'] = $pdo->query(
        "SELECT status, COUNT(*) AS total FROM saas_job GROUP BY status"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $checks['dispatch'] = $pdo->query(
        "SELECT core_dispatch_status, COUNT(*) AS total FROM saas_tenant GROUP BY core_dispatch_status"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $ok = true;
    foreach ($checks as $check) {
        if (is_array($check) && array_key_exists('ok', $check) && !$check['ok']) {
            $ok = false;
        }
    }
    sendJsonResponse($ok, $ok ? 'healthy' : 'degraded', ['checks' => $checks], $ok ? 200 : 503);
} catch (Throwable $e) {
    error_log('[saas:health] check failed: ' . $e->getMessage());
    sendJsonResponse(false, 'health check failed', [], 503);
}
