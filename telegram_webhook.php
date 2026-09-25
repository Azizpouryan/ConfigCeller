<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/SaaS/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$publicId = is_string($_GET['bot'] ?? null) ? trim((string) $_GET['bot']) : '';
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $publicId)) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

try {
    $statement = $pdo->prepare(
        "SELECT b.id, b.tenant_id, b.token_ciphertext, b.webhook_secret_ciphertext, b.status,
                t.status AS tenant_status, t.legacy_key
         FROM saas_bot b
         INNER JOIN saas_tenant t ON t.id = b.tenant_id
         WHERE b.public_id = ? AND b.deleted_at IS NULL
         LIMIT 1"
    );
    $statement->execute([$publicId]);
    $bot = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($bot) || ($bot['status'] ?? '') !== 'active' || ($bot['tenant_status'] ?? '') !== 'active') {
        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }

    $secretBox = \MirzaBot\SaaS\SecretBox::fromEnvironment();
    $expectedSecret = $secretBox->decrypt((string) $bot['webhook_secret_ciphertext']);
    $receivedSecret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if ($receivedSecret === '' || !hash_equals($expectedSecret, $receivedSecret)) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }

    $subscription = (new \MirzaBot\SaaS\SubscriptionService($pdo))->evaluate((string) $bot['tenant_id']);
    if (!$subscription['allowed']) {
        // Acknowledge the update so Telegram does not retry a suspended tenant.
        echo json_encode(['ok' => true]);
        exit;
    }

    $context = new \MirzaBot\SaaS\TenantContext();
    $context->set((string) $bot['tenant_id'], (int) $bot['id']);
    $context->applyToPdo($pdo);
    $GLOBALS['mirzaSaasTenantContext'] = $context;

    // The existing core is only safe to dispatch for the single legacy tenant
    // at this stage. New tenants remain blocked until their handlers are fully
    // migrated to TenantScopedRepository.
    if (($bot['legacy_key'] ?? null) !== 'legacy') {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'bot core migration pending']);
        exit;
    }

    $GLOBALS['APIKEY'] = $secretBox->decrypt((string) $bot['token_ciphertext']);
    $GLOBALS['mirzaManagedWebhookVerified'] = true;
    $previousDirectory = getcwd();
    chdir(__DIR__);
    require __DIR__ . '/index.php';
    if ($previousDirectory !== false) {
        chdir($previousDirectory);
    }
} catch (Throwable $e) {
    error_log('[saas:webhook] request rejected: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
