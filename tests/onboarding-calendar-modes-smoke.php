<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$migration = (string) file_get_contents($root . '/database/migrations/061_onboarding_calendar_modes.sql');
$onboarding = (string) file_get_contents($root . '/app/Services/OnboardingGuideService.php');
$availability = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$view = (string) file_get_contents($root . '/app/Views/onboarding/index.php');
$company = (string) file_get_contents($root . '/app/Views/companies/settings.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CompanyController.php');
$appVersion = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$assert(str_contains($migration, "ENUM('none','internal','smart')"), 'Migration deve registrar os três modos de agenda.');
$assert(str_contains($migration, "ENUM('locked','configuring','ready')"), 'Migration deve registrar o estado de liberação técnica.');
$assert(str_contains($onboarding, "if (\$mode === 'smart'"), 'Backend deve validar a escolha da Agenda inteligente.');
$assert(str_contains($onboarding, "smart_calendar_status'] ?? 'locked') !== 'ready'"), 'Cliente não pode ativar integração sem homologação da RS.');
$assert(str_contains($onboarding, 'configureInternalMode'), 'Agenda interna deve ser configurada explicitamente.');
$assert(str_contains($onboarding, 'configureSmartMode'), 'Agenda inteligente deve ser ativada explicitamente.');
$assert(str_contains($availability, "'use_n8n' => 0"), 'Agenda interna deve desligar n8n.');
$assert(str_contains($availability, "'create_google_event_on_confirm' => 0"), 'Agenda interna não deve criar evento no Google.');
$assert(str_contains($availability, "'by_day' => \$byDay"), 'Agenda interna deve armazenar horários por dia.');
$assert(str_contains($availability, 'Horários disponíveis gerados pela Agenda interna do RS Connect.'), 'Resposta interna não deve mencionar falha do n8n.');
$assert(str_contains($view, 'Agenda interna do RS Connect'), 'Onboarding deve mostrar Agenda interna.');
$assert(str_contains($view, 'Agenda inteligente integrada'), 'Onboarding deve mostrar Agenda inteligente.');
$assert(str_contains($view, 'Não utilizar agenda'), 'Onboarding deve permitir dispensar agenda.');
$assert(str_contains($view, 'data-calendar-mode-panel="internal"'), 'Configuração interna deve ser exibida condicionalmente.');
$assert(str_contains($company, 'Situação da Agenda inteligente'), 'Super Admin deve controlar a liberação na empresa.');
$assert(str_contains($controller, 'saveSmartCalendarAccess'), 'A liberação deve ser salva pelo backend do Super Admin.');
$assert(str_contains($appVersion, '061_onboarding_calendar_modes.sql'), 'Painel de versão deve exigir a migration 061.');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - modos de agenda, Agenda interna e liberação pelo Super Admin validados.\n";
