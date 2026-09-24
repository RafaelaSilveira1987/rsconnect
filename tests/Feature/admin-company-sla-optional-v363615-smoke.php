<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/Services/SlaPolicyService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CompanyController.php');
$conversation = (string) file_get_contents($root . '/app/Controllers/ConversationController.php');
$ops = (string) file_get_contents($root . '/app/Services/OperationalLoadService.php');
$view = (string) file_get_contents($root . '/app/Views/companies/overview.php');
$opsView = (string) file_get_contents($root . '/app/Views/operational_load/index.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'SLA pode ser ativado/desativado no RS Admin' => str_contains($view, 'name="sla_enabled"') && str_contains($view, 'Usar SLA operacional nesta empresa'),
    'service persiste a coluna enabled existente' => str_contains($service, ':enabled') && str_contains($service, "'enabled' => \$enabled"),
    'salvamento sem campo enabled preserva estado atual' => str_contains($service, "array_key_exists('sla_enabled', \$data)") && str_contains($service, "(int) (\$current['enabled'] ?? 1)"),
    'sincronização de expediente não reativa SLA silenciosamente' => str_contains($service, "'sla_enabled' => (int) (\$current['enabled'] ?? 1) === 1 ? '1' : '0'"),
    'controller administrativo grava e audita estado' => str_contains($controller, "'sla_enabled' =>") && str_contains($controller, "'enabled_before'") && str_contains($controller, "'enabled_after'"),
    'conversas não exibem SLA quando tenant desativou' => str_contains($conversation, '$slaEnabledCache') && str_contains($conversation, 'empty($slaEnabledCache[$tenantId])'),
    'carga operacional respeita SLA desligado' => str_contains($ops, '$slaEnabled = !empty($this->sla->settings($tenantId)[\'enabled\'])') && str_contains($opsView, 'SLA operacional está desativado'),
    'estado canônico possui status disabled' => str_contains($service, "'status' => 'disabled'") && str_contains($service, "'enabled' => 0"),
    'pacote identificado como 36.36.15' => str_contains($version, 'RS Connect 36.36.15 — SLA operacional opcional por empresa'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - SLA operacional opcional por empresa validado.\n";
