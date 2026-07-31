<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/ProfessionalCalendarService.php');
$availability = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$calendar = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$preSchedule = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$view = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$migration = (string) file_get_contents($root . '/database/migrations/065_professional_calendar_profiles_compat.sql');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'recurso opcional por empresa' => str_contains($migration, 'professional_calendar_enabled TINYINT(1) NOT NULL DEFAULT 0'),
    'automação da conversa desligada por padrão' => str_contains($migration, 'professional_calendar_auto_from_conversation TINYINT(1) NOT NULL DEFAULT 0'),
    'perfil individual criado' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS user_calendar_profiles'),
    'busca resolve perfil do compromisso' => str_contains($availability, 'contextForAppointment($tenantId, $appointment, $settings)'),
    'conflito filtra profissional' => str_contains($service, 'AND owner_user_id = :owner_user_id'),
    'troca de profissional bloqueia conflito' => str_contains($calendar, 'Escolha outro profissional ou horário.'),
    'fuso individual é validado' => str_contains($service, 'Informe um fuso horário válido'),
    'pré-agendamento só reaproveita conversa quando opt-in' => str_contains($preSchedule, "!empty(\$professionalCalendarSettings['auto_from_conversation'])"),
    'troca de profissional disponível' => str_contains($calendar, 'public function updateOwner(): void'),
    'rotas de perfil disponíveis' => str_contains($routes, '/calendar/availability/professional-profile'),
    'tela possui agenda individual' => str_contains($view, 'Agenda por profissional'),
    'versão atualizada' => str_contains($version, '36.10.7'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - agenda opcional por profissional com seleção manual e horários individuais.\n";
