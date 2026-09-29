<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$availabilityView = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$calendarView = (string) file_get_contents($root . '/app/Views/calendar/index.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true);
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$guest = (string) file_get_contents($root . '/app/Views/layouts/guest.php');

$labels = ['Compromissos', 'Visão geral', 'Disponibilidades', 'Pré-agendamentos', 'Configurações'];
$hasLabels = static function (string $view) use ($labels): bool {
    foreach ($labels as $label) {
        if (!str_contains($view, '<strong>' . $label . '</strong>')) return false;
    }
    return true;
};

$checks = [
    'agenda principal usa uma única navegação com cinco áreas' => substr_count($calendarView, '<nav class="agenda-main-tabs"') === 1
        && !str_contains($calendarView, '<nav class="agenda-unified-tabs"')
        && $hasLabels($calendarView),
    'disponibilidade usa uma única navegação com cinco áreas' => substr_count($availabilityView, '<nav class="agenda-main-tabs"') === 1
        && !str_contains($availabilityView, '<nav class="agenda-unified-tabs"')
        && !str_contains($availabilityView, '<nav class="agenda-section-tabs"')
        && $hasLabels($availabilityView),
    'cada área aponta para a rota correta' => str_contains($availabilityView, "\$tabUrl('overview')")
        && str_contains($availabilityView, "\$tabUrl('availability')")
        && str_contains($availabilityView, "\$tabUrl('preschedules')")
        && str_contains($availabilityView, "\$tabUrl('settings')")
        && str_contains($calendarView, 'section=availability&tab=overview')
        && str_contains($calendarView, 'section=availability&tab=settings'),
    'botão redundante de compromissos saiu do hero' => !str_contains($availabilityView, '>Ver compromissos</a>'),
    'abas são proporcionais em desktop e responsivas em telas menores' => str_contains($css, 'RS Connect 36.38.2')
        && str_contains($css, 'grid-template-columns: repeat(5, minmax(0, 1fr))')
        && str_contains($css, '.agenda-main-tabs')
        && str_contains($css, 'overflow-x: auto'),
    'versão foi atualizada sem nova migration' => ($manifest['package_version'] ?? '') === '36.38.2'
        && ($manifest['database']['required_migration'] ?? '') === '121_internal_calendar_published_slots.sql'
        && str_contains($version, 'RS Connect 36.38.2')
        && str_contains($version, "REQUIRED_MIGRATION = '121_internal_calendar_published_slots.sql'"),
    'cache visual foi invalidado' => str_contains($layout, 'app.css?v=36.38.2')
        && str_contains($layout, 'app.js?v=36.38.2')
        && str_contains($guest, 'app.css?v=36.38.2'),
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

echo "\nOK - v36.38.2 mantém uma única navegação proporcional na Agenda sem alterar disponibilidade ou banco.\n";
