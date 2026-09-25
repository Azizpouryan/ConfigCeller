<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Tenant-owned Bot lifecycle. Tokens are encrypted before persistence and are
 * never included in list/create responses or log messages.
 */
final class BotManager
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TenantContext $context,
        private readonly SecretBox $secretBox,
    ) {
    }

    public function list(): array
    {
        $statement = $this->pdo->prepare(
            "SELECT public_id, username, status, last_seen_at, last_health_check_at, last_error, created_at, updated_at
             FROM saas_bot
             WHERE tenant_id = ? AND deleted_at IS NULL
             ORDER BY id ASC"
        );
        $statement->execute([$this->context->requireTenantId()]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(string $token, string $username): array
    {
        $token = trim($token);
        $username = trim($username);
        if (!preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,}$/', $token)) {
            throw new InvalidArgumentException('Telegram Bot Token format is invalid.');
        }
        if ($username === '' || strlen($username) > 200 || !preg_match('/^[A-Za-z0-9_]{3,200}$/', ltrim($username, '@'))) {
            throw new InvalidArgumentException('Bot username is invalid.');
        }

        $tokenHash = SecretBox::tokenHash($token);
        $exists = $this->pdo->prepare('SELECT id FROM saas_bot WHERE token_hash = ? LIMIT 1');
        $exists->execute([$tokenHash]);
        if ($exists->fetchColumn() !== false) {
            throw new RuntimeException('This Telegram Bot is already registered.');
        }

        $now = date('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO saas_bot
                (public_id, tenant_id, username, token_hash, token_ciphertext,
                 webhook_secret_ciphertext, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $this->uuid(),
            $this->context->requireTenantId(),
            ltrim($username, '@'),
            $tokenHash,
            $this->secretBox->encrypt($token),
            $this->secretBox->encrypt(bin2hex(random_bytes(32))),
            'provisioning',
            $now,
            $now,
        ]);

        return $this->find((string) $this->pdo->lastInsertId(), true) ?? throw new RuntimeException('Bot was created but could not be loaded.');
    }

    public function softDelete(string $publicId): void
    {
        $this->assertPublicId($publicId);
        $statement = $this->pdo->prepare(
            "UPDATE saas_bot
             SET status = 'deleted', deleted_at = ?, updated_at = ?
             WHERE public_id = ? AND tenant_id = ? AND deleted_at IS NULL"
        );
        $statement->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $publicId, $this->context->requireTenantId()]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Bot was not found in the current tenant.');
        }
    }

    public function rotateToken(string $publicId, string $token): void
    {
        $this->assertPublicId($publicId);
        $token = trim($token);
        if (!preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,}$/', $token)) {
            throw new InvalidArgumentException('Telegram Bot Token format is invalid.');
        }

        $tokenHash = SecretBox::tokenHash($token);
        $exists = $this->pdo->prepare('SELECT id FROM saas_bot WHERE token_hash = ? AND public_id <> ? LIMIT 1');
        $exists->execute([$tokenHash, $publicId]);
        if ($exists->fetchColumn() !== false) {
            throw new RuntimeException('This Telegram Bot is already registered.');
        }

        $statement = $this->pdo->prepare(
            "UPDATE saas_bot
             SET token_hash = ?, token_ciphertext = ?, status = 'provisioning', last_error = NULL, updated_at = ?
             WHERE public_id = ? AND tenant_id = ? AND deleted_at IS NULL"
        );
        $statement->execute([
            $tokenHash,
            $this->secretBox->encrypt($token),
            date('Y-m-d H:i:s'),
            $publicId,
            $this->context->requireTenantId(),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Bot was not found in the current tenant.');
        }
    }

    /** Internal worker use only; never expose this return value to HTTP. */
    public function tokenFor(string $publicId): string
    {
        $this->assertPublicId($publicId);
        $bot = $this->rawFind($publicId);
        if (!is_array($bot) || empty($bot['token_ciphertext'])) {
            throw new RuntimeException('Bot credential is unavailable.');
        }
        return $this->secretBox->decrypt((string) $bot['token_ciphertext']);
    }

    public function markHealth(string $publicId, string $status, ?string $error = null): void
    {
        $this->assertPublicId($publicId);
        $allowed = ['active', 'paused', 'error', 'provisioning', 'deleted'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid Bot status.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE saas_bot
             SET status = ?, last_health_check_at = ?, last_error = ?, updated_at = ?
             WHERE public_id = ? AND tenant_id = ? AND deleted_at IS NULL'
        );
        $statement->execute([$status, date('Y-m-d H:i:s'), $error, date('Y-m-d H:i:s'), $publicId, $this->context->requireTenantId()]);
    }

    private function find(string $identifier, bool $byId = false): ?array
    {
        $column = $byId ? 'id' : 'public_id';
        $statement = $this->pdo->prepare(
            "SELECT public_id, username, status, last_seen_at, last_health_check_at, last_error, created_at, updated_at
             FROM saas_bot WHERE {$column} = ? AND tenant_id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $statement->execute([$identifier, $this->context->requireTenantId()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function rawFind(string $publicId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT public_id, token_ciphertext FROM saas_bot WHERE public_id = ? AND tenant_id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $statement->execute([$publicId, $this->context->requireTenantId()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function assertPublicId(string $publicId): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $publicId)) {
            throw new InvalidArgumentException('Invalid Bot identifier.');
        }
    }
}
