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
        $action = (string) ($_GET['action'] ?? 'tenants');
        if ($action === 'users') {
            $users = $pdo->query("SELECT id, public_id, username, email, is_master_admin, status, last_login_at, created_at FROM saas_user ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
            sendJsonResponse(true, 'ok', ['items' => $users, 'csrf' => csrf_token()]);
        }
        if ($action !== 'tenants') {
            sendJsonResponse(false, 'action invalid', [], 422);
        }
        $rows = $pdo->query(
            "SELECT t.id, t.tenant_key, t.name, t.status, t.core_dispatch_status, t.legacy_key, t.created_at, t.updated_at,
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
    if ($action === 'create_user') {
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $email = trim((string) ($data['email'] ?? ''));
        if ($username === '' || strlen($username) > 200 || !preg_match('/^[A-Za-z0-9._@+-]{3,200}$/', $username) || strlen($password) < 12 || strlen($password) > 4096) {
            sendJsonResponse(false, 'user data invalid', [], 422);
        }
        if ($email !== '' && (strlen($email) > 320 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            sendJsonResponse(false, 'email invalid', [], 422);
        }
        $exists = $pdo->prepare('SELECT id FROM saas_user WHERE username = ? LIMIT 1');
        $exists->execute([$username]);
        if ($exists->fetchColumn() !== false) {
            sendJsonResponse(false, 'username already exists', [], 409);
        }
        $publicId = $uuid();
        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
        $insert = $pdo->prepare(
            'INSERT INTO saas_user (public_id, username, email, email_hash, password_hash, is_master_admin, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, \'active\', ?, ?)'
        );
        $insert->execute([
            $publicId,
            $username,
            $email === '' ? null : $email,
            $email === '' ? null : hash('sha256', strtolower($email)),
            password_hash($password, PASSWORD_DEFAULT),
            !empty($data['is_master_admin']) ? 1 : 0,
            $now,
            $now,
        ]);
        $userId = (int) $pdo->lastInsertId();
        if (!empty($data['is_master_admin'])) {
            $legacyTenant = $pdo->query("SELECT id FROM saas_tenant WHERE legacy_key = 'legacy' LIMIT 1")->fetchColumn();
            if ($legacyTenant === false) {
                throw new RuntimeException('Legacy Tenant is missing.');
            }
            $membership = $pdo->prepare(
                'INSERT INTO saas_membership (tenant_id, user_id, role, permissions, status, created_at, updated_at) VALUES (?, ?, ?, ?, \'active\', ?, ?)'
            );
            $membership->execute([$legacyTenant, $userId, 'MASTER_ADMIN', '{}', $now, $now]);
        }
        $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $audit->record(null, $auth->userId(), 'user.created', 'saas_user', (string) $userId, ['username' => $username]);
        sendJsonResponse(true, 'user created', ['user_id' => $userId, 'public_id' => $publicId, 'username' => $username], 201);
    }
    if ($action === 'create') {
        $tenantKey = strtolower(trim((string) ($data['tenant_key'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $ownerUserId = (int) ($data['owner_user_id'] ?? 0);
        if (!preg_match('/^[a-z][a-z0-9-]{2,99}$/', $tenantKey) || $name === '' || strlen($name) > 200 || $ownerUserId < 1) {
            sendJsonResponse(false, 'tenant data invalid', [], 422);
        }

        $pdo->beginTransaction();
        try {
            $legacyTenant = $pdo->query("SELECT id FROM saas_tenant WHERE legacy_key = 'legacy' LIMIT 1")->fetchColumn();
            if ($legacyTenant === false) {
                throw new RuntimeException('Legacy Tenant is missing.');
            }
            $tenantId = $uuid();
            $now = date('Y-m-d H:i:s');
            $tenant = $pdo->prepare(
                'INSERT INTO saas_tenant (id, tenant_key, name, status, core_dispatch_status, metadata, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $tenant->execute([$tenantId, $tenantKey, $name, 'active', 'pending', '{}', $now, $now]);

            $configCounts = (new \MirzaBot\SaaS\TenantProvisioner($pdo))->cloneLegacyConfiguration((string) $legacyTenant, $tenantId);
            $pdo->prepare("UPDATE saas_tenant SET core_dispatch_status = 'ready', updated_at = ? WHERE id = ?")->execute([$now, $tenantId]);

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

        $audit->record($tenantId, $auth->userId(), 'tenant.created', 'saas_tenant', $tenantId, ['tenant_key' => $tenantKey, 'configuration_rows' => $configCounts ?? []]);
        sendJsonResponse(true, 'tenant created', ['tenant_id' => $tenantId, 'tenant_key' => $tenantKey], 201);
    }

    $tenantId = (string) ($data['tenant_id'] ?? '');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tenantId)) {
        sendJsonResponse(false, 'tenant id invalid', [], 422);
    }
    if ($action === 'suspend' || $action === 'activate') {
        $status = $action === 'suspend' ? 'suspended' : 'active';
        $now = date('Y-m-d H:i:s');
        $statement = $pdo->prepare('UPDATE saas_tenant SET status = ?, suspended_at = ?, updated_at = ? WHERE id = ? AND legacy_key IS NULL');
        $statement->execute([$status, $status === 'suspended' ? $now : null, $now, $tenantId]);
        if ($statement->rowCount() !== 1) {
            sendJsonResponse(false, 'tenant not found or cannot be changed', [], 409);
        }
        if ($action === 'suspend') {
            $pdo->prepare("UPDATE saas_subscription SET status = 'suspended', suspended_at = COALESCE(suspended_at, ?), updated_at = ? WHERE tenant_id = ? AND status <> 'suspended'")->execute([$now, $now, $tenantId]);
        } else {
            // Re-activation never extends a subscription. It only restores the
            // previous effective state when its paid/trial period is still valid.
            $pdo->prepare("UPDATE saas_subscription SET status = CASE WHEN current_period_end >= ? THEN CASE WHEN starts_at > ? THEN 'trial' ELSE 'active' END ELSE 'suspended' END, suspended_at = NULL, updated_at = ? WHERE tenant_id = ? AND status = 'suspended'")->execute([$now, $now, $now, $tenantId]);
        }
        $audit->record($tenantId, $auth->userId(), 'tenant.' . $action . 'd', 'saas_tenant', $tenantId);
        sendJsonResponse(true, 'tenant updated');
    }
    if ($action === 'add_member' || $action === 'update_member') {
        $userId = (int) ($data['user_id'] ?? 0);
        $role = (string) ($data['role'] ?? 'TENANT_STAFF');
        if ($userId < 1 || !\MirzaBot\SaaS\Role::isKnown($role) || $role === 'MASTER_ADMIN') {
            sendJsonResponse(false, 'membership data invalid', [], 422);
        }
        $userExists = $pdo->prepare("SELECT id FROM saas_user WHERE id = ? AND status = 'active' LIMIT 1");
        $userExists->execute([$userId]);
        if ($userExists->fetchColumn() === false) {
            sendJsonResponse(false, 'user not found', [], 404);
        }
        $now = date('Y-m-d H:i:s');
        if ($action === 'add_member') {
            $statement = $pdo->prepare(
                'INSERT INTO saas_membership (tenant_id, user_id, role, permissions, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE role = VALUES(role), status = \'active\', updated_at = VALUES(updated_at)'
            );
            $statement->execute([$tenantId, $userId, $role, '{}', 'active', $now, $now]);
        } else {
            $statement = $pdo->prepare("UPDATE saas_membership SET role = ?, status = 'active', updated_at = ? WHERE tenant_id = ? AND user_id = ?");
            $statement->execute([$role, $now, $tenantId, $userId]);
            if ($statement->rowCount() !== 1) {
                sendJsonResponse(false, 'membership not found', [], 404);
            }
        }
        $audit->record($tenantId, $auth->userId(), 'tenant.member_' . ($action === 'add_member' ? 'added' : 'updated'), 'saas_membership', (string) $userId, ['role' => $role]);
        sendJsonResponse(true, 'member updated');
    }
    if ($action === 'remove_member') {
        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId < 1) {
            sendJsonResponse(false, 'membership data invalid', [], 422);
        }
        $ownerCount = $pdo->prepare("SELECT COUNT(*) FROM saas_membership WHERE tenant_id = ? AND role IN ('TENANT_OWNER', 'TENANT_ADMIN') AND status = 'active'");
        $ownerCount->execute([$tenantId]);
        $memberRole = $pdo->prepare("SELECT role FROM saas_membership WHERE tenant_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
        $memberRole->execute([$tenantId, $userId]);
        $role = $memberRole->fetchColumn();
        if ($role === false) {
            sendJsonResponse(false, 'membership not found', [], 404);
        }
        if (in_array((string) $role, ['TENANT_OWNER', 'TENANT_ADMIN'], true) && (int) $ownerCount->fetchColumn() <= 1) {
            sendJsonResponse(false, 'tenant must retain an active owner or admin', [], 409);
        }
        $statement = $pdo->prepare("UPDATE saas_membership SET status = 'removed', updated_at = ? WHERE tenant_id = ? AND user_id = ? AND status = 'active'");
        $statement->execute([date('Y-m-d H:i:s'), $tenantId, $userId]);
        $audit->record($tenantId, $auth->userId(), 'tenant.member_removed', 'saas_membership', (string) $userId);
        sendJsonResponse(true, 'member removed');
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
