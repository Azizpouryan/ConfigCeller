<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

final class Role
{
    public const MASTER_ADMIN = 'MASTER_ADMIN';
    public const TENANT_OWNER = 'TENANT_OWNER';
    public const TENANT_ADMIN = 'TENANT_ADMIN';
    public const TENANT_STAFF = 'TENANT_STAFF';
    public const TENANT_VIEWER = 'TENANT_VIEWER';

    private const PERMISSIONS = [
        self::MASTER_ADMIN => ['*'],
        self::TENANT_OWNER => [
            'tenant.read', 'tenant.update', 'members.read', 'members.manage',
            'bots.read', 'bots.manage', 'panels.read', 'panels.manage',
            'products.read', 'products.manage', 'orders.read', 'orders.manage',
            'payments.read', 'settings.read', 'settings.manage', 'backups.manage',
            'audit.read',
        ],
        self::TENANT_ADMIN => [
            'tenant.read', 'members.read', 'members.manage', 'bots.read', 'bots.manage',
            'panels.read', 'panels.manage', 'products.read', 'products.manage',
            'orders.read', 'orders.manage', 'payments.read', 'settings.read',
            'settings.manage', 'backups.manage', 'audit.read',
        ],
        self::TENANT_STAFF => [
            'tenant.read', 'members.read', 'bots.read', 'panels.read',
            'products.read', 'orders.read', 'payments.read', 'settings.read',
        ],
        self::TENANT_VIEWER => [
            'tenant.read', 'bots.read', 'panels.read', 'products.read',
            'orders.read', 'payments.read',
        ],
    ];

    public static function can(string $role, string $permission): bool
    {
        $permissions = self::PERMISSIONS[$role] ?? [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public static function isKnown(string $role): bool
    {
        return isset(self::PERMISSIONS[$role]);
    }
}
