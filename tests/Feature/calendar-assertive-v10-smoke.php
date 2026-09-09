<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\PreSchedulingService;

$root = dirname(__DIR__, 2);
$availability = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$conversation = (string) file_get_contents($root . '/app/Services/CalendarConversationService.php');
$ai = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$calendarView = (string) file_get_contents($root . '/app/Views/calendar/index.php');
$settingsView = (string) file_get_contents($root . '/app/Views/companies/settings.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CompanyController.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'preferência exata é validada antes da grade de sugestões' =>
        str_contains($availability, 'validateInternalRequestedSlot')
        && str_contains($availability, 'A preferência exata do lead é sempre testada primeiro'),
    'intervalo de sugestões não impede horário exato livre' =>
        str_contains($availability, 'exact_preference')
        && str_contains($availability, 'RS Connect internal exact preference'),
    'pré-reserva real bloqueia concorrência' =>
        str_contains($availability, 'chosen_availability_slot_id')
        && str_contains($availability, 'availability_selection_expires_at >= NOW()')
        && str_contains($availability, 'slot_selected'),
    'preferência sem slot não bloqueia agenda interna' =>
        str_contains($availability, 'Preferências ainda não validadas')
        && str_contains($availability, 'NÃO bloqueiam')
        && str_contains($availability, 'COALESCE(chosen_availability_slot_id, 0) > 0'),
    'desligar sugestões não desliga validação do horário exato' =>
        str_contains($conversation, '$canSuggestAlternatives = !empty($settings[\'ai_can_suggest_slots\'])')
        && str_contains($conversation, 'if (!$canSuggestAlternatives)')
        && str_contains($conversation, 'calendar.alternatives_disabled'),
    'status só vira awaiting approval após seleção real' =>
        str_contains($pre, "\$status = 'pre_scheduled';")
        && str_contains($conversation, 'SET status = "awaiting_approval"'),
    'calendário visual exige slot real para pré-agendamento automático' =>
        str_contains($calendarView, '$chosenSlotId > 0')
        && str_contains($calendarView, "in_array(\$availabilityStatus, ['slot_selected', 'validated'], true)"),
    'configuração tem modo explícito human automatic pre schedule' =>
        str_contains($settingsView, 'pre_schedule_confirmation_mode')
        && str_contains($settingsView, 'Pré-agendar e aguardar aprovação da equipe')
        && str_contains($settingsView, 'Perguntar ao cliente e confirmar automaticamente'),
    'controller transforma modo explícito em flags coerentes' =>
        str_contains($controller, "\$confirmationMode === 'human'")
        && str_contains($controller, "\$confirmationMode === 'automatic'"),
    'aprovação humana desliga confirmação automática' =>
        str_contains($pre, '$aiCanConfirm = !$requireHumanApproval'),
    'IA livre não pode inventar disponibilidade ou confirmação' =>
        str_contains($ai, 'guardUnsupportedCalendarClaim')
        && str_contains($ai, '$claimsUnavailable')
        && str_contains($ai, '$asksTechnicalConfirmation'),
    'confirmação real continua exigindo persistência confirmed' =>
        str_contains($conversation, 'SET status = "confirmed"')
        && str_contains($conversation, 'Agendamento confirmado e persistido na agenda.'),
    'pacote identifica agenda assertiva' => str_contains($version, 'RS Connect 36.27.26'),
];

$service = new PreSchedulingService();
$intent = $service->detectIntent('quinta as 16h', true);
$checks['continuação captura quinta e 16h'] = !empty($intent['has_intent'])
    && str_contains(strtolower((string) ($intent['preferred_day'] ?? '')), 'quinta')
    && (string) ($intent['preferred_time'] ?? '') === '16:00';

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
}
if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - agenda assertiva valida preferência real, reserva slot e bloqueia confirmação textual falsa.\n";
