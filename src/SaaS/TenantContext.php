<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

use InvalidArgumentException;
use LogicException;

/**
 * Explicit tenant context for request and worker code.
 *
 * A tenant must be selected by trusted authentication or bot resolution before
 * tenant-owned data is accessed. This class deliberately has no URL or
 * user-input based resolver.
 */
final class TenantContext
{
    private ?string $tenantId = null;
    private ?int $botId = null;
    private array $attributes = [];

    public function set(string $tenantId, ?int $botId = null, array $attributes = []): void
    {
        $tenantId = strtolower(trim($tenantId));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $tenantId)) {
            throw new InvalidArgumentException('Invalid tenant identifier.');
        }
        if ($botId !== null && $botId < 1) {
            throw new InvalidArgumentException('Invalid bot identifier.');
        }

        $this->tenantId = $tenantId;
        $this->botId = $botId;
        $this->attributes = $attributes;
    }

    public function applyToPdo(\PDO $pdo): void
    {
        $tenant = $pdo->quote($this->requireTenantId());
        $bot = $this->botId === null ? 'NULL' : (string) $this->botId;
        $pdo->exec("SET @mirza_tenant_id = {$tenant}");
        $pdo->exec("SET @mirza_saas_bot_id = {$bot}");
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->botId = null;
        $this->attributes = [];
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function requireTenantId(): string
    {
        if ($this->tenantId === null) {
            throw new LogicException('Tenant context is required for this operation.');
        }

        return $this->tenantId;
    }

    public function botId(): ?int
    {
        return $this->botId;
    }

    public function requireBotId(): int
    {
        if ($this->botId === null) {
            throw new LogicException('Bot context is required for this operation.');
        }

        return $this->botId;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Run a callback with a temporary context and always restore the previous one.
     */
    public function run(string $tenantId, callable $callback, ?int $botId = null, array $attributes = []): mixed
    {
        $previousTenant = $this->tenantId;
        $previousBot = $this->botId;
        $previousAttributes = $this->attributes;

        $this->set($tenantId, $botId, $attributes);
        try {
            return $callback($this);
        } finally {
            $this->tenantId = $previousTenant;
            $this->botId = $previousBot;
            $this->attributes = $previousAttributes;
        }
    }
}
