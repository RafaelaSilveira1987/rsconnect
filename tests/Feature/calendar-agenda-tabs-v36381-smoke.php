<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$calendarView = (string) file_get_contents($root . '/app/Views/calendar/index.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CalendarAvailabilityController.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true);
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$checks = [
    'quatro abas operacionais foram criadas' => str_contains($view, "'overview' => ['Visão geral'")
        && str_contains($view, "'availability' => ['Disponibilidades'")
        && str_contains($view, "'preschedules' => ['Pré-agendamentos'")
        && str_contains($view, "'settings' => ['Configurações'"),
    'conteúdo é separado por aba no servidor' => str_contains($view, "if (\$activeTab === 'overview')")
        && str_contains($view, "if (\$activeTab === 'availability')")
        && str_contains($view, "if (\$activeTab === 'preschedules')")
        && str_contains($view, "if (\$activeTab === 'settings')"),
    'disponibilidades possuem publicação organizada e filtros' => str_contains($view, 'availability-publisher-panel')
        && str_contains($view, 'availability-filter-bar')
        && str_contains($view, 'availability_owner')
        && str_contains($view, 'availability_modality')
        && str_contains($view, 'availability_status')
        && str_contains($view, 'availability-day-group'),
    'configuração saiu da tela operacional' => str_contains($view, "if (\$activeTab === 'settings')")
        && str_contains($view, 'id="agenda-profissionais"')
        && str_contains($view, 'id="configuracoes-da-agenda"'),
    'pré-agendamentos e resultados ficam juntos' => str_contains($view, "if (\$activeTab === 'preschedules')")
        && str_contains($view, 'id="horarios-disponiveis"')
        && str_contains($view, 'Pré-agendamentos para validar'),
    'ações retornam para a aba correta' => str_contains($controller, 'tab=availability&tenant_id=')
        && str_contains($controller, 'tab=preschedules&tenant_id=')
        && str_contains($controller, 'tab=settings&tenant_id='),
    'agenda principal aponta resultados para pré-agendamentos' => str_contains($calendarView, 'tab=preschedules')
        && str_contains($calendarView, '<strong>Disponibilidade</strong>'),
    'layout novo possui responsividade' => str_contains($css, 'RS Connect 36.38.1')
        && str_contains($css, '.agenda-section-tabs')
        && str_contains($css, '.availability-slot-card')
        && str_contains($css, '@media (max-width: 600px)'),
    'release 36.38.1 permanece documentada após versões posteriores' => str_contains($version, 'RS Connect 36.38.1')
        && str_contains($css, 'RS Connect 36.38.1'),
    'assets continuam versionados no layout' => str_contains($layout, 'app.css?v=36.')
        && str_contains($layout, 'app.js?v=36.'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - v36.38.1 separa operação, disponibilidade e configuração sem alterar o contrato da Agenda interna.\n";
