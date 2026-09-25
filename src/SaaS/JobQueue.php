<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

use PDO;
use RuntimeException;

final class JobQueue
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function enqueue(
        string $jobType,
        array $payload,
        ?string $tenantId = null,
        ?int $botId = null,
        ?string $idempotencyKey = null,
        int $delaySeconds = 0,
        int $maxAttempts = 5,
    ): int {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $jobType)) {
            throw new RuntimeException('Invalid job type.');
        }
        if ($maxAttempts < 1 || $maxAttempts > 20) {
            throw new RuntimeException('Invalid maximum attempts.');
        }

        $idempotencyHash = $idempotencyKey === null || $idempotencyKey === '' ? null : hash('sha256', $idempotencyKey);
        if ($idempotencyHash !== null) {
            $existing = $this->pdo->prepare('SELECT id FROM saas_job WHERE idempotency_key_hash = ? LIMIT 1');
            $existing->execute([$idempotencyHash]);
            $id = $existing->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }

        $now = date('Y-m-d H:i:s');
        $available = date('Y-m-d H:i:s', time() + max(0, $delaySeconds));
        $statement = $this->pdo->prepare(
            'INSERT INTO saas_job
                (public_id, tenant_id, bot_id, job_type, payload, status, attempts, max_attempts,
                 available_at, idempotency_key, idempotency_key_hash, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $this->uuid(),
            $tenantId,
            $botId,
            $jobType,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'pending',
            $maxAttempts,
            $available,
            $idempotencyKey,
            $idempotencyHash,
            $now,
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Claims one job at a time using a row lock. The worker must call complete
     * or fail; no job is deleted as part of claiming.
     */
    public function claim(string $workerId): ?array
    {
        if ($workerId === '' || strlen($workerId) > 100) {
            throw new RuntimeException('Invalid worker id.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->query(
                "SELECT * FROM saas_job
                 WHERE status = 'pending' AND available_at <= NOW() AND attempts < max_attempts
                 ORDER BY id ASC LIMIT 1 FOR UPDATE"
            );
            $job = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE saas_job
                 SET status = 'running', attempts = attempts + 1, locked_at = ?, locked_by = ?, updated_at = ?
                 WHERE id = ? AND status = 'pending'"
            );
            $now = date('Y-m-d H:i:s');
            $update->execute([$now, $workerId, $now, (int) $job['id']]);
            $this->pdo->commit();
            $job['attempts'] = (int) $job['attempts'] + 1;
            $job['status'] = 'running';
            return $job;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function complete(int $jobId, string $workerId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE saas_job SET status = 'completed', locked_at = NULL, locked_by = NULL, updated_at = ?
             WHERE id = ? AND status = 'running' AND locked_by = ?"
        );
        $statement->execute([date('Y-m-d H:i:s'), $jobId, $workerId]);
    }

    public function fail(int $jobId, string $workerId, string $error, int $retryAfter = 60): void
    {
        $error = trim($error);
        $error = strlen($error) > 4000 ? substr($error, 0, 4000) : $error;
        $statement = $this->pdo->prepare(
            "UPDATE saas_job
             SET status = IF(attempts < max_attempts, 'pending', 'failed'),
                 available_at = ?, locked_at = NULL, locked_by = NULL, last_error = ?, updated_at = ?
             WHERE id = ? AND status = 'running' AND locked_by = ?"
        );
        $statement->execute([
            date('Y-m-d H:i:s', time() + max(1, $retryAfter)),
            $error,
            date('Y-m-d H:i:s'),
            $jobId,
            $workerId,
        ]);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
