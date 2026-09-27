<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../panel/inc/saas_auth.php';

header('Content-Type: application/json; charset=utf-8');
$auth = saas_current_auth();
if ($auth === null || !$auth->isMasterAdmin()) {
    sendJsonResponse(false, 'master admin required', [], 403);
}
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'POST' && !csrf_check_value((string) headerValue(getallheaders(), 'X-CSRF-Token'))) {
    sendJsonResponse(false, 'csrf invalid', [], 403);
}

$validateMap = static function (mixed $value, string $name): array {
    if (!is_array($value) || count($value) > 100) {
        throw new InvalidArgumentException($name . ' invalid.');
    }
    $result = [];
    foreach ($value as $key => $item) {
        if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $key)) {
            throw new InvalidArgumentException($name . ' key invalid.');
        }
        if (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
            $result[$key] = $item;
        } else {
            throw new InvalidArgumentException($name . ' values invalid.');
        }
    }
    return $result;
};

try {
    if ($method === 'GET') {
        $items = $pdo->query('SELECT id, code, name, status, limits, features, created_at, updated_at FROM saas_plan ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            $item['limits'] = json_decode((string) $item['limits'], true) ?: [];
            $item['features'] = json_decode((string) $item['features'], true) ?: [];
        }
        unset($item);
        sendJsonResponse(true, 'ok', ['items' => $items, 'csrf' => csrf_token()]);
    }
    if ($method !== 'POST') {
        sendJsonResponse(false, 'method not allowed', [], 405);
    }
    $raw = file_get_contents('php://input');
    $data = $raw === false ? [] : json_decode($raw, true);
    if (!is_array($data)) {
        sendJsonResponse(false, 'data invalid', [], 422);
    }
    $action = (string) ($data['action'] ?? '');
    $now = date('Y-m-d H:i:s');
    if ($action === 'create') {
        $code = strtolower(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,99}$/', $code) || $name === '' || strlen($name) > 200) {
            sendJsonResponse(false, 'plan data invalid', [], 422);
        }
        $limits = $validateMap($data['limits'] ?? [], 'limits');
        $features = $validateMap($data['features'] ?? [], 'features');
        $statement = $pdo->prepare(
            'INSERT INTO saas_plan (code, name, status, limits, features, created_at, updated_at) VALUES (?, ?, \'active\', ?, ?, ?, ?)'
        );
        $statement->execute([$code, $name, json_encode($limits, JSON_THROW_ON_ERROR), json_encode($features, JSON_THROW_ON_ERROR), $now, $now]);
        (new \MirzaBot\SaaS\AuditLogger($pdo))->record(null, $auth->userId(), 'plan.created', 'saas_plan', (string) $pdo->lastInsertId(), ['code' => $code]);
        sendJsonResponse(true, 'plan created', ['code' => $code], 201);
    }
    if ($action === 'status') {
        $code = strtolower(trim((string) ($data['code'] ?? '')));
        $status = (string) ($data['status'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_-]{1,99}$/', $code) || !in_array($status, ['active', 'archived'], true)) {
            sendJsonResponse(false, 'plan status invalid', [], 422);
        }
        $statement = $pdo->prepare('UPDATE saas_plan SET status = ?, updated_at = ? WHERE code = ?');
        $statement->execute([$status, $now, $code]);
        if ($statement->rowCount() !== 1) {
            sendJsonResponse(false, 'plan not found', [], 404);
        }
        (new \MirzaBot\SaaS\AuditLogger($pdo))->record(null, $auth->userId(), 'plan.status_changed', 'saas_plan', $code, ['status' => $status]);
        sendJsonResponse(true, 'plan updated');
    }
    sendJsonResponse(false, 'action invalid', [], 422);
} catch (InvalidArgumentException $e) {
    sendJsonResponse(false, $e->getMessage(), [], 422);
} catch (RuntimeException $e) {
    sendJsonResponse(false, $e->getMessage(), [], 409);
} catch (Throwable $e) {
    error_log('[saas:plans] operation failed: ' . $e->getMessage());
    sendJsonResponse(false, 'plan operation failed', [], 500);
}
