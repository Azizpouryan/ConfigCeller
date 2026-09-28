<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

use PDO;
use RuntimeException;

/**
 * Creates an isolated copy of the Legacy configuration for a new Tenant.
 * Credentials and Bot rows are deliberately excluded; BotManager owns them.
 */
final class TenantProvisioner
{
    private const CONFIG_TABLES = [
        'setting', 'shopSetting', 'channels', 'topicid', 'help', 'category',
        'product', 'marzban_panel', 'Discount', 'DiscountSell', 'affiliates',
        'app', 'departman', 'manualsell',
    ];

    private const SENSITIVE_COLUMNS = [
        'setting' => ['webhook_secret'],
        'PaySetting' => ['api', 'merchant', 'token', 'walletaddress', 'secret', 'endpoint', 'cardnumber', 'namecard', 'marchent'],
        'marzban_panel' => ['password_panel', 'secret_code', 'datelogin', 'proxies', 'inbounds', 'linksubx'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function cloneLegacyConfiguration(string $sourceTenantId, string $targetTenantId): array
    {
        $this->assertTenant($sourceTenantId);
        $this->assertTenant($targetTenantId);
        if ($sourceTenantId === $targetTenantId) {
            throw new RuntimeException('Source and target Tenant must differ.');
        }

        $counts = [];
        if ($this->tableExists('PaySetting') && $this->hasColumn('PaySetting', 'tenant_id')) {
            $counts['PaySetting'] = $this->copyPaySettings($sourceTenantId, $targetTenantId);
        }
        foreach (self::CONFIG_TABLES as $table) {
            if (!$this->tableExists($table) || !$this->hasColumn($table, 'tenant_id')) {
                continue;
            }
            $columns = $this->copyableColumns($table);
            if ($columns === []) {
                continue;
            }
            $quoted = implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', $columns));
            $sql = "INSERT INTO `{$table}` ({$quoted}, `tenant_id`) SELECT {$quoted}, ? FROM `{$table}` WHERE tenant_id = ?";
            $statement = $this->pdo->prepare($sql);
            $statement->execute([$targetTenantId, $sourceTenantId]);
            $counts[$table] = $statement->rowCount();
        }
        return $counts;
    }

    private function copyPaySettings(string $sourceTenantId, string $targetTenantId): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO `PaySetting` (`NamePay`, `ValuePay`, `tenant_id`)
             SELECT `NamePay`,
                    CASE WHEN LOWER(`NamePay`) REGEXP '(api|merchant|token|wallet|secret|password|endpoint|marchent|cardnumber|namecard|variza)'
                         THEN '0' ELSE `ValuePay` END,
                    ?
             FROM `PaySetting` WHERE tenant_id = ?"
        );
        $statement->execute([$targetTenantId, $sourceTenantId]);
        return $statement->rowCount();
    }

    private function copyableColumns(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME, EXTRA, GENERATION_EXPRESSION
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME <> \'tenant_id\'
             ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([$table]);
        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $extra = strtolower((string) ($row['EXTRA'] ?? ''));
            if (str_contains($extra, 'auto_increment') || str_contains($extra, 'generated') || (string) ($row['GENERATION_EXPRESSION'] ?? '') !== '') {
                continue;
            }
            $column = (string) ($row['COLUMN_NAME'] ?? '');
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
                throw new RuntimeException('Invalid configuration column.');
            }
            $normalized = strtolower($column);
            foreach (self::SENSITIVE_COLUMNS[$table] ?? [] as $needle) {
                if (str_contains($normalized, $needle)) {
                    continue 2;
                }
            }
            $columns[] = $column;
        }
        return $columns;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function assertTenant(string $tenantId): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tenantId)) {
            throw new RuntimeException('Invalid Tenant identifier.');
        }
    }
}
