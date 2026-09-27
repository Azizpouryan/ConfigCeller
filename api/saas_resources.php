<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../panel/inc/saas_auth.php';

header('Content-Type: application/json; charset=utf-8');

$specs = [
    'users' => [
        'table' => 'user',
        'permission' => 'members.read',
        'managePermission' => 'members.manage',
        'columns' => 'id, username, Balance, User_Status, agent, register, lang',
        'idField' => 'id',
        'writable' => ['username', 'User_Status', 'agent', 'lang', 'verify', 'joinchannel'],
    ],
    'panels' => [
        'table' => 'marzban_panel',
        'permission' => 'panels.read',
        'managePermission' => 'panels.manage',
        'columns' => 'id, code_panel, name_panel, status, url_panel, agent, type, version_panel',
        'idField' => 'id',
        'writable' => ['name_panel', 'status', 'url_panel', 'agent', 'type', 'version_panel'],
    ],
    'products' => [
        'table' => 'product',
        'permission' => 'products.read',
        'managePermission' => 'products.manage',
        'columns' => 'id, code_product, name_product, price_product, Location, Service_time, agent, category',
        'idField' => 'id',
        'writable' => ['name_product', 'price_product', 'Volume_constraint', 'Location', 'Service_time', 'agent', 'note', 'data_limit_reset', 'one_buy_status', 'category', 'hide_panel'],
    ],
    'invoices' => [
        'table' => 'invoice',
        'permission' => 'orders.read',
        'managePermission' => 'orders.manage',
        'columns' => 'id_invoice, id_user, username, Service_location, time_sell, name_product, price_product, Status',
        'idField' => 'id_invoice',
        'writable' => ['Status', 'note', 'Service_location'],
    ],
    'payments' => [
        'table' => 'Payment_report',
        'permission' => 'payments.read',
        'managePermission' => 'payments.manage',
        'columns' => 'id, id_user, id_order, time, price, Payment_Method, payment_Status, id_invoice',
        'idField' => 'id',
        'writable' => ['payment_Status', 'Payment_Method', 'dec_not_confirmed'],
    ],
];

$auth = saas_current_auth();
if ($auth === null) {
    sendJsonResponse(false, 'authentication required', [], 401);
}

$resource = is_string($_GET['resource'] ?? null) ? (string) $_GET['resource'] : '';
if (!isset($specs[$resource])) {
    sendJsonResponse(false, 'resource invalid', [], 422);
}
$spec = $specs[$resource];
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$mutating = in_array($method, ['POST', 'PUT', 'PATCH'], true);
if (!$mutating && $method !== 'GET') {
    sendJsonResponse(false, 'method not allowed', [], 405);
}
if (!$auth->can($mutating ? $spec['managePermission'] : $spec['permission'])) {
    sendJsonResponse(false, 'forbidden', [], 403);
}
if ($mutating && !csrf_check_value((string) headerValue(getallheaders(), 'X-CSRF-Token'))) {
    sendJsonResponse(false, 'csrf invalid', [], 403);
}

try {
    $subscription = new \MirzaBot\SaaS\SubscriptionService($pdo);
    $subscription->requireAccess($auth->tenantId());
    $context = new \MirzaBot\SaaS\TenantContext();
    $context->set($auth->tenantId());
    $repository = new \MirzaBot\SaaS\TenantScopedRepository($pdo, $context);

    if ($mutating) {
        $raw = file_get_contents('php://input');
        $data = $raw === false ? [] : json_decode($raw, true);
        if (!is_array($data) || (string) ($data['action'] ?? 'update') !== 'update') {
            sendJsonResponse(false, 'update data invalid', [], 422);
        }
        $id = $data['id'] ?? null;
        if (!is_scalar($id) || (string) $id === '') {
            sendJsonResponse(false, 'resource id required', [], 422);
        }
        $values = $data['values'] ?? [];
        if (!is_array($values) || $values === []) {
            sendJsonResponse(false, 'resource values required', [], 422);
        }
        $unknown = array_diff(array_keys($values), $spec['writable']);
        if ($unknown !== []) {
            sendJsonResponse(false, 'field is not writable', ['fields' => array_values($unknown)], 422);
        }
        $identifier = (string) $id;
        $existing = $repository->fetchAll($spec['table'], [$spec['idField'] => $identifier], $spec['idField'], 1, 0, $spec['idField'], 'ASC');
        if ($existing === []) {
            sendJsonResponse(false, 'resource not found in current tenant', [], 404);
        }
        $changed = $repository->update($spec['table'], $values, [$spec['idField'] => $identifier]);
        (new \MirzaBot\SaaS\AuditLogger($pdo))->record(
            $auth->tenantId(),
            $auth->userId(),
            'resource.updated',
            $spec['table'],
            $identifier,
            ['resource' => $resource, 'fields' => array_keys($values)]
        );
        sendJsonResponse(true, 'resource updated', ['changed' => $changed]);
    }

    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? min(max((int) $_GET['limit'], 1), 100) : 50;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? max((int) $_GET['page'], 1) : 1;
    $filters = [];
    if (isset($_GET['id']) && is_scalar($_GET['id']) && (string) $_GET['id'] !== '') {
        $filters[$spec['idField']] = (string) $_GET['id'];
    }

    $items = $repository->fetchAll($spec['table'], $filters, $spec['columns'], $limit, ($page - 1) * $limit, $spec['idField'], 'DESC');
    sendJsonResponse(true, 'ok', [
        'resource' => $resource,
        'page' => $page,
        'limit' => $limit,
        'items' => $items,
        'count' => $repository->count($spec['table'], $filters),
    ]);
} catch (RuntimeException $e) {
    sendJsonResponse(false, $e->getMessage(), [], 403);
} catch (Throwable $e) {
    error_log('[saas:resources] scoped operation failed: ' . $e->getMessage());
    sendJsonResponse(false, 'resource operation failed', [], 500);
}
