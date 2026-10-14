<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/app/Views/onboarding/index.php');
$calendarService = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/OnboardingController.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true) ?: [];
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [];
$checks['onboarding virou Ativação Assistida com quatro blocos de negócio'] =
    str_contains($view, 'Ativação Assistida')
    && str_contains($view, "'label' => 'Sua empresa'")
    && str_contains($view, "'label' => 'Como atender'")
    && str_contains($view, "'label' => 'Conectar'")
    && str_contains($view, "'label' => 'Testar e ativar'");

$checks['interface mostra apenas uma tarefa por vez sem remover validações internas'] =
    str_contains($view, "if ((string) (\$step['key'] ?? '') !== \$activeStepKey) continue;")
    && str_contains($view, 'Visão rápida')
    && str_contains($view, 'Voltar para a próxima tarefa');

$checks['cadastro pede segmento e oculta decisões técnicas em ajustes opcionais'] =
    str_contains($view, 'Comece pelo segmento.')
    && str_contains($view, 'name="business_niche_id" required')
    && str_contains($view, 'name="agent_blueprint_id" required')
    && str_contains($view, 'Ajustes opcionais')
    && str_contains($view, 'Normalmente não é necessário alterar');

$checks['atendimento usa perguntas de negócio e recolhe SLA e mensagens opcionais'] =
    str_contains($view, 'O que o assistente precisa perguntar?')
    && str_contains($view, 'Quando sua equipe atende?')
    && str_contains($view, 'Mensagem fora do horário e SLA')
    && str_contains($view, 'sla_target_minutes')
    && str_contains($view, 'after_hours_message');

$checks['agenda expressa pergunta capacidade e confirmação sem expor integração técnica'] =
    str_contains($view, 'O cliente pode agendar pelo WhatsApp?')
    && str_contains($view, 'name="slot_capacity"')
    && str_contains($view, 'Quem confirma o horário?')
    && str_contains($view, 'Minha equipe')
    && str_contains($view, 'Automaticamente')
    && str_contains($calendarService, "'booking_capacity_mode' => \$this->normalizeCapacityMode")
    && str_contains($calendarService, "'default_slot_capacity' => max(1, min(100");

$checks['limites mostram decisões simples em linguagem de negócio'] =
    str_contains($view, 'Quando o assistente não pode decidir sozinho?')
    && str_contains($view, 'Pedido de desconto')
    && str_contains($view, 'A empresa atende qualquer idade?')
    && str_contains($view, 'Outros casos especiais');

$checks['histórico e opções técnicas não disputam atenção com a tarefa atual'] =
    str_contains($view, 'onboarding-history-details')
    && str_contains($view, 'Ver histórico')
    && str_contains($css, 'RS Connect 36.44.2 — Ativação Assistida enxuta');

$checks['navegação e título usam Ativação Assistida'] =
    str_contains($controller, "'title' => 'Ativação Assistida'")
    && str_contains($layout, '<span>Ativação Assistida</span>')
    && str_contains($layout, '/assets/css/app.css?v=36.44.4');

$checks['release 36.44.2 não exige migration nova'] =
    ($manifest['package_version'] ?? '') === '36.44.4'
    && ($manifest['database']['required_migration'] ?? '') === '125_native_financial_lifecycle_notifications.sql'
    && str_contains($version, 'RS Connect 36.44.2 — Ativação Assistida enxuta')
    && str_contains($version, "REQUIRED_MIGRATION = '125_native_financial_lifecycle_notifications.sql'");

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
}
if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - Ativação Assistida enxuta validada.\n";
