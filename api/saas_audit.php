<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../panel/inc/saas_auth.php';

header('Content-Type: application/json; charset=utf-8');
$auth = saas_current_auth();
if ($auth === null) {
    sendJsonResponse(false, 'authentication required', [], 401);
}
if (!$auth->can('audit.read')) {
    sendJsonResponse(false, 'forbidden', [], 403);
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    sendJsonResponse(false, 'method not allowed', [], 405);
}

try {
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? min(max((int) $_GET['limit'], 1), 100) : 50;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? max((int) $_GET['page'], 1) : 1;
    $action = is_scalar($_GET['action'] ?? null) ? trim((string) $_GET['action']) : '';
    if ($action !== '' && !preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $action)) {
        sendJsonResponse(false, 'action invalid', [], 422);
    }

    $where = ['tenant_id = ?'];
    $params = [$auth->tenantId()];
    if ($action !== '') {
        $where[] = 'action = ?';
        $params[] = $action;
    }
    $statement = $pdo->prepare(
        'SELECT id, actor_user_id, bot_id, action, resource_type, resource_id, metadata, ip_address, request_id, created_at
         FROM saas_audit_log WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit)
    );
    $statement->execute($params);
    $items = $statement->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$item) {
        $metadata = json_decode((string) ($item['metadata'] ?? '{}'), true);
        $item['metadata'] = is_array($metadata) ? $metadata : [];
    }
    unset($item);
    $count = $pdo->prepare('SELECT COUNT(*) FROM saas_audit_log WHERE ' . implode(' AND ', $where));
    $count->execute($params);
    sendJsonResponse(true, 'ok', [
        'items' => $items,
        'page' => $page,
        'limit' => $limit,
        'count' => (int) $count->fetchColumn(),
    ]);
} catch (Throwable $e) {
    error_log('[saas:audit] read failed: ' . $e->getMessage());
    sendJsonResponse(false, 'audit read failed', [], 500);
}
