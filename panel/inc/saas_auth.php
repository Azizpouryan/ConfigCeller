<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../src/SaaS/bootstrap.php';

use MirzaBot\SaaS\AuthContext;
use MirzaBot\SaaS\AuthService;

function saas_auth_service(): AuthService
{
    global $pdo;
    static $service;
    if (!$service instanceof AuthService) {
        $idle = (int) (getenv('MIRZABOT_SAAS_IDLE_TIMEOUT') ?: 1800);
        $absolute = (int) (getenv('MIRZABOT_SAAS_ABSOLUTE_TIMEOUT') ?: 28800);
        $service = new AuthService($pdo, max(300, $idle), max(3600, $absolute));
    }
    return $service;
}

function saas_current_auth(): ?AuthContext
{
    try {
        return saas_auth_service()->current();
    } catch (Throwable $e) {
        error_log('[saas:auth] session validation failed: ' . $e->getMessage());
        return null;
    }
}

function saas_require_auth(?string $permission = null): AuthContext
{
    $auth = saas_current_auth();
    if ($auth === null) {
        header('Location: saas_login.php');
        exit;
    }
    if ($permission !== null && !$auth->can($permission)) {
        http_response_code(403);
        exit('Forbidden');
    }
    return $auth;
}
