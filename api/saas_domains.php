<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../panel/inc/saas_auth.php';

header('Content-Type: application/json; charset=utf-8');
$auth = saas_current_auth();
if ($auth === null) {
    sendJsonResponse(false, 'authentication required', [], 401);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'GET') {
    if (!$auth->can('tenant.read')) {
        sendJsonResponse(false, 'forbidden', [], 403);
    }
} elseif ($method === 'POST' || $method === 'DELETE') {
    if (!$auth->can('domains.manage')) {
        sendJsonResponse(false, 'forbidden', [], 403);
    }
    if (!csrf_check_value((string) headerValue(getallheaders(), 'X-CSRF-Token'))) {
        sendJsonResponse(false, 'csrf invalid', [], 403);
    }
} else {
    sendJsonResponse(false, 'method not allowed', [], 405);
}

try {
    (new \MirzaBot\SaaS\SubscriptionService($pdo))->requireAccess($auth->tenantId());
    $resolver = new \MirzaBot\SaaS\DomainResolver($pdo);
    $audit = new \MirzaBot\SaaS\AuditLogger($pdo);
    $tenantId = $auth->tenantId();

    if ($method === 'GET') {
        sendJsonResponse(true, 'ok', ['csrf' => csrf_token(), 'items' => $resolver->list($tenantId)]);
    }

    $raw = file_get_contents('php://input');
    $data = $raw === false || trim($raw) === '' ? [] : json_decode($raw, true);
    if (!is_array($data)) {
        sendJsonResponse(false, 'data invalid', [], 422);
    }
    $action = (string) ($data['action'] ?? '');
    $hostname = (string) ($data['hostname'] ?? ($_GET['hostname'] ?? ''));

    if ($method === 'DELETE' || $action === 'remove') {
        $resolver->remove($tenantId, $hostname);
        $audit->record($tenantId, $auth->userId(), 'domain.removed', 'saas_domain', $hostname);
        sendJsonResponse(true, 'domain removed');
    }
    if ($action === 'add') {
        $created = $resolver->createPending($tenantId, $hostname);
        $audit->record($tenantId, $auth->userId(), 'domain.added', 'saas_domain', $created['hostname']);
        sendJsonResponse(true, 'domain pending verification', ['domain' => $created], 201);
    }
    if ($action === 'verify') {
        $resolver->verify($tenantId, $hostname, (string) ($data['verification_token'] ?? ''));
        $audit->record($tenantId, $auth->userId(), 'domain.verified', 'saas_domain', $hostname);
        sendJsonResponse(true, 'domain verified');
    }
    sendJsonResponse(false, 'action invalid', [], 422);
} catch (InvalidArgumentException $e) {
    sendJsonResponse(false, $e->getMessage(), [], 422);
} catch (RuntimeException $e) {
    sendJsonResponse(false, $e->getMessage(), [], 409);
} catch (Throwable $e) {
    error_log('[saas:domains] operation failed: ' . $e->getMessage());
    sendJsonResponse(false, 'domain operation failed', [], 500);
}
