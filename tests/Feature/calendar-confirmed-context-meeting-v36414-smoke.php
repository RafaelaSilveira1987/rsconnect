<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$calendarView = (string) file_get_contents($root . '/app/Views/calendar/index.php');
$availabilityView = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$calendarJs = (string) file_get_contents($root . '/public/assets/js/app.js');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$preScheduling = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$availabilityService = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

$checks = [
    'contexto estruturado sobrevive à conversão do pré-agendamento' => str_contains($calendarView, '$showStructuredContext = $isPreSchedule || $hasPreScheduleContext($appointment)')
        && str_contains($calendarView, "'context_rows' => \$hasStructuredContext ? \$appointmentContextRows(\$appointment) : []")
        && str_contains($calendarView, 'Informações trazidas do pré-agendamento'),
    'modal do calendário renderiza dados estruturados e esconde descrição técnica' => str_contains($calendarView, 'data-calendar-dialog-context')
        && str_contains($calendarView, 'data-calendar-dialog-context-list')
        && str_contains($calendarJs, 'const hasStructuredContext = contextRows.length > 0')
        && str_contains($calendarJs, 'description.hidden = hasStructuredContext'),
    'novo pré-agendamento não duplica mensagem original na descrição' => !str_contains($preScheduling, "'Mensagem do lead: '")
        && str_contains($preScheduling, 'Informações estruturadas registradas para validação humana.'),
    'confirmação online aceita link antes do disparo ao cliente' => str_contains($availabilityView, 'Link da consulta online')
        && str_contains($availabilityView, 'name="meeting_url"')
        && str_contains($controller, "array_key_exists('meeting_url', \$_POST)")
        && str_contains($controller, 'antes da comunicação com o cliente'),
    'link pode ser editado no compromisso confirmado' => str_contains($routes, "'/calendar/meeting-link'")
        && str_contains($controller, 'public function updateMeetingLink(): void')
        && str_contains($calendarView, 'data-calendar-dialog-meeting-form')
        && str_contains($calendarView, 'Salvar link'),
    'link é validado como http ou https' => str_contains($controller, 'FILTER_VALIDATE_URL')
        && str_contains($controller, "in_array(\$scheme, ['http', 'https'], true)"),
    'mensagens automáticas expõem variável específica do link' => str_contains($preScheduling, "'{{link_consulta}}'")
        && str_contains($availabilityView, '<code>{{link_consulta}}</code>'),
    'confirmação de evento VAGO recebe meeting_url' => str_contains($availabilityService, "'meeting_url' => trim((string) (\$appointment['meeting_url'] ?? ''))"),
    'cache de CSS e JS foi renovado' => str_contains($layout, '/assets/css/app.css?v=36.41.4')
        && str_contains($layout, '/assets/js/app.js?v=36.41.4'),
    'versão atual é 36.41.4 sem migration nova' => str_contains($version, 'RS Connect 36.41.4')
        && ($manifest['package_version'] ?? '') === '36.41.4'
        && ($manifest['database']['required_migration'] ?? '') === '123_published_slots_min_notice_policy.sql',
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - 36.41.4 preserva o contexto do pré-agendamento e gerencia o link da consulta online.\n";
