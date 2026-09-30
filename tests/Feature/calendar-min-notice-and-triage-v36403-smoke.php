<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); } }
if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $start) : substr($value, $start, $length); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); } }

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentTriageService;
use App\Services\CalendarAvailabilityService;
use App\Services\CalendarConversationService;

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$tz = new DateTimeZone('America/Sao_Paulo');
$now = new DateTimeImmutable('now', $tz);
$tomorrow = $now->modify('+1 day');
$preferred = $tomorrow->format('Y-m-d') . ' 09:00:00';

$availability = new CalendarAvailabilityService();
$searchWindow = new ReflectionMethod(CalendarAvailabilityService::class, 'searchWindow');
$searchWindow->setAccessible(true);

$blocked = $searchWindow->invoke($availability, [
    'timezone' => 'America/Sao_Paulo',
    'min_notice_hours' => 48,
    'search_days_ahead' => 14,
    'internal_availability_strategy' => 'published',
    'published_slots_respect_min_notice' => 1,
], [
    'starts_at' => $preferred,
    'preferred_day_text' => 'quarta-feira',
    'preferred_time_text' => 'manhã',
]);
$check(($blocked['blocked_reason'] ?? '') === 'min_notice', 'período de amanhã é bloqueado por antecedência de 48h quando a política está ativa');
$check(substr((string) ($blocked['start'] ?? ''), 11) === '00:00:00', 'diagnóstico preserva início real do período solicitado');
$check(substr((string) ($blocked['end'] ?? ''), 11) === '12:00:00', 'diagnóstico preserva fim real da manhã em vez de colapsar start=end');

$override = $searchWindow->invoke($availability, [
    'timezone' => 'America/Sao_Paulo',
    'min_notice_hours' => 48,
    'search_days_ahead' => 14,
    'internal_availability_strategy' => 'published',
    'published_slots_respect_min_notice' => 0,
], [
    'starts_at' => $preferred,
    'preferred_day_text' => 'quarta-feira',
    'preferred_time_text' => 'manhã',
]);
$check(empty($override['blocked_reason']), 'vaga publicada pode ignorar antecedência quando a empresa desativa a trava');
$check(!empty($override['respect_min_notice']) === false, 'janela registra que antecedência foi dispensada para publicados');

$googleStillBlocked = $searchWindow->invoke($availability, [
    'timezone' => 'America/Sao_Paulo',
    'min_notice_hours' => 48,
    'search_days_ahead' => 14,
    'internal_availability_strategy' => 'published',
    'published_slots_respect_min_notice' => 0,
    '_calendar_source' => 'google',
], [
    'starts_at' => $preferred,
    'preferred_day_text' => 'quarta-feira',
    'preferred_time_text' => 'manhã',
]);
$check(($googleStillBlocked['blocked_reason'] ?? '') === 'min_notice', 'dispensa da antecedência vale somente para Agenda interna publicada, não para Google');

$conversation = new CalendarConversationService();
$constraint = new ReflectionMethod(CalendarConversationService::class, 'availabilityConstraintMessage');
$constraint->setAccessible(true);
$message = $constraint->invoke($conversation, [
    'requested_payload_json' => json_encode([
        'search' => [
            'blocked_reason' => 'min_notice',
            'min_notice_hours' => 48,
            'notice_start_at' => $now->modify('+48 hours')->format('Y-m-d H:i:s'),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);
$check(is_string($message) && str_contains($message, '48 hora(s)') && str_contains($message, 'a partir de'), 'WhatsApp explica a antecedência mínima em vez de afirmar agenda vazia');

$triage = new AgentTriageService();
$looksDemand = new ReflectionMethod(AgentTriageService::class, 'looksLikeDemandAnswer');
$looksDemand->setAccessible(true);
$check($looksDemand->invoke($triage, 'é melhor para ela', 'e melhor para ela') === false, 'frase de continuação não vira demanda');
$check($looksDemand->invoke($triage, 'Ela está com muita ansiedade depois de uma mudança de cidade', 'ela esta com muita ansiedade depois de uma mudanca de cidade') === true, 'relato substancial continua sendo aceito como demanda');

$customCapture = new ReflectionMethod(AgentTriageService::class, 'captureConfiguredFreeTextValue');
$customCapture->setAccessible(true);
$check($customCapture->invoke($triage, 'custom_objetivo', 'é melhor para ela', 'e melhor para ela', ['options' => []]) === null, 'frase de continuação também não preenche campo customizado genérico');

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$migration = (string) file_get_contents($root . '/database/migrations/123_published_slots_min_notice_policy.sql');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$check(str_contains($view, 'published_slots_respect_min_notice'), 'configuração de antecedência dos horários publicados aparece na Agenda');
$check(str_contains($migration, 'published_slots_respect_min_notice'), 'migration adiciona política por empresa');
$check(str_contains($version, 'RS Connect 36.40.3'), 'pacote identifica a versão 36.40.3');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - 36.40.3 trata antecedência publicada e evita preencher triagem com frases de continuação.\n";
