<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

use PDO;

final class LegacyBotContext
{
    public static function activate(PDO $pdo, string $token): ?TenantContext
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        try {
            $statement = $pdo->prepare(
                "SELECT b.id, b.tenant_id, b.status, t.status AS tenant_status
                 FROM saas_bot b
                 INNER JOIN saas_tenant t ON t.id = b.tenant_id
                 WHERE b.token_hash = ? AND b.deleted_at IS NULL
                 LIMIT 1"
            );
            $statement->execute([SecretBox::tokenHash($token)]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || ($row['tenant_status'] ?? '') !== 'active' || ($row['status'] ?? '') === 'deleted') {
                return null;
            }

            $context = new TenantContext();
            $context->set((string) $row['tenant_id'], (int) $row['id']);
            $context->applyToPdo($pdo);
            return $context;
        } catch (\Throwable $e) {
            // The resolver is a compatibility bridge. Before migration 013 the
            // old single-tenant runtime must continue to work unchanged.
            error_log('[saas:legacy-context] resolver unavailable: ' . $e->getMessage());
            return null;
        }
    }
}
