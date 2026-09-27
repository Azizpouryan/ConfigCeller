<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class DomainResolver
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Resolve only a verified host mapping; Host is not accepted as a tenant id. */
    public function resolve(string $host): ?array
    {
        $hostname = self::normalize($host);
        $hash = hash('sha256', $hostname);
        $statement = $this->pdo->prepare(
            "SELECT d.tenant_id, d.hostname, d.domain_type, t.tenant_key, t.name
             FROM saas_domain d
             INNER JOIN saas_tenant t ON t.id = d.tenant_id
             WHERE d.hostname_hash = ? AND d.hostname = ?
               AND d.verification_status = 'verified' AND t.status = 'active'
             LIMIT 1"
        );
        $statement->execute([$hash, $hostname]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '' || strlen($host) > 255 || str_contains($host, '/') || str_contains($host, '\\')) {
            throw new InvalidArgumentException('Invalid host.');
        }
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }
        $host = rtrim($host, '.');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            throw new InvalidArgumentException('Invalid hostname.');
        }
        return $host;
    }

    public function list(string $tenantId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT hostname, domain_type, verification_status, is_primary, verified_at, created_at, updated_at
             FROM saas_domain WHERE tenant_id = ? ORDER BY is_primary DESC, hostname ASC'
        );
        $statement->execute([$tenantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createPending(string $tenantId, string $host): array
    {
        $token = bin2hex(random_bytes(24));
        $this->registerPending($tenantId, $host, $token);
        return ['hostname' => self::normalize($host), 'verification_token' => $token];
    }

    public function registerPending(string $tenantId, string $host, string $verificationToken): void
    {
        $hostname = self::normalize($host);
        if ($verificationToken === '' || strlen($verificationToken) > 512) {
            throw new InvalidArgumentException('Invalid verification token.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO saas_domain
                (tenant_id, hostname, hostname_hash, domain_type, verification_status, verification_token_hash, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = date('Y-m-d H:i:s');
        $statement->execute([
            $tenantId,
            $hostname,
            hash('sha256', $hostname),
            'custom',
            'pending',
            hash('sha256', $verificationToken),
            $now,
            $now,
        ]);
    }

    public function remove(string $tenantId, string $host): void
    {
        $hostname = self::normalize($host);
        $statement = $this->pdo->prepare('DELETE FROM saas_domain WHERE tenant_id = ? AND hostname_hash = ? AND hostname = ?');
        $statement->execute([$tenantId, hash('sha256', $hostname), $hostname]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Domain not found.');
        }
    }

    public function verify(string $tenantId, string $host, string $verificationToken): void
    {
        $hostname = self::normalize($host);
        $statement = $this->pdo->prepare(
            "UPDATE saas_domain
             SET verification_status = 'verified', verified_at = ?, updated_at = ?
             WHERE tenant_id = ? AND hostname_hash = ? AND hostname = ? AND verification_token_hash = ?"
        );
        $now = date('Y-m-d H:i:s');
        $statement->execute([$now, $now, $tenantId, hash('sha256', $hostname), $hostname, hash('sha256', $verificationToken)]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Domain verification failed.');
        }
    }
}
