<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/saas_auth.php';
$auth = saas_require_auth();
if (!$auth->isMasterAdmin()) {
    http_response_code(403);
    exit('Forbidden');
}

$tenants = [];
$stats = ['total' => 0, 'active' => 0, 'suspended' => 0];
try {
    $stats['total'] = (int) $pdo->query('SELECT COUNT(*) FROM saas_tenant')->fetchColumn();
    $stats['active'] = (int) $pdo->query("SELECT COUNT(*) FROM saas_tenant WHERE status = 'active'")->fetchColumn();
    $stats['suspended'] = (int) $pdo->query("SELECT COUNT(*) FROM saas_tenant WHERE status = 'suspended'")->fetchColumn();
    $tenants = $pdo->query(
        "SELECT t.id, t.tenant_key, t.name, t.status, t.created_at,
                s.status AS subscription_status, s.current_period_end, s.grace_until
         FROM saas_tenant t
         LEFT JOIN saas_subscription s ON s.id = (
             SELECT MAX(s2.id) FROM saas_subscription s2 WHERE s2.tenant_id = t.id
         )
         ORDER BY t.created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[saas:master] dashboard failed: ' . $e->getMessage());
}
function master_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Master Admin</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div style="max-width:1200px;margin:40px auto;padding:0 20px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap">
        <div><h1>Master Admin</h1><p>مدیریت Tenantها و Subscriptionها</p></div>
        <div style="display:flex;gap:10px;align-items:center">
            <a class="btn btn-ghost" href="saas_dashboard.php">Tenant Dashboard</a>
            <form method="post" action="saas_logout.php">
                <input type="hidden" name="_csrf" value="<?= master_escape(csrf_token()) ?>">
                <button class="btn btn-no" type="submit">خروج</button>
            </form>
        </div>
    </div>
    <div class="stats" style="margin-top:30px">
        <div class="stat"><div class="stat-label">کل Tenantها</div><div class="stat-num"><?= number_format($stats['total']) ?></div></div>
        <div class="stat ok"><div class="stat-label">فعال</div><div class="stat-num"><?= number_format($stats['active']) ?></div></div>
        <div class="stat no"><div class="stat-label">Suspended</div><div class="stat-num"><?= number_format($stats['suspended']) ?></div></div>
    </div>
    <div class="card" style="margin-top:30px">
        <div class="card-head"><div class="card-title">Tenantها</div><div>API: <code>/api/saas_tenants.php</code></div></div>
        <div class="tbl-wrap"><table class="tbl-xl">
            <thead><tr><th>Key</th><th>نام</th><th>وضعیت</th><th>Subscription</th><th>پایان دوره</th><th>ایجاد</th></tr></thead>
            <tbody>
            <?php foreach ($tenants as $tenant): ?>
                <tr>
                    <td><?= master_escape($tenant['tenant_key']) ?></td>
                    <td><?= master_escape($tenant['name']) ?></td>
                    <td><?= master_escape($tenant['status']) ?></td>
                    <td><?= master_escape($tenant['subscription_status'] ?? '—') ?></td>
                    <td><?= master_escape($tenant['current_period_end'] ?? '—') ?></td>
                    <td><?= master_escape($tenant['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($tenants === []): ?><tr><td colspan="6">Tenantی وجود ندارد.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
</body>
</html>
