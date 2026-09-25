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
if ($method === 'POST') {
    if (!csrf_check_value((string) headerValue(getallheaders(), 'X-CSRF-Token'))) {
        sendJsonResponse(false, 'csrf invalid', [], 403);
    }
}

$uuid = static function (): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
};

try {
    if ($method === 'GET') {
        $rows = $pdo->query(
            "SELECT t.id, t.tenant_key, t.name, t.status, t.legacy_key, t.created_at, t.updated_at,
                    s.status AS subscription_status, s.current_period_end, s.grace_until
             FROM saas_tenant t
             LEFT JOIN saas_subscription s ON s.id = (
                 SELECT MAX(s2.id) FROM saas_subscription s2 WHERE s2.tenant_id = t.id
             )
             ORDER BY t.created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        sendJsonResponse(true, 'ok', ['items' => $rows, 'csrf' => csrf_token()]);
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
    $audit = new \MirzaBot\SaaS\AuditLogger($pdo);
    if ($action === 'create') {
        $tenantKey = strtolower(trim((string) ($data['tenant_key'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $ownerUserId = (int) ($data['owner_user_id'] ?? 0);
        if (!preg_match('/^[a-z][a-z0-9-]{2,99}$/', $tenantKey) || $name === '' || strlen($name) > 200 || $ownerUserId < 1) {
            sendJsonResponse(false, 'tenant data invalid', [], 422);
        }

        $pdo->beginTransaction();
        try {
            $tenantId = $uuid();
            $now = date('Y-m-d H:i:s');
            $tenant = $pdo->prepare(
                'INSERT INTO saas_tenant (id, tenant_key, name, status, metadata, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $tenant->execute([$tenantId, $tenantKey, $name, 'active', '{}', $now, $now]);

            $member = $pdo->prepare(
                'INSERT INTO saas_membership (tenant_id, user_id, role, permissions, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $member->execute([$tenantId, $ownerUserId, 'TENANT_OWNER', '{}', 'active', $now, $now]);

            $planId = $pdo->query("SELECT id FROM saas_plan WHERE code = 'legacy' LIMIT 1")->fetchColumn();
            if ($planId === false) {
                throw new RuntimeException('Default SaaS plan is missing.');
            }
            $subscription = $pdo->prepare(
                'INSERT INTO saas_subscription (public_id, tenant_id, plan_id, status, starts_at, current_period_end, grace_until, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $periodEnd = date('Y-m-d H:i:s', time() + 14 * 86400);
            $graceEnd = date('Y-m-d H:i:s', time() + 21 * 86400);
            $subscription->execute([$uuid(), $tenantId, (int) $planId, 'trial', $now, $periodEnd, $graceEnd, $now, $now]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $audit->record($tenantId, $auth->userId(), 'tenant.created', 'saas_tenant', $tenantId, ['tenant_key' => $tenantKey]);
        sendJsonResponse(true, 'tenant created', ['tenant_id' => $tenantId, 'tenant_key' => $tenantKey], 201);
    }

    $tenantId = (string) ($data['tenant_id'] ?? '');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tenantId)) {
        sendJsonResponse(false, 'tenant id invalid', [], 422);
    }
    if ($action === 'suspend' || $action === 'activate') {
        $status = $action === 'suspend' ? 'suspended' : 'active';
        $statement = $pdo->prepare('UPDATE saas_tenant SET status = ?, suspended_at = ?, updated_at = ? WHERE id = ? AND legacy_key IS NULL');
        $statement->execute([$status, $status === 'suspended' ? date('Y-m-d H:i:s') : null, date('Y-m-d H:i:s'), $tenantId]);
        if ($action === 'suspend') {
            $pdo->prepare("UPDATE saas_subscription SET status = 'suspended', suspended_at = COALESCE(suspended_at, ?), updated_at = ? WHERE tenant_id = ?")->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $tenantId]);
        }
        $audit->record($tenantId, $auth->userId(), 'tenant.' . $action . 'd', 'saas_tenant', $tenantId);
        sendJsonResponse(true, 'tenant updated');
    }
    if ($action === 'add_member') {
        $userId = (int) ($data['user_id'] ?? 0);
        $role = (string) ($data['role'] ?? 'TENANT_STAFF');
        if ($userId < 1 || !\MirzaBot\SaaS\Role::isKnown($role) || $role === 'MASTER_ADMIN') {
            sendJsonResponse(false, 'membership data invalid', [], 422);
        }
        $statement = $pdo->prepare(
            'INSERT INTO saas_membership (tenant_id, user_id, role, permissions, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role), status = \'active\', updated_at = VALUES(updated_at)'
        );
        $now = date('Y-m-d H:i:s');
        $statement->execute([$tenantId, $userId, $role, '{}', 'active', $now, $now]);
        $audit->record($tenantId, $auth->userId(), 'tenant.member_added', 'saas_tenant', $tenantId, ['user_id' => $userId, 'role' => $role]);
        sendJsonResponse(true, 'member added');
    }

    sendJsonResponse(false, 'action invalid', [], 422);
} catch (InvalidArgumentException $e) {
    sendJsonResponse(false, $e->getMessage(), [], 422);
} catch (RuntimeException $e) {
    sendJsonResponse(false, $e->getMessage(), [], 409);
} catch (Throwable $e) {
    error_log('[saas:tenants] operation failed: ' . $e->getMessage());
    sendJsonResponse(false, 'tenant operation failed', [], 500);
}
