<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$passes = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$passes, &$failures): void {
    if ($condition) {
        $passes++;
        echo "[OK] {$message}\n";
        return;
    }
    $failures[] = $message;
    echo "[FALHA] {$message}\n";
};

$login = file_get_contents($root . '/app/Views/auth/login.php') ?: '';
$demoJs = file_get_contents($root . '/public/assets/js/login-demo.js') ?: '';
$crm = file_get_contents($root . '/app/Views/crm/pipeline.php') ?: '';
$controller = file_get_contents($root . '/app/Controllers/CrmController.php') ?: '';
$webhook = file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php') ?: '';
$service = file_get_contents($root . '/app/Services/CommercialAutomationService.php') ?: '';
$migration = file_get_contents($root . '/database/migrations/090_crm_conversation_automation.sql') ?: '';
$manifest = file_get_contents($root . '/database/migrations/manifest.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$check(str_contains($login, 'data-login-demo-open') && str_contains($login, 'data-login-demo-modal'), 'login possui acionador e modal da demonstração');
$check(str_contains($demoJs, 'Conhecer os planos') && str_contains($demoJs, "stage: ['Ganho'"), 'demonstração possui fluxo de qualificação até ganho');
$check(str_contains($crm, 'Automação do Comercial') && str_contains($crm, 'Apenas sugerir movimentações'), 'CRM possui configuração opcional da automação');
$check(str_contains($crm, 'Aprovar e mover') && str_contains($crm, 'Bloquear neste card'), 'CRM permite revisar sugestões e bloquear cards');
$check(str_contains($controller, 'saveAutomationSettings') && str_contains($controller, 'reviewAutomationSuggestion'), 'controller possui ações de configuração e revisão');
$check(str_contains($webhook, 'CommercialAutomationService') && str_contains($webhook, 'processIncoming'), 'webhook dispara análise comercial após criar ou localizar o lead');
$check(str_contains($service, "'mode' => 'suggest'") && str_contains($service, 'confidence_threshold'), 'serviço usa modo seguro e confiança configurável');
$check(str_contains($service, 'snoozeAfterManualMove') && str_contains($service, 'automation_locked'), 'serviço respeita movimentação manual e bloqueio por card');
$check(str_contains($migration, 'tenant_crm_automation_settings') && str_contains($migration, 'crm_automation_events'), 'migration cria configuração e histórico auditável');
$check(str_contains($manifest, "'sequence' => 97") && str_contains($manifest, '090_crm_conversation_automation.sql'), 'manifest registra a migration 090');
$check(str_contains($version, 'RS Connect 36.21.0') && str_contains($version, '090_crm_conversation_automation.sql'), 'versão e migration obrigatória foram atualizadas');

if ($failures) {
    echo "\n" . count($failures) . " falha(s).\n";
    exit(1);
}

echo "\nResumo automação comercial v36.21.0: {$passes} verificações aprovadas.\n";
