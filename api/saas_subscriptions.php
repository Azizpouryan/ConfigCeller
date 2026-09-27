<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../panel/inc/saas_auth.php';

header('Content-Type: application/json; charset=utf-8');
$auth = saas_current_auth();
if ($auth === null || !$auth->isMasterAdmin()) {
    sendJsonResponse(false, 'master admin required', [], 403);
}

$uuid = static function (): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
};

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'POST' && !csrf_check_value((string) headerValue(getallheaders(), 'X-CSRF-Token'))) {
    sendJsonResponse(false, 'csrf invalid', [], 403);
}

try {
    if ($method === 'GET') {
        $tenantId = (string) ($_GET['tenant_id'] ?? '');
        if ($tenantId !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tenantId)) {
            sendJsonResponse(false, 'tenant id invalid', [], 422);
        }
        $sql = "SELECT s.public_id, s.tenant_id, t.tenant_key, p.code AS plan_code, p.name AS plan_name,
                       s.status, s.starts_at, s.current_period_end, s.grace_until, s.suspended_at, s.created_at
                FROM saas_subscription s
                INNER JOIN saas_tenant t ON t.id = s.tenant_id
                INNER JOIN saas_plan p ON p.id = s.plan_id";
        $params = [];
        if ($tenantId !== '') {
            $sql .= ' WHERE s.tenant_id = ?';
            $params[] = $tenantId;
        }
        $sql .= ' ORDER BY s.id DESC';
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        sendJsonResponse(true, 'ok', ['items' => $statement->fetchAll(PDO::FETCH_ASSOC), 'csrf' => csrf_token()]);
    }
    if ($method !== 'POST') {
        sendJsonResponse(false, 'method not allowed', [], 405);
    }

    $raw = file_get_contents('php://input');
    $data = $raw === false ? [] : json_decode($raw, true);
    if (!is_array($data)) {
        sendJsonResponse(false, 'data invalid', [], 422);
    }
    $tenantId = (string) ($data['tenant_id'] ?? '');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tenantId)) {
        sendJsonResponse(false, 'tenant id invalid', [], 422);
    }
    $tenant = $pdo->prepare("SELECT id FROM saas_tenant WHERE id = ? AND legacy_key IS NULL LIMIT 1");
    $tenant->execute([$tenantId]);
    if ($tenant->fetchColumn() === false) {
        sendJsonResponse(false, 'tenant not found', [], 404);
    }

    $action = (string) ($data['action'] ?? '');
    $now = date('Y-m-d H:i:s');
    $audit = new \MirzaBot\SaaS\AuditLogger($pdo);
    if ($action === 'grant') {
        $planCode = strtolower(trim((string) ($data['plan'] ?? 'legacy')));
        $days = isset($data['days']) && is_numeric($data['days']) ? (int) $data['days'] : 14;
        $graceDays = isset($data['grace_days']) && is_numeric($data['grace_days']) ? (int) $data['grace_days'] : 7;
        if (!preg_match('/^[a-z][a-z0-9_-]{1,99}$/', $planCode) || $days < 1 || $days > 3650 || $graceDays < 0 || $graceDays > 365) {
            sendJsonResponse(false, 'subscription data invalid', [], 422);
        }
        $plan = $pdo->prepare("SELECT id FROM saas_plan WHERE code = ? AND status = 'active' LIMIT 1");
        $plan->execute([$planCode]);
        $planId = $plan->fetchColumn();
        if ($planId === false) {
            sendJsonResponse(false, 'plan not found', [], 404);
        }
        $periodEnd = date('Y-m-d H:i:s', time() + $days * 86400);
        $graceUntil = date('Y-m-d H:i:s', time() + ($days + $graceDays) * 86400);
        $insert = $pdo->prepare(
            'INSERT INTO saas_subscription (public_id, tenant_id, plan_id, status, starts_at, current_period_end, grace_until, created_at, updated_at)
             VALUES (?, ?, ?, \'active\', ?, ?, ?, ?, ?)'
        );
        $insert->execute([$uuid(), $tenantId, (int) $planId, $now, $periodEnd, $graceUntil, $now, $now]);
        $pdo->prepare("UPDATE saas_tenant SET status = 'active', suspended_at = NULL, updated_at = ? WHERE id = ?")->execute([$now, $tenantId]);
        $audit->record($tenantId, $auth->userId(), 'subscription.granted', 'saas_subscription', (string) $pdo->lastInsertId(), ['plan' => $planCode, 'days' => $days, 'grace_days' => $graceDays]);
        sendJsonResponse(true, 'subscription granted', ['current_period_end' => $periodEnd, 'grace_until' => $graceUntil]);
    }
    if ($action === 'suspend' || $action === 'activate') {
        $latest = $pdo->prepare('SELECT id, current_period_end FROM saas_subscription WHERE tenant_id = ? ORDER BY id DESC LIMIT 1');
        $latest->execute([$tenantId]);
        $subscription = $latest->fetch(PDO::FETCH_ASSOC);
        if (!is_array($subscription)) {
            sendJsonResponse(false, 'subscription not found', [], 404);
        }
        if ($action === 'suspend') {
            $statement = $pdo->prepare("UPDATE saas_subscription SET status = 'suspended', suspended_at = COALESCE(suspended_at, ?), updated_at = ? WHERE id = ?");
            $statement->execute([$now, $now, (int) $subscription['id']]);
        } else {
            if ((strtotime((string) $subscription['current_period_end']) ?: 0) < time()) {
                sendJsonResponse(false, 'subscription period has expired', [], 409);
            }
            $statement = $pdo->prepare("UPDATE saas_subscription SET status = 'active', suspended_at = NULL, updated_at = ? WHERE id = ?");
            $statement->execute([$now, (int) $subscription['id']]);
        }
        $audit->record($tenantId, $auth->userId(), 'subscription.' . $action . 'd', 'saas_subscription', (string) $subscription['id']);
        sendJsonResponse(true, 'subscription updated');
    }
    sendJsonResponse(false, 'action invalid', [], 422);
} catch (InvalidArgumentException $e) {
    sendJsonResponse(false, $e->getMessage(), [], 422);
} catch (RuntimeException $e) {
    sendJsonResponse(false, $e->getMessage(), [], 409);
} catch (Throwable $e) {
    error_log('[saas:subscriptions] operation failed: ' . $e->getMessage());
    sendJsonResponse(false, 'subscription operation failed', [], 500);
}
