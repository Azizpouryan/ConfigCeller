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
    if (!$auth->can('bots.read')) {
        sendJsonResponse(false, 'forbidden', [], 403);
    }
} elseif ($method === 'POST') {
    if (!$auth->can('bots.manage')) {
        sendJsonResponse(false, 'forbidden', [], 403);
    }
    $headers = getallheaders();
    $csrf = headerValue($headers, 'X-CSRF-Token');
    if (!csrf_check_value((string) $csrf)) {
        sendJsonResponse(false, 'csrf invalid', [], 403);
    }
} else {
    sendJsonResponse(false, 'method not allowed', [], 405);
}

$context = new \MirzaBot\SaaS\TenantContext();
$context->set($auth->tenantId());

try {
    $manager = new \MirzaBot\SaaS\BotManager(
        $pdo,
        $context,
        \MirzaBot\SaaS\SecretBox::fromEnvironment(),
    );

    if ($method === 'GET') {
        sendJsonResponse(true, 'ok', [
            'csrf' => csrf_token(),
            'items' => $manager->list(),
        ]);
    }

    $raw = file_get_contents('php://input');
    $data = $raw === false ? [] : json_decode($raw, true);
    if (!is_array($data)) {
        sendJsonResponse(false, 'data invalid', [], 422);
    }

    $action = (string) ($data['action'] ?? '');
    switch ($action) {
        case 'create':
            $item = $manager->create((string) ($data['token'] ?? ''), (string) ($data['username'] ?? ''));
            sendJsonResponse(true, 'bot created', ['item' => $item], 201);
        case 'delete':
            $manager->softDelete((string) ($data['public_id'] ?? ''));
            sendJsonResponse(true, 'bot scheduled for deletion', []);
        case 'rotate':
            $manager->rotateToken((string) ($data['public_id'] ?? ''), (string) ($data['token'] ?? ''));
            sendJsonResponse(true, 'bot credential rotated', []);
        default:
            sendJsonResponse(false, 'action invalid', [], 422);
    }
} catch (InvalidArgumentException $e) {
    sendJsonResponse(false, $e->getMessage(), [], 422);
} catch (RuntimeException $e) {
    sendJsonResponse(false, $e->getMessage(), [], 409);
} catch (Throwable $e) {
    error_log('[saas:bots] operation failed: ' . $e->getMessage());
    sendJsonResponse(false, 'bot operation failed', [], 500);
}
