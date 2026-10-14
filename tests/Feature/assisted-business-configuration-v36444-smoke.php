<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/app/Views/onboarding/index.php');
$controller = (string) file_get_contents($root . '/app/Controllers/OnboardingController.php');
$guide = (string) file_get_contents($root . '/app/Services/OnboardingGuideService.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');

$checks = [];
$checks['operação virou um único formulário de negócio'] =
    str_contains($view, "in_array(\$step['key'], \$businessOperationKeys, true)")
    && str_contains($view, "Router::url('/onboarding/operation')")
    && str_contains($view, 'O que o assistente precisa perguntar?')
    && str_contains($view, 'Quando sua equipe atende?')
    && str_contains($view, 'O cliente pode agendar pelo WhatsApp?')
    && str_contains($view, 'Quando o assistente não pode decidir sozinho?')
    && str_contains($view, 'Salvar e verificar');

$checks['tela principal não expõe contratos técnicos do runtime'] =
    !str_contains($view, 'calendar.pre_schedule')
    && !str_contains($view, 'Agenda inteligente integrada')
    && !str_contains($view, 'Fluxo executável e orientado')
    && !str_contains($view, 'Ação validada');

$checks['configuração de agenda usa conceitos simples de capacidade e aprovação'] =
    str_contains($view, 'name="calendar_enabled"')
    && str_contains($view, 'name="slot_capacity"')
    && str_contains($view, 'name="approval_mode"')
    && str_contains($view, 'Pessoas no mesmo horário')
    && str_contains($view, 'Quem confirma o horário?');

$checks['configuração essencial é salva em transação única'] =
    str_contains($routes, "'/onboarding/operation'")
    && str_contains($controller, 'function saveOperation')
    && str_contains($guide, 'function saveBusinessOperation')
    && str_contains($guide, '$this->pdo->beginTransaction()')
    && str_contains($guide, '$this->syncAssistedTriageAndWorkflow')
    && str_contains($guide, '$this->saveAttendance')
    && str_contains($guide, '$this->saveAgenda')
    && str_contains($guide, '$this->saveAgentLimits')
    && str_contains($guide, '$this->syncAssistedCapabilities')
    && str_contains($guide, '$this->pdo->rollBack()');

$checks['workflow e capabilities são derivados sem exigir edição técnica'] =
    str_contains($guide, 'syncAssistedTriageAndWorkflow')
    && str_contains($guide, "'calendar.pre_schedule' => \$agendaEnabled")
    && str_contains($guide, "'calendar.human_approval' => \$agendaEnabled && \$humanApproval")
    && str_contains($guide, "'calendar.confirm' => \$agendaEnabled && !\$humanApproval")
    && str_contains($guide, "required_before_schedule = :required_before_schedule");

$checks['layout possui hierarquia clean e responsiva'] =
    str_contains($css, 'RS Connect 36.44.4 — configuração do atendimento em linguagem de negócio')
    && str_contains($css, '.business-question-card')
    && str_contains($css, '.business-check-grid')
    && str_contains($css, '.business-save-bar')
    && str_contains($css, '@media (max-width: 720px)');

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
}
if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - configuração de negócio clean validada.\n";
