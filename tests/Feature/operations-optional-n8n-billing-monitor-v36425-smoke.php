<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$operations = (string) file_get_contents($root . '/app/Services/OperationsService.php');
$language = (string) file_get_contents($root . '/app/Services/OperationalLanguageService.php');
$playbook = (string) file_get_contents($root . '/app/Services/OperationalPlaybookService.php');
$billing = (string) file_get_contents($root . '/app/Services/BillingReminderService.php');
$billingController = (string) file_get_contents($root . '/app/Controllers/BillingReminderController.php');
$billingView = (string) file_get_contents($root . '/app/Views/billing_reminders/index.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

$failed = 0;
$check = static function (bool $condition, string $label) use (&$failed): void {
    if ($condition) {
        echo "[OK] {$label}\n";
        return;
    }
    $failed++;
    echo "[FALHA] {$label}\n";
};

$check(
    str_contains($operations, 'if ($localActiveFlows < 1)')
        && str_contains($operations, 'A integração externa está desativada intencionalmente')
        && strpos($operations, 'if ($localActiveFlows < 1)') < strpos($operations, '(new N8nLiveMetricsService())->snapshot()'),
    'monitor não consulta/exige n8n quando não existe fluxo ativo local'
);

$check(
    str_contains($operations, "channel IN ('n8n','whatsapp','email')")
        && str_contains($operations, "subscription_notification_rules")
        && str_contains($operations, 'financeira(s)/de vigência ativa(s) configurada(s) para n8n/externo'),
    'dependências financeiras externas ainda ativas impedem falso verde ao desligar n8n'
);

$check(
    str_contains($operations, '$activeRules = $billingRules + $lifecycleRules')
        && str_contains($operations, 'Nenhuma regra financeira, de vigência ou de teste está ativa. Não há execução automática pendente.'),
    'monitor financeiro fica normal quando não existe regra ativa'
);

$check(
    str_contains($language, 'Nenhuma automação externa está ativa ou é necessária neste momento.')
        && str_contains($language, 'Há avisos financeiros ou de vigência configurados para automação externa, mas o n8n está desativado.'),
    'linguagem operacional diferencia integração desativada de configuração financeira incompatível'
);

$check(
    str_contains($playbook, 'Para WhatsApp nativo, confirme a conexão central da plataforma selecionada no topo da tela.')
        && str_contains($playbook, 'troque o canal para RS Connect/WhatsApp nativo'),
    'playbook preserva n8n opcional e orienta os canais nativos'
);

$check(
    str_contains($billing, 'public function automationState(): array')
        && str_contains($billingController, "'automationState' => \$service->automationState()")
        && str_contains($billingView, 'Existem regras que ainda usam integração externa')
        && str_contains($billingView, 'WhatsApp nativo da plataforma'),
    'tela financeira explica quando uma regra ainda depende de n8n e oferece canal nativo'
);

$check(
    str_contains($version, 'RS Connect 36.42.5 — Monitoramento coerente sem n8n')
        && str_contains($version, 'RS Connect 36.43.0') && str_contains($version, 'RS Connect 36.43.1') && str_contains($version, 'RS Connect 36.44.0') && str_contains($version, 'RS Connect 36.44.1')
        && ($manifest['package_version'] ?? '') === '36.44.2'
        && ($manifest['database']['required_migration'] ?? '') === '125_native_financial_lifecycle_notifications.sql',
    'release 36.42.5 preservada e versão atual registrada com migration 125'
);

exit($failed > 0 ? 1 : 0);
