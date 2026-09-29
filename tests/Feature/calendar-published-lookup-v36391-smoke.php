<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); } }
if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $start) : substr($value, $start, $length); } }

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentConversationBehaviorService;

$root = dirname(__DIR__, 2);
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$availability = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$professional = (string) file_get_contents($root . '/app/Services/ProfessionalCalendarService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CalendarAvailabilityController.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$behavior = new AgentConversationBehaviorService();
$settings = [
    'modalities' => [
        'online' => ['enabled' => true, 'allowed_days' => ['mon']],
    ],
];
$appointment = ['appointment_modality' => 'online', 'timezone' => 'America/Sao_Paulo'];
$published = [[
    'starts_at' => '2026-09-30 09:00:00',
    'ends_at' => '2026-09-30 09:50:00',
    'source' => 'internal_published',
]];
$calculated = [[
    'starts_at' => '2026-09-30 09:00:00',
    'ends_at' => '2026-09-30 09:50:00',
    'source' => 'internal_fallback',
]];

$checks = [
    'consulta ampla limpa horário exato anterior' => str_contains($pre, '($availabilityInquiry ? null')
        && str_contains($pre, 'não pode herdar um') && str_contains($pre, 'horário exato de uma tentativa anterior'),
    'preferência não confirmada não bloqueia contato na busca' => !str_contains($availability, 'COALESCE(preferred_day_text, "") <> ""\n                                        AND COALESCE(preferred_time_text, "") <> ""')
        && str_contains($availability, 'availability_selection_expires_at IS NULL OR availability_selection_expires_at >= NOW()'),
    'verificação por profissional também ignora preferência sem slot' => !str_contains($professional, 'COALESCE(a.preferred_day_text, "") <> ""\n                                        AND COALESCE(a.preferred_time_text, "") <> ""')
        && str_contains($professional, 'a.availability_selection_expires_at IS NULL OR a.availability_selection_expires_at >= NOW()'),
    'vaga publicada prevalece sobre dia genérico da modalidade' => count($behavior->filterSlotsWithSettings($settings, $appointment, $published)) === 1,
    'vaga calculada continua respeitando dia genérico da modalidade' => count($behavior->filterSlotsWithSettings($settings, $appointment, $calculated)) === 0,
    'pré-agendamento IA descobre profissional pelo slot publicado' => str_contains($availability, '$publishedDiscoveryUnbound')
        && str_contains($availability, '$publishedDiscoveryUnbound ? 0 :'),
    'publicar vaga ativa estratégia publicada na agenda interna' => str_contains($controller, 'activatePublishedInternalStrategy')
        && str_contains($availability, 'internal_availability_strategy = "published"'),
    'versão identifica 36.39.1 sem migration nova' => str_contains($version, 'RS Connect 36.39.1')
        && str_contains($version, "REQUIRED_MIGRATION = '122_calendar_client_communications.sql'"),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - 36.39.1 usa horários publicados como fonte determinística da Agenda interna.\n";
