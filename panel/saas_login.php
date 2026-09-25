<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/saas_auth.php';

if (saas_current_auth() !== null) {
    header('Location: saas_dashboard.php');
    exit;
}

$error = '';
$tenantChoices = [];
$username = trim((string) ($_POST['username'] ?? ''));
$selectedTenant = trim((string) ($_POST['tenant_id'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check_value((string) ($_POST['_csrf'] ?? ''))) {
        $error = 'درخواست نامعتبر است.';
    } elseif ($username === '' || ($_POST['password'] ?? '') === '') {
        $error = 'نام کاربری و رمز عبور الزامی است.';
    } elseif (!check_login_rate($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) {
        $error = 'تعداد تلاش‌های ورود بیش از حد مجاز است.';
    } else {
        try {
            $authenticated = saas_auth_service()->authenticate($username, (string) $_POST['password']);
            if ($authenticated === null) {
                $error = 'نام کاربری یا رمز عبور نادرست است.';
            } else {
                $tenantChoices = $authenticated['memberships'] ?? [];
                if ($selectedTenant === '' && count($tenantChoices) === 1) {
                    $selectedTenant = (string) $tenantChoices[0]['tenant_id'];
                }
                if ($selectedTenant === '') {
                    $error = 'یک Tenant را انتخاب کنید.';
                } else {
                    saas_auth_service()->start($authenticated, $selectedTenant);
                    clear_login_rate($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
                    header('Location: saas_dashboard.php');
                    exit;
                }
            }
        } catch (Throwable $e) {
            error_log('[saas:login] login failed: ' . $e->getMessage());
            $error = 'ورود انجام نشد. تنظیمات SaaS را بررسی کنید.';
        }
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ورود پنل SaaS</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="auth">
    <main class="auth-main" style="width:100%">
        <div class="auth-box" style="animation:fadeUp .5s ease-out">
            <h1>پنل SaaS</h1>
            <p class="lede">ورود امن به Tenant انتخاب‌شده</p>
            <?php if ($error !== ''): ?>
                <div class="notice notice-no" style="margin-bottom:20px"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form class="auth-form" method="post" autocomplete="on">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="field">
                    <label for="username">نام کاربری</label>
                    <input class="input" id="username" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" maxlength="200" autocomplete="username" required autofocus>
                </div>
                <div class="field">
                    <label for="password">رمز عبور</label>
                    <input class="input" id="password" name="password" type="password" maxlength="4096" autocomplete="current-password" required>
                </div>
                <?php if (count($tenantChoices) > 1): ?>
                    <div class="field">
                        <label for="tenant_id">Tenant</label>
                        <select class="input" id="tenant_id" name="tenant_id" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($tenantChoices as $tenant): ?>
                                <option value="<?= htmlspecialchars((string) $tenant['tenant_id'], ENT_QUOTES, 'UTF-8') ?>" <?= $selectedTenant === $tenant['tenant_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $tenant['name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <button class="btn btn-primary" type="submit">ورود</button>
            </form>
            <div class="auth-bottom">Tenant از حساب احراز‌شده انتخاب می‌شود، نه از URL.</div>
        </div>
    </main>
</div>
</body>
</html>
