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

$uuid = static function (): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
};

$tenants = [];
$now = date('Y-m-d H:i:s');
try {
    $pdo->beginTransaction();
    $tenant = $pdo->prepare(
        'INSERT INTO saas_tenant (id, tenant_key, name, status, metadata, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $bot = $pdo->prepare(
        'INSERT INTO saas_bot (public_id, tenant_id, username, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    for ($i = 0; $i < 500; $i++) {
        $tenantId = $uuid();
        $tenants[] = $tenantId;
        $tenant->execute([$tenantId, 'load-' . $i . '-' . bin2hex(random_bytes(2)), 'Load ' . $i, 'active', '{}', $now, $now]);
        $bot->execute([$uuid(), $tenantId, 'load_bot_' . $i, 'active', $now, $now]);
    }

    foreach ([10, 50, 100, 500] as $size) {
        $started = hrtime(true);
        for ($i = 0; $i < $size; $i++) {
            $context = new TenantContext();
            $context->set($tenants[$i]);
            $repository = new TenantScopedRepository($pdo, $context);
            $rows = $repository->fetchAll('saas_bot', [], 'public_id, tenant_id', 10, 0, 'id', 'ASC');
            if (count($rows) !== 1 || $rows[0]['tenant_id'] !== $tenants[$i]) {
                throw new RuntimeException('Tenant load isolation failed at cohort ' . $size . '.');
            }
        }
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        fwrite(STDOUT, sprintf("cohort=%d tenants elapsed_ms=%.2f\n", $size, $elapsedMs));
    }
    $pdo->rollBack();
    fwrite(STDOUT, "Tenant load isolation test passed.\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
}
