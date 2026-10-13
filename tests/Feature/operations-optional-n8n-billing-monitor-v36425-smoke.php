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
        && str_contains($operations, 'regra(s) de cobrança ativa(s) configurada(s) para n8n/externo'),
    'dependências de cobrança ainda ativas impedem falso verde ao desligar n8n'
);

$check(
    str_contains($operations, '$activeRules = $this->count("SELECT COUNT(*) FROM billing_reminder_rules WHERE status = \'active\'")')
        && str_contains($operations, 'Nenhuma regra de lembrete de cobrança está ativa. Não há execução automática de cobrança pendente.'),
    'monitor de cobrança fica normal quando não existe regra ativa'
);

$check(
    str_contains($language, 'Nenhuma automação externa está ativa ou é necessária neste momento.')
        && str_contains($language, 'Há avisos de cobrança ativos configurados para automação externa, mas o n8n está desativado.'),
    'linguagem operacional diferencia integração desativada de configuração incompatível'
);

$check(
    str_contains($playbook, 'Se o n8n foi desativado intencionalmente, marque como Inativas as regras configuradas para n8n/externo.'),
    'playbook orienta resolver a configuração em vez de exigir n8n por padrão'
);

$check(
    str_contains($billing, 'public function automationState(): array')
        && str_contains($billingController, "'automationState' => \$service->automationState()")
        && str_contains($billingView, 'Existem regras ativas que ainda dependem do n8n')
        && str_contains($billingView, 'altere a Situação para <strong>Inativa</strong>'),
    'tela da régua explica diretamente por que o alerta continua quando n8n está desligado'
);

$check(
    str_contains($version, 'RS Connect 36.42.5 — Monitoramento coerente sem n8n')
        && ($manifest['package_version'] ?? '') === '36.42.5'
        && ($manifest['database']['required_migration'] ?? '') === '124_calendar_slot_capacity_mode.sql',
    'release 36.42.5 registrada sem migration nova'
);

exit($failed > 0 ? 1 : 0);
