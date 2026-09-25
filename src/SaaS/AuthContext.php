<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

use LogicException;

final class AuthContext
{
    public function __construct(
        private readonly int $userId,
        private readonly string $tenantId,
        private readonly string $role,
        private readonly bool $masterAdmin,
        private readonly int $issuedAt,
        private readonly int $lastActivity,
    ) {
        if ($userId < 1 || !Role::isKnown($role)) {
            throw new LogicException('Invalid authentication context.');
        }
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function isMasterAdmin(): bool
    {
        return $this->masterAdmin;
    }

    public function issuedAt(): int
    {
        return $this->issuedAt;
    }

    public function lastActivity(): int
    {
        return $this->lastActivity;
    }

    public function can(string $permission): bool
    {
        return $this->masterAdmin || Role::can($this->role, $permission);
    }
}
