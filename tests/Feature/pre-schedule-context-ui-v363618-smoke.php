<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$view = (string) file_get_contents($root . '/app/Views/calendar/index.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'agenda carrega grupo atual do contato' => str_contains($controller, 'ct.contact_group AS current_contact_group'),
    'agenda carrega demanda atual da conversa' => str_contains($controller, 'fs.demand_status AS current_demand_status') && str_contains($controller, 'fs.demand_summary AS current_demand_summary'),
    'agenda usa fallback da triagem atual' => str_contains($controller, 'ts.collected_json AS current_triage_collected_json'),
    'pré-agendamento usa registro consolidado' => str_contains($view, 'Informações registradas') && str_contains($view, 'pre-schedule-record-list'),
    'registro consolidado mostra os campos operacionais' => str_contains($view, 'Situação da demanda') && str_contains($view, 'Dia/período informado') && str_contains($view, 'Horário/período informado') && str_contains($view, 'Responsável') && str_contains($view, 'Mensagem que originou o pedido'),
    'texto histórico fica apenas como auditoria recolhida' => str_contains($view, 'Ver texto original para auditoria') && str_contains($view, 'pre-schedule-technical-record'),
    'status atual de demanda usa rótulos operacionais' => str_contains($view, 'ConversationFlowService::DEMAND_STATUSES'),
    'layout responsivo foi simplificado' => str_contains($css, '.pre-schedule-record-list') && str_contains($css, '@media (max-width: 680px)'),
    'cache de CSS renovado' => str_contains($layout, 'app.css?v=36.36.19'),
    'pacote identificado como 36.36.19' => str_contains($version, 'RS Connect 36.36.19'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nResumo UI pré-agendamento: " . count($checks) . "/" . count($checks) . " verificações aprovadas.\n";
