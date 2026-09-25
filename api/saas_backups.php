<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../panel/inc/saas_auth.php';

header('Content-Type: application/json; charset=utf-8');
$auth = saas_current_auth();
if ($auth === null) {
    sendJsonResponse(false, 'authentication required', [], 401);
}
if (!$auth->can('backups.manage')) {
    sendJsonResponse(false, 'forbidden', [], 403);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'POST') {
    $headers = getallheaders();
    if (!csrf_check_value((string) headerValue($headers, 'X-CSRF-Token'))) {
        sendJsonResponse(false, 'csrf invalid', [], 403);
    }
}

try {
    (new \MirzaBot\SaaS\SubscriptionService($pdo))->requireAccess($auth->tenantId());
    $service = new \MirzaBot\SaaS\TenantBackupService(
        $pdo,
        \MirzaBot\SaaS\SecretBox::fromEnvironment(),
    );
    $audit = new \MirzaBot\SaaS\AuditLogger($pdo);

    if ($method === 'GET') {
        $action = (string) ($_GET['action'] ?? 'preview');
        if ($action !== 'preview') {
            sendJsonResponse(false, 'action invalid', [], 422);
        }
        sendJsonResponse(true, 'ok', [
            'manifest' => $service->preview(
                $auth->tenantId(),
                (string) ($_GET['backup_id'] ?? ''),
            ),
        ]);
    }

    if ($method !== 'POST') {
        sendJsonResponse(false, 'method not allowed', [], 405);
    }
    $raw = file_get_contents('php://input');
    $data = $raw === false ? [] : json_decode($raw, true);
    if (!is_array($data)) {
        sendJsonResponse(false, 'data invalid', [], 422);
    }

    switch ((string) ($data['action'] ?? '')) {
        case 'create':
            $backup = $service->create(
                $auth->tenantId(),
                (string) ($data['mode'] ?? 'full'),
                $auth->userId(),
            );
            $audit->record($auth->tenantId(), $auth->userId(), 'backup.created', 'saas_backup', $backup['public_id'], ['mode' => $backup['mode']]);
            sendJsonResponse(true, 'backup created', ['backup' => $backup], 201);
        case 'restore':
            $audit->record($auth->tenantId(), $auth->userId(), 'backup.restore_requested', 'saas_backup', (string) ($data['backup_id'] ?? ''), ['mode' => (string) ($data['mode'] ?? '')]);
            $service->restore(
                $auth->tenantId(),
                (string) ($data['backup_id'] ?? ''),
                (string) ($data['mode'] ?? ''),
            );
            sendJsonResponse(true, 'restore completed');
        default:
            sendJsonResponse(false, 'action invalid', [], 422);
    }
} catch (InvalidArgumentException $e) {
    sendJsonResponse(false, $e->getMessage(), [], 422);
} catch (RuntimeException $e) {
    sendJsonResponse(false, $e->getMessage(), [], 409);
} catch (Throwable $e) {
    error_log('[saas:backup] operation failed: ' . $e->getMessage());
    sendJsonResponse(false, 'backup operation failed', [], 500);
}
