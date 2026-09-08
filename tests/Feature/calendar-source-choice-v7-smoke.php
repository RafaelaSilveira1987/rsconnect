<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CalendarAvailabilityController.php');
$view = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$lifecycle = (string) file_get_contents($root . '/app/Services/CalendarGoogleLifecycleService.php');
$prompt = (string) file_get_contents($root . '/app/Services/PromptStudioService.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($view, 'name="calendar_source" value="internal"'), 'Tela deve permitir escolher Agenda interna.');
$assert(str_contains($view, 'name="calendar_source" value="google"'), 'Tela deve permitir escolher Google Agenda.');
$assert(str_contains($view, 'name="calendar_source" value="none"'), 'Tela deve permitir desativar agenda.');
$assert(str_contains($view, 'Agenda interna do RS Connect'), 'Tela deve explicar a Agenda interna.');
$assert(str_contains($view, 'internal_days[]'), 'Agenda interna deve permitir configurar dias por semana.');
$assert(str_contains($view, 'internal_start[') && str_contains($view, 'internal_end['), 'Agenda interna deve permitir horários por dia.');

$assert(str_contains($controller, 'applyCalendarSourceChoice'), 'Controller deve persistir a origem escolhida.');
$assert(str_contains($service, 'public function calendarSourceSettings'), 'Serviço deve resolver a origem ativa.');
$assert(str_contains($service, 'public function applyCalendarSourceChoice'), 'Serviço deve salvar a origem ativa.');
$assert(str_contains($service, "if (\$calendarSource === 'internal')"), 'Runtime deve possuir ramo explícito para Agenda interna.');
$assert(str_contains($service, "'google_used' => false") && str_contains($service, "'n8n_used' => false"), 'Busca interna deve registrar que não usou Google/n8n.');
$assert(str_contains($service, "'calendar_source' => 'internal'"), 'Resultado interno deve ser identificado no diagnóstico.');
$assert(str_contains($service, "use_n8n = 0") && str_contains($service, "use_internal_fallback = 1"), 'Escolha interna deve desligar transporte n8n e ativar motor local.');
$assert(str_contains($service, "use_n8n = 1"), 'Escolha Google deve reativar n8n sem apagar credenciais.');
$assert(str_contains($service, 'tenant_onboarding_settings') && str_contains($service, 'calendar_mode'), 'Escolha deve sincronizar o modo usado por prompt/onboarding.');
$assert(str_contains($lifecycle, "\$calendarSource !== 'google'"), 'Ciclo Google não deve criar eventos quando Agenda interna estiver ativa.');
$assert(str_contains($prompt, "calendar_mode"), 'Prompt Studio continua consumindo o modo de agenda sincronizado.');

$assert(!str_contains($service, 'SET enabled = 1,\n                     availability_mode = "free_slots",\n                     use_n8n = 0'), 'Trocar para Agenda interna não deve destruir o modo Google salvo.');

echo "OK - escolha Agenda interna / Google / sem agenda e isolamento do Google validados.\n";
