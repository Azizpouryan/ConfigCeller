<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

use PDO;

final class AuthService
{
    private const SESSION_KEY = 'saas_auth';
    private const DEFAULT_IDLE_TIMEOUT = 1800;
    private const DEFAULT_ABSOLUTE_TIMEOUT = 28800;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $idleTimeout = self::DEFAULT_IDLE_TIMEOUT,
        private readonly int $absoluteTimeout = self::DEFAULT_ABSOLUTE_TIMEOUT,
    ) {
    }

    /**
     * Validate credentials and return available tenant memberships.
     * No tenant is ever inferred from a URL or a client-supplied resource id.
     */
    public function authenticate(string $username, string $password): ?array
    {
        $username = trim($username);
        if ($username === '' || strlen($username) > 200 || $password === '' || strlen($password) > 4096) {
            return null;
        }

        $statement = $this->pdo->prepare(
            "SELECT id, password_hash, is_master_admin, status
             FROM saas_user
             WHERE username = ? AND status = 'active'
             LIMIT 1"
        );
        $statement->execute([$username]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user) || !password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $this->pdo->prepare('UPDATE saas_user SET password_hash = ?, updated_at = ? WHERE id = ?');
            $rehash->execute([password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s'), (int) $user['id']]);
        }

        $memberships = $this->memberships((int) $user['id']);
        if ($memberships === []) {
            return null;
        }

        return [
            'user' => $user,
            'memberships' => $memberships,
        ];
    }

    /**
     * Start a session for a membership already returned by authenticate().
     */
    public function start(array $authenticated, string $tenantId): AuthContext
    {
        $user = $authenticated['user'] ?? null;
        $memberships = $authenticated['memberships'] ?? [];
        if (!is_array($user) || !isset($user['id']) || !is_array($memberships)) {
            throw new \InvalidArgumentException('Invalid authentication result.');
        }

        $membership = null;
        foreach ($memberships as $candidate) {
            if (($candidate['tenant_id'] ?? '') === $tenantId) {
                $membership = $candidate;
                break;
            }
        }
        if ($membership === null) {
            throw new \RuntimeException('The selected tenant is not assigned to this user.');
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_regenerate_id(true);
        $now = time();
        $_SESSION[self::SESSION_KEY] = [
            'user_id' => (int) $user['id'],
            'tenant_id' => (string) $membership['tenant_id'],
            'role' => (string) $membership['role'],
            'is_master_admin' => (bool) ($user['is_master_admin'] ?? false),
            'issued_at' => $now,
            'last_activity' => $now,
        ];

        $statement = $this->pdo->prepare('UPDATE saas_user SET last_login_at = ?, updated_at = ? WHERE id = ?');
        $statement->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), (int) $user['id']]);

        return $this->contextFromSession($_SESSION[self::SESSION_KEY]);
    }

    public function current(): ?AuthContext
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $session = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($session)) {
            return null;
        }

        $now = time();
        $issuedAt = (int) ($session['issued_at'] ?? 0);
        $lastActivity = (int) ($session['last_activity'] ?? 0);
        if ($issuedAt < 1 || $lastActivity < 1 || ($now - $lastActivity) > $this->idleTimeout || ($now - $issuedAt) > $this->absoluteTimeout) {
            $this->logout();
            return null;
        }

        $userId = (int) ($session['user_id'] ?? 0);
        $tenantId = (string) ($session['tenant_id'] ?? '');
        $role = (string) ($session['role'] ?? '');
        if ($userId < 1 || !preg_match('/^[0-9a-f-]{36}$/i', $tenantId) || !Role::isKnown($role)) {
            $this->logout();
            return null;
        }

        $statement = $this->pdo->prepare(
            "SELECT u.is_master_admin, m.role
             FROM saas_user u
             INNER JOIN saas_membership m ON m.user_id = u.id
             INNER JOIN saas_tenant t ON t.id = m.tenant_id
             WHERE u.id = ? AND m.tenant_id = ?
               AND u.status = 'active' AND m.status = 'active' AND t.status = 'active'
             LIMIT 1"
        );
        $statement->execute([$userId, $tenantId]);
        $membership = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($membership) || (string) $membership['role'] !== $role) {
            $this->logout();
            return null;
        }

        // Refresh activity only after the membership has been revalidated.
        $_SESSION[self::SESSION_KEY]['last_activity'] = $now;
        $_SESSION[self::SESSION_KEY]['is_master_admin'] = (bool) $membership['is_master_admin'];

        return new AuthContext($userId, $tenantId, $role, (bool) $membership['is_master_admin'], $issuedAt, $now);
    }

    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    public function memberships(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT m.tenant_id, m.role, t.tenant_key, t.name
             FROM saas_membership m
             INNER JOIN saas_tenant t ON t.id = m.tenant_id
             WHERE m.user_id = ? AND m.status = 'active' AND t.status = 'active'
             ORDER BY t.name ASC"
        );
        $statement->execute([$userId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function contextFromSession(array $session): AuthContext
    {
        return new AuthContext(
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['role'],
            (bool) $session['is_master_admin'],
            (int) $session['issued_at'],
            (int) $session['last_activity'],
        );
    }
}
