<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $s, ?string $encoding = null): string { return strtolower($s); } }
if (!function_exists('mb_substr')) { function mb_substr(string $s, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($s, $start) : substr($s, $start, $length); } }
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\CalendarAvailabilityService;
use App\Services\CalendarConversationService;

$availability = new CalendarAvailabilityService();
$method = new ReflectionMethod($availability, 'searchWindow');
$settings = [
    'timezone' => 'America/Sao_Paulo',
    'min_notice_hours' => 0,
    'internal_availability_strategy' => 'calculated',
    '_calendar_source' => 'internal',
    'search_days_ahead' => 14,
];
$appointment = [
    'preferred_day_text' => '20/10/2026',
    'preferred_time_text' => '08:00',
    'starts_at' => '2026-10-20 08:00:00',
];
$window = $method->invoke($availability, $settings, $appointment);
assert($window['start'] === '2026-10-20 00:00:00');
assert($window['end'] === '2026-10-21 00:00:00');
$settings['_calendar_source'] = 'google';
$google = $method->invoke($availability, $settings, $appointment);
assert($google['start'] === '2026-10-20 08:00:00');

$conversation = new CalendarConversationService();
$format = new ReflectionMethod($conversation, 'formatOptions');
$slots = [];
foreach (['07:00', '08:00', '09:00', '10:00', '11:00', '12:00'] as $index => $time) {
    $slots[] = [
        'starts_at' => '2026-10-20 ' . $time . ':00',
        'modality' => 'presencial',
        'suggestion_position' => $index + 1,
        'raw_json' => json_encode(['remaining_capacity' => $index === 0 ? 3 : 1]),
    ];
}
$message = $format->invoke($conversation, $slots);
assert(str_contains($message, '07:00'));
assert(str_contains($message, '08:00'));
assert(str_contains($message, '12:00'));
assert(str_contains($message, '(3 vagas)'));
assert(!str_contains($message, 'Ocupado'));
$select = new ReflectionMethod($conversation, 'resolveSelection');
$selection = $select->invoke($conversation, '8 horas', $slots, ['appointment_modality' => 'presencial']);
assert(($selection['reason'] ?? '') === 'time');
assert(($selection['slot']['starts_at'] ?? '') === '2026-10-20 08:00:00');
$choice = $select->invoke($conversation, 'opção 5', $slots, ['appointment_modality' => 'presencial']);
assert(($choice['reason'] ?? '') === 'position');
assert(($choice['slot']['starts_at'] ?? '') === '2026-10-20 11:00:00');

$source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/CalendarConversationService.php');
assert(str_contains($source, '$max = 120;'));
assert(str_contains($source, '(\\d{1,3})'));
assert(str_contains($source, 'Não confundir'));
assert(substr_count($source, 'filterSlotsForAppointment($tenantId, $appointment, $slots)') >= 2);
echo "OK - janela diária integral, preservação da janela Google e grade numerada.\n";
