<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Compatibility guard for the procedural Telegram core. It scopes simple
 * single-table SELECT/UPDATE/DELETE statements when a trusted Bot context is
 * active. INSERTs are left to migration 015 triggers, which fill tenant_id and
 * saas_bot_id from PDO session variables.
 *
 * Complex SQL is rejected instead of being allowed to run unscoped. The old
 * single-tenant runtime is unchanged when no trusted context is active.
 */
final class LegacySqlScope
{
    private const TABLES = [
        'admin', 'user', 'help', 'setting', 'channels', 'marzban_panel', 'product',
        'invoice', 'payment_report', 'discount', 'giftcodeconsumed', 'paysetting',
        'discountsell', 'affiliates', 'shopsetting', 'cancel_service', 'service_other',
        'card_number', 'requestagent', 'topicid', 'manualsell', 'departman',
        'support_message', 'wheel_list', 'botsaz', 'app', 'logs_api', 'category',
        'reagent_report',
    ];

    public static function prepare(PDO $pdo, string $sql, array $options = []): PDOStatement
    {
        $scoped = self::scope($sql);
        $statement = $pdo->prepare($scoped, $options);
        if (str_contains($scoped, ':__mirza_tenant_id')) {
            $statement->bindValue(':__mirza_tenant_id', self::tenantId(), PDO::PARAM_STR);
        }
        return $statement;
    }

    public static function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = self::prepare($pdo, $sql);
        $statement->execute();
        return $statement;
    }

    public static function scope(string $sql): string
    {
        $tenantId = self::tenantId();
        if ($tenantId === null) {
            return $sql;
        }
        if (preg_match('/;\s*\S/', $sql)) {
            throw new RuntimeException('Multi-statement legacy SQL is blocked in a tenant context.');
        }

        $trimmed = ltrim($sql);
        $operation = strtoupper(strtok($trimmed, " \t\r\n"));
        if (!in_array($operation, ['SELECT', 'UPDATE', 'DELETE'], true)) {
            return $sql;
        }
        if (preg_match('/\b(?:[A-Za-z_][A-Za-z0-9_]*\.)?tenant_id\s*=\s*/i', $sql)) {
            return $sql;
        }

        $pattern = match ($operation) {
            'UPDATE' => '/\bUPDATE\s+`?([A-Za-z_][A-Za-z0-9_]*)`?(?:\s+(?:AS\s+)?([A-Za-z_][A-Za-z0-9_]*))?\s+SET\b/i',
            'DELETE' => '/\bDELETE\s+FROM\s+`?([A-Za-z_][A-Za-z0-9_]*)`?(?:\s+(?:AS\s+)?([A-Za-z_][A-Za-z0-9_]*))?/i',
            default => '/\bFROM\s+`?([A-Za-z_][A-Za-z0-9_]*)`?(?:\s+(?:AS\s+)?([A-Za-z_][A-Za-z0-9_]*))?/i',
        };
        if (!preg_match($pattern, $sql, $match, PREG_OFFSET_CAPTURE)) {
            return $sql;
        }
        $table = strtolower($match[1][0]);
        if (!in_array($table, self::TABLES, true)) {
            return $sql;
        }

        $alias = isset($match[2][0]) ? strtolower($match[2][0]) : '';
        if (in_array($alias, ['where', 'group', 'order', 'limit', 'having', 'union', 'set', 'left', 'right', 'inner', 'outer', 'join', 'on'], true)) {
            $alias = '';
        }
        if (preg_match('/\bJOIN\b|,\s*`?[A-Za-z_][A-Za-z0-9_]*`?/i', substr($sql, $match[0][1] + strlen($match[0][0]))) && str_contains(strtoupper($sql), ' JOIN ')) {
            throw new RuntimeException('Joined legacy SQL must be migrated explicitly before tenant dispatch.');
        }

        $field = ($alias === '' ? '' : $alias . '.') . 'tenant_id';
        $condition = $field . ' = :__mirza_tenant_id';
        $tailOffset = strlen($sql);
        if (preg_match('/\b(GROUP\s+BY|ORDER\s+BY|HAVING|LIMIT|UNION|FOR\s+UPDATE)\b/i', $sql, $tail, PREG_OFFSET_CAPTURE)) {
            $tailOffset = $tail[0][1];
        }
        $head = substr($sql, 0, $tailOffset);
        $tailSql = substr($sql, $tailOffset);
        if (preg_match('/\bWHERE\b/i', $head)) {
            $head = rtrim($head) . ' AND ' . $condition . ' ';
        } else {
            $head = rtrim($head) . ' WHERE ' . $condition . ' ';
        }
        return $head . ltrim($tailSql);
    }

    private static function tenantId(): ?string
    {
        $context = $GLOBALS['mirzaSaasTenantContext'] ?? null;
        if (!is_object($context) || !method_exists($context, 'tenantId')) {
            return null;
        }
        $tenantId = $context->tenantId();
        if (!is_string($tenantId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tenantId)) {
            throw new InvalidArgumentException('Invalid trusted tenant context.');
        }
        return $tenantId;
    }
}
