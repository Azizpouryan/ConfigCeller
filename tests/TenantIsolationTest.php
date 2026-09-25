<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/SaaS/bootstrap.php';

use MirzaBot\SaaS\TenantContext;
use MirzaBot\SaaS\TenantScopedRepository;

$dsn = getenv('MIRZABOT_TEST_DSN');
if (!$dsn) {
    fwrite(STDOUT, "SKIP: MIRZABOT_TEST_DSN is not configured.\n");
    exit(0);
}

$pdo = new PDO($dsn, getenv('MIRZABOT_TEST_USER') ?: null, getenv('MIRZABOT_TEST_PASSWORD') ?: null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$uuid = static function (int $variant): string {
    return sprintf('123e4567-e89b-4%03d-a456-426614174000', $variant);
};
$tenantA = $uuid(1);
$tenantB = $uuid(2);
$botA = $uuid(11);
$botB = $uuid(12);
$now = date('Y-m-d H:i:s');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $pdo->beginTransaction();
    $tenant = $pdo->prepare(
        'INSERT INTO saas_tenant (id, tenant_key, name, status, metadata, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $tenant->execute([$tenantA, 'test-a-' . bin2hex(random_bytes(3)), 'Test A', 'active', '{}', $now, $now]);
    $tenant->execute([$tenantB, 'test-b-' . bin2hex(random_bytes(3)), 'Test B', 'active', '{}', $now, $now]);

    $bot = $pdo->prepare(
        'INSERT INTO saas_bot (public_id, tenant_id, username, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $bot->execute([$botA, $tenantA, 'test_a_bot', 'active', $now, $now]);
    $bot->execute([$botB, $tenantB, 'test_b_bot', 'active', $now, $now]);

    // Test A: a Tenant A context sees only Tenant A rows.
    $contextA = new TenantContext();
    $contextA->set($tenantA);
    $repoA = new TenantScopedRepository($pdo, $contextA);
    $rowsA = $repoA->fetchAll('saas_bot', [], 'public_id, username', 100, 0, 'id', 'ASC');
    $assert(count($rowsA) === 1 && $rowsA[0]['public_id'] === $botA, 'Tenant A received another tenant row.');

    // Test B: an object id from Tenant B cannot be updated by Tenant A.
    $updated = $repoA->update('saas_bot', ['status' => 'paused'], ['public_id' => $botB]);
    $assert($updated === 0, 'Cross-tenant update was not rejected by scope.');
    $assert($repoA->count('saas_bot', ['public_id' => $botB]) === 0, 'Cross-tenant count leaked a row.');

    // Test C: switching to Tenant B changes the result set, not the request id.
    $contextB = new TenantContext();
    $contextB->set($tenantB);
    $repoB = new TenantScopedRepository($pdo, $contextB);
    $rowsB = $repoB->fetchAll('saas_bot', [], 'public_id, username', 100, 0, 'id', 'ASC');
    $assert(count($rowsB) === 1 && $rowsB[0]['public_id'] === $botB, 'Tenant B isolation failed.');

    $pdo->rollBack();
    fwrite(STDOUT, "Tenant isolation A/B/C tests passed.\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
}
