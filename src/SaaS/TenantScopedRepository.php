<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Small, strict repository for the new SaaS control-plane tables.
 * Legacy helpers remain untouched until each legacy flow is migrated.
 */
final class TenantScopedRepository
{
    private const TABLES = [
        'saas_membership',
        'saas_bot',
        'saas_domain',
        'saas_subscription',
        'saas_audit_log',
        'saas_job',
        'saas_backup',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly TenantContext $context,
    ) {
    }

    public function find(string $table, array $filters = [], string $columns = '*'): ?array
    {
        $rows = $this->fetchAll($table, $filters, $columns, 1, 0, 'id', 'ASC');
        return $rows[0] ?? null;
    }

    public function fetchAll(
        string $table,
        array $filters = [],
        string $columns = '*',
        int $limit = 100,
        int $offset = 0,
        string $orderBy = 'id',
        string $direction = 'DESC'
    ): array {
        $table = $this->assertTable($table);
        $columns = $this->assertColumns($columns);
        $orderBy = $this->assertIdentifier($orderBy);
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $limit = max(1, min($limit, 1000));
        $offset = max(0, $offset);

        [$where, $params] = $this->where($filters);
        $sql = sprintf(
            'SELECT %s FROM `%s` WHERE tenant_id = :tenant_id%s ORDER BY `%s` %s LIMIT %d OFFSET %d',
            $columns,
            $table,
            $where === '' ? '' : ' AND ' . $where,
            $orderBy,
            $direction,
            $limit,
            $offset,
        );
        $params[':tenant_id'] = $this->context->requireTenantId();

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count(string $table, array $filters = []): int
    {
        $table = $this->assertTable($table);
        [$where, $params] = $this->where($filters);
        $sql = 'SELECT COUNT(*) FROM `' . $table . '` WHERE tenant_id = :tenant_id';
        if ($where !== '') {
            $sql .= ' AND ' . $where;
        }
        $params[':tenant_id'] = $this->context->requireTenantId();

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    public function insert(string $table, array $values): string|int
    {
        $table = $this->assertTable($table);
        if ($values === [] || array_key_exists('tenant_id', $values)) {
            throw new InvalidArgumentException('Tenant-scoped inserts must not provide tenant_id.');
        }

        $values = ['tenant_id' => $this->context->requireTenantId()] + $values;
        $columns = [];
        $placeholders = [];
        $params = [];
        foreach ($values as $column => $value) {
            $columns[] = '`' . $this->assertIdentifier((string) $column) . '`';
            $placeholder = ':v' . count($params);
            $placeholders[] = $placeholder;
            $params[$placeholder] = $value;
        }

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
        );
        $this->pdo->prepare($sql)->execute($params);
        return $this->pdo->lastInsertId();
    }

    public function update(string $table, array $values, array $filters): int
    {
        $table = $this->assertTable($table);
        if ($values === [] || array_key_exists('tenant_id', $values)) {
            throw new InvalidArgumentException('Tenant-scoped updates must not change tenant_id.');
        }
        [$where, $params] = $this->where($filters);
        $sets = [];
        foreach ($values as $column => $value) {
            $identifier = $this->assertIdentifier((string) $column);
            $placeholder = ':set_' . count($params);
            $sets[] = '`' . $identifier . '` = ' . $placeholder;
            $params[$placeholder] = $value;
        }

        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant_id';
        if ($where !== '') {
            $sql .= ' AND ' . $where;
        }
        $params[':tenant_id'] = $this->context->requireTenantId();
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    private function where(array $filters): array
    {
        $where = [];
        $params = [];
        foreach ($filters as $column => $value) {
            $identifier = $this->assertIdentifier((string) $column);
            $placeholder = ':where_' . count($params);
            $where[] = '`' . $identifier . '` = ' . $placeholder;
            $params[$placeholder] = $value;
        }

        return [implode(' AND ', $where), $params];
    }

    private function assertTable(string $table): string
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new RuntimeException('Table is not approved for tenant-scoped access.');
        }
        return $table;
    }

    private function assertColumns(string $columns): string
    {
        if ($columns === '*') {
            return $columns;
        }
        foreach (explode(',', $columns) as $column) {
            $this->assertIdentifier(trim($column));
        }
        return implode(', ', array_map(static fn(string $column): string => '`' . trim($column) . '`', explode(',', $columns)));
    }

    private function assertIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }
        return $identifier;
    }
}
