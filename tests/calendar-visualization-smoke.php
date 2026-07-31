<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$view = (string) file_get_contents($root . '/app/Views/calendar/index.php');
$javascript = (string) file_get_contents($root . '/public/assets/js/app.js');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$checks = [
    'quatro visualizações disponíveis' => str_contains($view, "['list' => 'Lista', 'day' => 'Dia', 'week' => 'Semana', 'month' => 'Mês']"),
    'semana padrão na agenda profissional' => str_contains($controller, "? 'week' : 'list'"),
    'período diário calculado' => str_contains($controller, "if (\$calendarView === 'day')"),
    'período semanal calculado' => str_contains($controller, "elseif (\$calendarView === 'week')"),
    'grade mensal completa' => str_contains($controller, "modify('first day of this month')")
        && str_contains($controller, "modify('last day of this month')"),
    'filtros preservados' => str_contains($view, 'calendarQueryBase')
        && str_contains($view, 'owner_user_id')
        && str_contains($view, 'status'),
    'detalhes em modal' => str_contains($view, 'data-calendar-event-dialog')
        && str_contains($javascript, 'openEvent'),
    'renderização diária' => str_contains($javascript, 'renderDay'),
    'renderização semanal' => str_contains($javascript, 'renderWeek'),
    'renderização mensal' => str_contains($javascript, 'renderMonth'),
    'preferência individual salva' => str_contains($controller, "'rs_calendar_view_' . (string) (Auth::id() ?? 0)")
        && str_contains($javascript, 'data-calendar-preference-key'),
    'layout responsivo' => str_contains($css, '.calendar-week-scroll')
        && str_contains($css, '.calendar-month-scroll')
        && str_contains($css, '@media (max-width: 560px)'),
    'cache renovado' => str_contains($layout, 'app.css?v=36.10.5')
        && str_contains($layout, 'app.js?v=36.10.5'),
    'versão atualizada' => str_contains($version, 'RS Connect 36.10.5'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - agenda com visualizações Lista, Dia, Semana e Mês, filtros e preferência individual.\n";
