<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Versioned, encrypted tenant export. Restore is intentionally validation-first:
 * legacy tables still have global primary keys and cannot be safely remapped to
 * a new tenant until the key migration is complete.
 */
final class TenantBackupService
{
    private const FORMAT = 'mirza-tenant-backup';
    private const VERSION = 1;
    private const MODES = ['full', 'data_only', 'config_only'];

    private const DATA_TABLES = [
        'user', 'invoice', 'Payment_report', 'service_other', 'cancel_service',
        'Giftcodeconsumed', 'reagent_report', 'wheel_list', 'support_message',
        'manualsell', 'Requestagent',
    ];

    private const CONFIG_TABLES = [
        'setting', 'PaySetting', 'shopSetting', 'affiliates', 'channels', 'topicid',
        'help', 'category', 'product', 'marzban_panel', 'card_number', 'departman',
        'app', 'Discount', 'DiscountSell', 'botsaz',
    ];

    private const ALL_TABLES = [
        'admin', 'user', 'help', 'setting', 'channels', 'marzban_panel', 'product',
        'invoice', 'Payment_report', 'Discount', 'Giftcodeconsumed', 'PaySetting',
        'DiscountSell', 'affiliates', 'shopSetting', 'cancel_service', 'service_other',
        'card_number', 'Requestagent', 'topicid', 'manualsell', 'departman',
        'support_message', 'wheel_list', 'botsaz', 'app', 'category', 'reagent_report',
    ];

    private const SECRET_FIELDS = [
        'botsaz' => ['bot_token', 'webhook_secret'],
        'marzban_panel' => ['password_panel', 'secret_code'],
        'user' => ['token'],
        'setting' => ['webhook_secret'],
        'PaySetting' => ['ValuePay'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SecretBox $secretBox,
    ) {
    }

    public function create(string $tenantId, string $mode = 'full', ?int $initiatedBy = null): array
    {
        $this->assertTenantId($tenantId);
        $this->assertMode($mode);
        $tenant = $this->tenant($tenantId);
        if ($tenant === null) {
            throw new RuntimeException('Tenant not found.');
        }

        $tables = $this->tablesForMode($mode);
        $payload = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'mode' => $mode,
            'created_at' => gmdate('c'),
            'tenant' => $tenant,
            'tables' => [],
            'control_plane' => $this->controlPlane($tenantId, $mode),
        ];

        foreach ($tables as $table) {
            $rows = $this->tenantRows($table, $tenantId);
            $payload['tables'][$table] = array_map(fn(array $row): array => $this->protectRow($table, $row), $rows);
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $encrypted = $this->secretBox->encrypt($json);
        $path = $this->writeFile($tenantId, $encrypted);
        $publicId = $this->uuid();
        $manifest = $this->manifest($payload);

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO saas_backup
                    (public_id, tenant_id, version, mode, status, storage_path, manifest, checksum, encrypted, initiated_by, created_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
            );
            $now = date('Y-m-d H:i:s');
            $statement->execute([
                $publicId,
                $tenantId,
                (string) self::VERSION,
                $mode,
                'completed',
                $path,
                json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                hash('sha256', $encrypted),
                $initiatedBy,
                $now,
                $now,
            ]);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        return [
            'public_id' => $publicId,
            'tenant_id' => $tenantId,
            'version' => self::VERSION,
            'mode' => $mode,
            'manifest' => $manifest,
            'checksum' => hash('sha256', $encrypted),
        ];
    }

    public function preview(string $tenantId, string $backupId): array
    {
        $payload = $this->load($tenantId, $backupId);
        return $this->manifest($payload);
    }

    /**
     * Deliberately refuses mutation until legacy identifiers are remappable.
     * This prevents a "new tenant" restore from colliding on user.id,
     * invoice.id_invoice and other global legacy keys.
     */
    public function restore(string $tenantId, string $backupId, string $mode): never
    {
        $this->assertTenantId($tenantId);
        if (!in_array($mode, ['new', 'merge', 'replace'], true)) {
            throw new InvalidArgumentException('Restore mode must be new, merge or replace.');
        }
        $this->load($tenantId, $backupId);
        throw new RuntimeException('Restore mutation is blocked until legacy primary keys are tenant-remapped. Use preview first.');
    }

    private function tenant(string $tenantId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, tenant_key, name, status, legacy_key, metadata, created_at, updated_at FROM saas_tenant WHERE id = ? LIMIT 1');
        $statement->execute([$tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function controlPlane(string $tenantId, string $mode): array
    {
        $result = [];
        if ($mode !== 'data_only') {
            $result['saas_bot'] = $this->tenantRows('saas_bot', $tenantId);
            $result['saas_domain'] = $this->tenantRows('saas_domain', $tenantId);
            $result['saas_subscription'] = $this->tenantRows('saas_subscription', $tenantId);
        }

        $membership = $this->pdo->prepare(
            'SELECT m.* FROM saas_membership m WHERE m.tenant_id = ? ORDER BY m.id ASC'
        );
        $membership->execute([$tenantId]);
        $result['saas_membership'] = $membership->fetchAll(PDO::FETCH_ASSOC);

        $userIds = array_values(array_unique(array_map('intval', array_column($result['saas_membership'], 'user_id'))));
        $result['saas_user'] = [];
        if ($userIds !== []) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $users = $this->pdo->prepare("SELECT id, public_id, legacy_admin_id, username, email, email_hash, password_hash, is_master_admin, status, last_login_at, created_at, updated_at FROM saas_user WHERE id IN ({$placeholders})");
            $users->execute($userIds);
            $result['saas_user'] = $users->fetchAll(PDO::FETCH_ASSOC);
        }

        return $result;
    }

    private function tenantRows(string $table, string $tenantId): array
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException('Invalid backup table.');
        }
        $statement = $this->pdo->prepare("SELECT * FROM `{$table}` WHERE tenant_id = ?");
        $statement->execute([$tenantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function protectRow(string $table, array $row): array
    {
        foreach (self::SECRET_FIELDS[$table] ?? [] as $field) {
            if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                continue;
            }
            $row[$field] = ['__mirza_secret_v1' => $this->secretBox->encrypt((string) $row[$field])];
        }
        return $row;
    }

    private function load(string $tenantId, string $backupId): array
    {
        $this->assertTenantId($tenantId);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $backupId)) {
            throw new InvalidArgumentException('Invalid backup identifier.');
        }

        $statement = $this->pdo->prepare('SELECT storage_path, checksum, encrypted, version FROM saas_backup WHERE public_id = ? AND tenant_id = ? LIMIT 1');
        $statement->execute([$backupId, $tenantId]);
        $backup = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($backup) || (int) $backup['encrypted'] !== 1 || (string) $backup['version'] !== (string) self::VERSION) {
            throw new RuntimeException('Backup not found or unsupported.');
        }

        $path = $this->safePath((string) $backup['storage_path']);
        $encrypted = @file_get_contents($path);
        if ($encrypted === false || !hash_equals((string) $backup['checksum'], hash('sha256', $encrypted))) {
            throw new RuntimeException('Backup checksum validation failed.');
        }
        $payload = json_decode($this->secretBox->decrypt($encrypted), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['format'] ?? null) !== self::FORMAT || (int) ($payload['version'] ?? 0) !== self::VERSION || (($payload['tenant']['id'] ?? null) !== $tenantId)) {
            throw new RuntimeException('Backup manifest validation failed.');
        }
        return $payload;
    }

    private function manifest(array $payload): array
    {
        $counts = [];
        foreach ($payload['tables'] ?? [] as $table => $rows) {
            $counts[$table] = is_array($rows) ? count($rows) : 0;
        }
        foreach ($payload['control_plane'] ?? [] as $table => $rows) {
            $counts[$table] = is_array($rows) ? count($rows) : 0;
        }
        return [
            'format' => $payload['format'] ?? self::FORMAT,
            'version' => (int) ($payload['version'] ?? self::VERSION),
            'mode' => $payload['mode'] ?? 'unknown',
            'created_at' => $payload['created_at'] ?? null,
            'tenant_id' => $payload['tenant']['id'] ?? null,
            'tenant_key' => $payload['tenant']['tenant_key'] ?? null,
            'counts' => $counts,
        ];
    }

    private function tablesForMode(string $mode): array
    {
        if ($mode === 'data_only') {
            return self::DATA_TABLES;
        }
        if ($mode === 'config_only') {
            return self::CONFIG_TABLES;
        }
        return self::ALL_TABLES;
    }

    private function writeFile(string $tenantId, string $contents): string
    {
        $root = getenv('MIRZABOT_BACKUP_PATH') ?: dirname(__DIR__, 2) . '/storage/backups';
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Backup storage is unavailable.');
        }
        $rootReal = realpath($root);
        if ($rootReal === false) {
            throw new RuntimeException('Backup storage path is invalid.');
        }
        $name = strtolower($tenantId) . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(8)) . '.mbk';
        $path = $rootReal . DIRECTORY_SEPARATOR . $name;
        $temporary = $path . '.tmp';
        if (@file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to write backup.');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to finalize backup.');
        }
        return $path;
    }

    private function safePath(string $path): string
    {
        $root = getenv('MIRZABOT_BACKUP_PATH') ?: dirname(__DIR__, 2) . '/storage/backups';
        $rootReal = realpath($root);
        $pathReal = realpath($path);
        if ($rootReal === false || $pathReal === false || !str_starts_with($pathReal, $rootReal . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Backup path is outside the configured storage directory.');
        }
        return $pathReal;
    }

    private function assertTenantId(string $tenantId): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tenantId)) {
            throw new InvalidArgumentException('Invalid tenant identifier.');
        }
    }

    private function assertMode(string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Backup mode is invalid.');
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
