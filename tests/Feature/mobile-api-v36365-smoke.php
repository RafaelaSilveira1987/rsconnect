<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = file_get_contents($root . '/app/Controllers/MobileApiController.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$checks = [
    'contacts tenant filter' => str_contains($controller, 'WHERE ct.tenant_id = :tenant_id'),
    'contact responsible tenant join' => str_contains($controller, 'LEFT JOIN users u ON u.id = c.assigned_user_id AND u.tenant_id = ct.tenant_id'),
    'contact lead tenant scope' => str_contains($controller, 'l.tenant_id = ct.tenant_id AND l.contact_id = ct.id'),
    'contact conversation tenant scope' => str_contains($controller, 'c2.tenant_id = ct.tenant_id AND c2.contact_id = ct.id'),
    'token tenant selected' => str_contains($controller, 'tkn.tenant_id AS token_tenant_id'),
    'user tenant selected separately' => str_contains($controller, 'u.tenant_id AS user_tenant_id'),
    'token tenant mismatch revokes token' => str_contains($controller, '$tokenTenantId !== $userTenantId')
        && str_contains($controller, 'UPDATE mobile_api_tokens SET revoked_at = NOW() WHERE id = :id'),
    'api identity uses token tenant' => str_contains($controller, '$user[\'tenant_id\'] = $tokenTenantId;'),
    'contacts response identifies tenant' => str_contains($controller, "'contacts' => \$contacts")
        && str_contains($controller, "PublicId::encode('tenant', \$tenantId)"),
    'version label' => (str_contains($version, '36.36.5') || str_contains($version, '36.36.6')),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FAILED: " . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "OK mobile-api-v36365-smoke" . PHP_EOL;
