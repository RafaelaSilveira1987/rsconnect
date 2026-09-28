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

use App\Services\CalendarAvailabilityService;
use App\Services\CalendarConversationService;
use App\Services\PreSchedulingService;

$root = dirname(__DIR__, 2);
$prePhp = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$availabilityPhp = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$conversationPhp = (string) file_get_contents($root . '/app/Services/CalendarConversationService.php');
$aiPhp = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$cachePhp = (string) file_get_contents($root . '/app/Services/AiExactCacheService.php');
$versionPhp = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [];

$pre = new PreSchedulingService();
$intent = $pre->detectIntent('quinta pela manhã, quais horários tem disponível?');
$checks['consulta de quinta pela manhã é intenção de agenda'] = !empty($intent['has_intent']);
$checks['consulta captura quinta-feira'] = (string) ($intent['preferred_day'] ?? '') === 'quinta-feira';
$checks['consulta captura período manhã'] = (string) ($intent['preferred_time'] ?? '') === 'manhã';

$availability = new CalendarAvailabilityService();
$searchWindow = new ReflectionMethod(CalendarAvailabilityService::class, 'searchWindow');
$searchWindow->setAccessible(true);
$settings = [
    'timezone' => 'America/Sao_Paulo',
    'min_notice_hours' => 0,
    'search_days_ahead' => 14,
];
$morning = $searchWindow->invoke($availability, $settings, [
    'starts_at' => '2099-10-01 09:00:00',
    'preferred_day_text' => 'quinta-feira',
    'preferred_time_text' => 'manhã',
]);
$checks['período manhã não vaza para outro dia'] =
    str_starts_with((string) ($morning['start'] ?? ''), '2099-10-01 ')
    && (string) ($morning['end'] ?? '') === '2099-10-01 12:00:00';
$checks['manhã inclui início do expediente em vez de fixar 09h'] = (string) ($morning['start'] ?? '') === '2099-10-01 00:00:00';

$dayOnly = $searchWindow->invoke($availability, $settings, [
    'starts_at' => '2099-10-01 09:00:00',
    'preferred_day_text' => 'quinta-feira',
    'preferred_time_text' => '',
]);
$checks['consulta só por dia fica limitada ao dia pedido'] =
    (string) ($dayOnly['start'] ?? '') === '2099-10-01 00:00:00'
    && (string) ($dayOnly['end'] ?? '') === '2099-10-02 00:00:00';

$exact = $searchWindow->invoke($availability, $settings, [
    'starts_at' => '2099-10-01 10:00:00',
    'preferred_day_text' => 'quinta-feira',
    'preferred_time_text' => '10:00',
]);
$checks['horário exato continua começando no horário pedido'] = (string) ($exact['start'] ?? '') === '2099-10-01 10:00:00';

$conversation = new CalendarConversationService();
$isBrowse = new ReflectionMethod(CalendarConversationService::class, 'isAvailabilityBrowseAppointment');
$isBrowse->setAccessible(true);
$checks['manhã é busca de opções, não horário exato'] = $isBrowse->invoke($conversation, ['preferred_time_text' => 'manhã']) === true;
$checks['10:00 continua sendo preferência exata'] = $isBrowse->invoke($conversation, ['preferred_time_text' => '10:00']) === false;

$checks['auto request não exige modalidade universalmente'] =
    str_contains($prePhp, '$modalityChoiceRequiredBeforeSchedule && !$this->isAvailabilityModality($modality)')
    && !str_contains($prePhp, "if (!\$this->isAvailabilityModality(\$modality)) {\n                return [\n                    'ok' => false,\n                    'skipped' => true,\n                    'code' => 'modality_required'");
$checks['prontidão de agenda respeita workflow de modalidade'] =
    str_contains($prePhp, '!$modalityChoiceRequiredBeforeSchedule || $this->isAvailabilityModality($effectiveModality)')
    && str_contains($prePhp, '!$modalityChoiceRequiredBeforeSchedule || $this->isAvailabilityModality($intentModality)');
$checks['modalidade única é normalizada antes da consulta'] =
    str_contains($prePhp, "(\$modalityPolicy['mode'] ?? '') === 'single'")
    && str_contains($prePhp, 'SET appointment_modality = :modality');
$checks['agenda interna tem escopo de período explícito'] =
    str_contains($availabilityPhp, 'normalizePeriodPreference')
    && str_contains($availabilityPhp, 'periodBounds')
    && str_contains($availabilityPhp, 'consultas da Agenda interna respeitam o ESCOPO');
$checks['mensagem de opções não chama período de horário solicitado'] =
    str_contains($conversationPhp, 'Só HH:MM é tratado como preferência exata');
$checks['guarda da IA pergunta modalidade apenas quando workflow exige'] =
    str_contains($aiPhp, '$modalityChoiceRequiredBeforeSchedule && !in_array($modality');
$checks['cache foi invalidado'] = str_contains($cachePhp, "'runtime_contract' => '36.37.3'");
$checks['pacote identifica versão 36.37.3'] = str_contains($versionPhp, 'RS Connect 36.37.3');

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - Agenda interna v36.37.3 respeita workflow, modalidade e período solicitado.\n";
