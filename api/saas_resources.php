<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../panel/inc/saas_auth.php';

header('Content-Type: application/json; charset=utf-8');

$specs = [
    'users' => [
        'table' => 'user',
        'permission' => 'members.read',
        'columns' => 'id, username, Balance, User_Status, agent, register, lang',
        'idField' => 'id',
    ],
    'panels' => [
        'table' => 'marzban_panel',
        'permission' => 'panels.read',
        'columns' => 'id, code_panel, name_panel, status, url_panel, agent, type, version_panel',
        'idField' => 'id',
    ],
    'products' => [
        'table' => 'product',
        'permission' => 'products.read',
        'columns' => 'id, code_product, name_product, price_product, Location, Service_time, agent, category',
        'idField' => 'id',
    ],
    'invoices' => [
        'table' => 'invoice',
        'permission' => 'orders.read',
        'columns' => 'id_invoice, id_user, username, Service_location, time_sell, name_product, price_product, Status',
        'idField' => 'id_invoice',
    ],
    'payments' => [
        'table' => 'Payment_report',
        'permission' => 'payments.read',
        'columns' => 'id, id_user, id_order, time, price, Payment_Method, payment_Status, id_invoice',
        'idField' => 'id',
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
if (!$auth->can($spec['permission'])) {
    sendJsonResponse(false, 'forbidden', [], 403);
}

try {
    (new \MirzaBot\SaaS\SubscriptionService($pdo))->requireAccess($auth->tenantId());
    $context = new \MirzaBot\SaaS\TenantContext();
    $context->set($auth->tenantId());
    $repository = new \MirzaBot\SaaS\TenantScopedRepository($pdo, $context);

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
    error_log('[saas:resources] scoped read failed: ' . $e->getMessage());
    sendJsonResponse(false, 'resource read failed', [], 500);
}
