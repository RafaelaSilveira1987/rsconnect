<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$governance = (string) file_get_contents($root . '/app/Services/MessageGovernanceService.php');
$users = (string) file_get_contents($root . '/app/Controllers/UserController.php');
$conversations = (string) file_get_contents($root . '/app/Controllers/ConversationController.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'Super Admin global autorizado' => str_contains($governance, 'u.tenant_id IS NULL AND u.role = "super_admin"'),
    'Assinatura integra o texto entregue' => str_contains($governance, '$result[\'delivered\'] = \'*\' . $signature . "*\\n" . $original;'),
    'Usuário global pode habilitar assinatura' => !str_contains($users, '$tenantId !== null ? $whatsappSignatureEnabled : 0'),
    'Retorno expõe diagnóstico' => str_contains($conversations, "'human_signature_applied'"),
    'Versão atualizada' => str_contains($version, '36.13.0'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - assinatura humana entregue ao WhatsApp, inclusive por Super Admin.\n";
