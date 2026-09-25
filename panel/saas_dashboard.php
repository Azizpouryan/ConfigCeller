<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/saas_auth.php';

use MirzaBot\SaaS\TenantContext;
use MirzaBot\SaaS\TenantScopedRepository;

$auth = saas_require_auth('tenant.read');
$context = new TenantContext();
$context->set($auth->tenantId());
$repository = new TenantScopedRepository($pdo, $context);
$error = '';
$counts = ['customers' => 0, 'orders' => 0, 'panels' => 0, 'bots' => 0];
$bots = [];

try {
    $counts['customers'] = $repository->count('user');
    $counts['orders'] = $repository->count('invoice');
    $counts['panels'] = $repository->count('marzban_panel');
    $counts['bots'] = $repository->count('saas_bot');
    $bots = $repository->fetchAll('saas_bot', [], 'public_id, username, status, last_seen_at', 50, 0, 'id', 'ASC');
} catch (Throwable $e) {
    error_log('[saas:dashboard] scoped query failed: ' . $e->getMessage());
    $error = 'ساختار SaaS هنوز کامل Bootstrap نشده است.';
}

function saas_dashboard_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>داشبورد SaaS</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div style="max-width:1100px;margin:40px auto;padding:0 20px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap">
        <div>
            <h1>داشبورد Tenant</h1>
            <p>Tenant ID: <code><?= saas_dashboard_escape($auth->tenantId()) ?></code></p>
            <p>Role: <strong><?= saas_dashboard_escape($auth->role()) ?></strong></p>
        </div>
        <form method="post" action="saas_logout.php">
            <input type="hidden" name="_csrf" value="<?= saas_dashboard_escape(csrf_token()) ?>">
            <button class="btn btn-ghost" type="submit">خروج</button>
        </form>
    </div>

    <?php if ($error !== ''): ?>
        <div class="notice notice-no" style="margin:20px 0"><?= saas_dashboard_escape($error) ?></div>
    <?php endif; ?>

    <div class="stats" style="margin-top:30px">
        <div class="stat"><div class="stat-label">مشتریان Tenant</div><div class="stat-num"><?= number_format($counts['customers']) ?></div></div>
        <div class="stat"><div class="stat-label">فاکتورها</div><div class="stat-num"><?= number_format($counts['orders']) ?></div></div>
        <div class="stat"><div class="stat-label">پنل‌های VPN</div><div class="stat-num"><?= number_format($counts['panels']) ?></div></div>
        <div class="stat"><div class="stat-label">Botها</div><div class="stat-num"><?= number_format($counts['bots']) ?></div></div>
    </div>

    <div class="card" style="margin-top:30px">
        <div class="card-head"><div class="card-title">Botهای این Tenant</div></div>
        <div class="tbl-wrap">
            <table class="tbl-xl">
                <thead><tr><th>Username</th><th>Status</th><th>Last seen</th></tr></thead>
                <tbody>
                <?php foreach ($bots as $bot): ?>
                    <tr>
                        <td><?= saas_dashboard_escape($bot['username'] ?? '') ?></td>
                        <td><?= saas_dashboard_escape($bot['status'] ?? '') ?></td>
                        <td><?= saas_dashboard_escape($bot['last_seen_at'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($bots === []): ?><tr><td colspan="3">Botی ثبت نشده است.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
