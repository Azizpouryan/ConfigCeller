<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../src/SaaS/bootstrap.php';

$lockPath = __DIR__ . '/../storage/saas-worker.lock';
$lockDirectory = dirname($lockPath);
if (!is_dir($lockDirectory)) {
    @mkdir($lockDirectory, 0775, true);
}
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$workerId = gethostname() . ':' . getmypid();
$batch = min(max((int) (getenv('MIRZABOT_SAAS_WORKER_BATCH') ?: 10), 1), 100);
$queue = new \MirzaBot\SaaS\JobQueue($pdo);
$processed = 0;

try {
    while ($processed < $batch) {
        $job = $queue->claim($workerId);
        if ($job === null) {
            break;
        }
        try {
            $payload = json_decode((string) $job['payload'], true, 512, JSON_THROW_ON_ERROR);
            $tenantId = (string) ($job['tenant_id'] ?? '');
            $context = new \MirzaBot\SaaS\TenantContext();
            $context->set($tenantId);

            switch ((string) $job['job_type']) {
                case 'bot.cleanup':
                    $manager = new \MirzaBot\SaaS\BotManager(
                        $pdo,
                        $context,
                        null,
                    );
                    $manager->cleanupDeleted((string) ($payload['public_id'] ?? ''));
                    break;
                default:
                    throw new RuntimeException('Unsupported SaaS job type.');
            }
            $queue->complete((int) $job['id'], $workerId);
        } catch (Throwable $e) {
            error_log('[saas:worker] job failed: ' . $e->getMessage());
            $queue->fail((int) $job['id'], $workerId, 'job failed', 120);
        }
        $processed++;
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
