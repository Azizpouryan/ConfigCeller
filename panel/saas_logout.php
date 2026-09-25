<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/saas_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check_value((string) ($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid logout request');
}

saas_auth_service()->logout();
header('Location: saas_login.php');
exit;
