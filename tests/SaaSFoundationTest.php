<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/SaaS/bootstrap.php';

use MirzaBot\SaaS\AuthContext;
use MirzaBot\SaaS\DomainResolver;
use MirzaBot\SaaS\Role;
use MirzaBot\SaaS\SecretBox;
use MirzaBot\SaaS\TenantContext;

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$pass = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

putenv('MIRZABOT_SECRET_KEY=base64:' . base64_encode(random_bytes(32)));
$box = SecretBox::fromEnvironment();
$secret = 'telegram-token-that-must-not-be-stored-plain';
$ciphertext = $box->encrypt($secret);
$pass($ciphertext !== $secret, 'SecretBox returned plaintext.');
$pass($box->decrypt($ciphertext) === $secret, 'SecretBox round-trip failed.');
$pass(SecretBox::tokenHash($secret) !== $secret, 'Secret hash returned plaintext.');

$context = new TenantContext();
$tenantId = '123e4567-e89b-42d3-a456-426614174000';
$context->set($tenantId, 7, ['source' => 'test']);
$pass($context->requireTenantId() === $tenantId, 'Tenant context did not retain tenant id.');
$pass($context->requireBotId() === 7, 'Bot context did not retain bot id.');

$nestedResult = $context->run('123e4567-e89b-42d3-a456-426614174001', static function (TenantContext $nested): string {
    return $nested->requireTenantId();
});
$pass($nestedResult === '123e4567-e89b-42d3-a456-426614174001', 'Nested tenant context failed.');
$pass($context->requireTenantId() === $tenantId, 'Previous tenant context was not restored.');

$context->clear();
$pass(!$context->hasTenant(), 'Tenant context was not cleared.');

$authContext = new AuthContext(1, $tenantId, Role::TENANT_OWNER, false, time() - 10, time());
$pass($authContext->can('bots.manage'), 'Tenant owner permission was not granted.');
$pass(!$authContext->can('system.shutdown'), 'Unknown permission was granted.');
$masterContext = new AuthContext(2, $tenantId, Role::MASTER_ADMIN, true, time() - 10, time());
$pass($masterContext->can('system.shutdown'), 'Master admin wildcard permission was not granted.');
$pass(DomainResolver::normalize('Panel.Example.com:443') === 'panel.example.com', 'Domain normalization failed.');

fwrite(STDOUT, "SaaS foundation tests passed.\n");
