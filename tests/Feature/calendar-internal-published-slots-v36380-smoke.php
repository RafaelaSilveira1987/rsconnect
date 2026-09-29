<?php

declare(strict_types=1);

if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $start) : substr($value, $start, $length); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); } }

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\InternalCalendarSlotService;

$root = dirname(__DIR__, 2);
$availability = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$slotService = (string) file_get_contents($root . '/app/Services/InternalCalendarSlotService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CalendarAvailabilityController.php');
$calendarController = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$view = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$manifest = (string) file_get_contents($root . '/database/migrations/manifest.php');
$migration = (string) file_get_contents($root . '/database/migrations/121_internal_calendar_published_slots.sql');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$cache = (string) file_get_contents($root . '/app/Services/AiExactCacheService.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');

$normalizer = new InternalCalendarSlotService();
$checks = [
    'estratégia antiga continua padrão' => $normalizer->normalizeStrategy('') === 'calculated'
        && str_contains($availability, "'internal_availability_strategy' => 'calculated'"),
    'modo publicado é opt-in explícito' => $normalizer->normalizeStrategy('published') === 'published'
        && str_contains($view, 'Oferecer somente horários liberados'),
    'migration adiciona estratégia sem migrar tenants' => str_contains($migration, "DEFAULT 'calculated'")
        && !str_contains($migration, "UPDATE tenant_calendar_availability_settings SET internal_availability_strategy = 'published'"),
    'migration cria tabela separada de disponibilidade' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS calendar_internal_slots')
        && str_contains($migration, 'hold_appointment_id')
        && str_contains($migration, 'hold_expires_at'),
    'manifest inclui migration 121' => str_contains($manifest, "121_internal_calendar_published_slots.sql"),
    'busca interna alterna entre calculada e publicada' => str_contains($availability, 'generatePublishedInternalSlots')
        && str_contains($availability, 'generateInternalSlots')
        && str_contains($availability, "? 'internal_published'")
        && str_contains($availability, ": 'internal_fallback'"),
    'modo publicado consulta somente tabela de vagas' => str_contains($slotService, 'status = "available"')
        && str_contains($slotService, 'calendar_internal_slots')
        && str_contains($slotService, 'availableForWindow'),
    'vagas conflitantes com compromissos são removidas' => str_contains($availability, 'overlapsBusy($start, $end, $busy, $buffer)')
        && str_contains($availability, 'RS Connect published availability'),
    'seleção faz hold concorrente da vaga' => str_contains($slotService, 'FOR UPDATE')
        && str_contains($slotService, 'status = "held"')
        && str_contains($availability, 'holdFromAvailabilitySlot'),
    'vaga de profissional pode atribuir responsável' => str_contains($availability, 'owner_user_id = :owner_user_id')
        && str_contains($availability, '$publishedOwnerId'),
    'confirmação e cancelamento atualizam vaga publicada' => str_contains($availability, "availability_source'] ?? '') === 'internal_published'")
        && str_contains($slotService, 'status = "booked"')
        && str_contains($slotService, 'releaseForAppointment'),
    'troca de profissional libera vaga publicada' => str_contains($calendarController, "availability_source'] ?? '') === 'internal_published'")
        && str_contains($calendarController, 'releaseSelectedSlot'),
    'painel permite publicar e remover vagas' => str_contains($controller, 'publishInternalSlots')
        && str_contains($controller, 'cancelInternalSlot')
        && str_contains($routes, '/calendar/availability/internal-slots/publish')
        && str_contains($routes, '/calendar/availability/internal-slots/cancel')
        && str_contains($view, 'id="horarios-liberados"'),
    'faixas podem ser repetidas semanalmente sem nova regra de agenda' => str_contains($slotService, 'repeat_weeks')
        && str_contains($view, 'Repetir no mesmo dia da semana'),
    'interface explica que vazio não é vaga' => str_contains($view, 'Um espaço vazio no calendário não será considerado disponível')
        && str_contains($view, 'não inventa horários entre essas opções'),
    'estilos do painel publicados' => str_contains($css, 'RS Connect 36.38.0')
        && str_contains($css, '.internal-strategy-grid'),
    'pacote exige migration nova' => str_contains($version, "REQUIRED_MIGRATION = '121_internal_calendar_published_slots.sql'"),
    'versão e cache foram atualizados' => str_contains($version, 'RS Connect 36.38.0')
        && str_contains($cache, "'runtime_contract' => '36.38.0'"),
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

echo "\nOK - v36.38.0 preserva o cálculo legado e adiciona disponibilidade publicada como fonte explícita da Agenda interna.\n";
