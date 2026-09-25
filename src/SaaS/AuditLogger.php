<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

use PDO;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(
        ?string $tenantId,
        ?int $actorUserId,
        string $action,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $metadata = [],
        ?int $botId = null,
    ): void {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $action)) {
            throw new \InvalidArgumentException('Invalid audit action.');
        }
        $metadata = $this->redact($metadata);
        $statement = $this->pdo->prepare(
            'INSERT INTO saas_audit_log
                (tenant_id, actor_user_id, bot_id, action, resource_type, resource_id, metadata, ip_address, request_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $tenantId,
            $actorUserId,
            $botId,
            $action,
            $resourceType,
            $resourceId,
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $this->requestId(),
            date('Y-m-d H:i:s'),
        ]);
    }

    private function redact(array $metadata): array
    {
        $sensitive = ['token', 'bot_token', 'password', 'password_panel', 'secret', 'secret_code', 'api_key', 'valuepay', 'ciphertext'];
        $result = [];
        foreach ($metadata as $key => $value) {
            $normalized = strtolower((string) $key);
            $isSensitive = false;
            foreach ($sensitive as $needle) {
                if (str_contains($normalized, $needle)) {
                    $isSensitive = true;
                    break;
                }
            }
            $result[$key] = $isSensitive ? '[redacted]' : $this->redactValue($value, $sensitive);
        }
        return $result;
    }

    private function redactValue(mixed $value, array $sensitive): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            $result[$key] = in_array($normalized, $sensitive, true) ? '[redacted]' : $this->redactValue($item, $sensitive);
        }
        return $result;
    }

    private function requestId(): ?string
    {
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        return is_string($requestId) && preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $requestId) ? $requestId : null;
    }
}
