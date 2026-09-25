<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

use PDO;
use RuntimeException;

final class SubscriptionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Returns the effective access state without deleting or purging tenant data.
     * Legacy installations stay available until an explicit subscription is
     * created for them.
     */
    public function evaluate(string $tenantId): array
    {
        $tenant = $this->tenant($tenantId);
        if ($tenant === null) {
            throw new RuntimeException('Tenant not found.');
        }

        $subscription = $this->subscription($tenantId);
        if ($subscription === null && ($tenant['legacy_key'] ?? null) === 'legacy') {
            return ['status' => 'legacy', 'allowed' => true, 'plan' => null, 'subscription' => null];
        }
        if ($subscription === null) {
            return ['status' => 'unsubscribed', 'allowed' => false, 'plan' => null, 'subscription' => null];
        }

        $now = time();
        $periodEnd = strtotime((string) $subscription['current_period_end']) ?: 0;
        $graceUntil = !empty($subscription['grace_until']) ? (strtotime((string) $subscription['grace_until']) ?: 0) : 0;
        $status = (string) $subscription['status'];

        if (in_array($status, ['active', 'trial'], true) && $periodEnd >= $now) {
            return ['status' => $status, 'allowed' => true, 'plan' => $subscription['plan'], 'subscription' => $subscription];
        }
        if (in_array($status, ['active', 'trial', 'past_due', 'grace'], true) && $graceUntil >= $now) {
            return ['status' => 'grace', 'allowed' => true, 'plan' => $subscription['plan'], 'subscription' => $subscription];
        }
        if ($status !== 'suspended') {
            $this->suspend($tenantId, (int) $subscription['id']);
        }

        return ['status' => 'suspended', 'allowed' => false, 'plan' => $subscription['plan'], 'subscription' => $subscription];
    }

    public function requireAccess(string $tenantId, ?string $feature = null): array
    {
        $state = $this->evaluate($tenantId);
        if (!$state['allowed']) {
            throw new RuntimeException('Tenant subscription is suspended.');
        }
        if ($feature !== null && $state['plan'] !== null) {
            $features = json_decode((string) ($state['plan']['features'] ?? '{}'), true);
            if (is_array($features) && array_key_exists($feature, $features) && !$features[$feature]) {
                throw new RuntimeException('This feature is not available on the current plan.');
            }
        }
        return $state;
    }

    public function limit(string $tenantId, string $resource): ?int
    {
        $state = $this->evaluate($tenantId);
        if ($state['plan'] === null) {
            return null;
        }
        $limits = json_decode((string) ($state['plan']['limits'] ?? '{}'), true);
        if (!is_array($limits) || !array_key_exists($resource, $limits) || $limits[$resource] === null) {
            return null;
        }
        return max(0, (int) $limits[$resource]);
    }

    private function tenant(string $tenantId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, legacy_key, status FROM saas_tenant WHERE id = ? LIMIT 1');
        $statement->execute([$tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function subscription(string $tenantId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT s.*, p.code AS plan_code, p.name AS plan_name, p.limits, p.features
             FROM saas_subscription s
             INNER JOIN saas_plan p ON p.id = s.plan_id
             WHERE s.tenant_id = ?
             ORDER BY s.id DESC LIMIT 1"
        );
        $statement->execute([$tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $plan = [
            'code' => $row['plan_code'],
            'name' => $row['plan_name'],
            'limits' => $row['limits'],
            'features' => $row['features'],
        ];
        $row['plan'] = $plan;
        return $row;
    }

    private function suspend(string $tenantId, int $subscriptionId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE saas_subscription
             SET status = 'suspended', suspended_at = COALESCE(suspended_at, ?), updated_at = ?
             WHERE id = ? AND tenant_id = ? AND status <> 'suspended'"
        );
        $now = date('Y-m-d H:i:s');
        $statement->execute([$now, $now, $subscriptionId, $tenantId]);
    }
}
