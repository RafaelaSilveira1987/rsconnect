<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); } }
if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $start) : substr($value, $start, $length); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); } }

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentTriageService;
use App\Services\CalendarConversationService;

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$calendar = new CalendarConversationService();
$constraint = new ReflectionMethod(CalendarConversationService::class, 'availabilityConstraintMessage');
$constraint->setAccessible(true);
$message = $constraint->invoke($calendar, [
    'requested_payload_json' => json_encode([
        'search' => [
            'blocked_reason' => 'min_notice',
            'min_notice_hours' => 24,
            'notice_start_at' => '2026-10-08 12:36:00',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);
$check(
    is_string($message)
        && str_contains($message, 'Infelizmente')
        && str_contains($message, 'pelo menos 24 horas de antecedência')
        && str_contains($message, 'não é possível agendar no período solicitado')
        && str_contains($message, 'Posso verificar horários a partir de'),
    'mensagem de antecedência é humana e explica que o período solicitado não pode ser agendado'
);

$profile = [
    'status' => 'active',
    'interaction_mode' => 'hybrid',
    'capabilities' => ['triage.enabled' => true],
    'policies' => [],
    'triage_fields' => [
        [
            'field_key' => 'custom_demanda',
            'label' => 'Demanda',
            'field_type' => 'text',
            'prompt_text' => 'Tem acontecido alguma coisa que fez você perceber que seria importante iniciar a psicoterapia?',
            'active' => 1,
            'required_for_completion' => 1,
        ],
        [
            'field_key' => 'patient_age',
            'label' => 'Idade da pessoa que será atendida',
            'field_type' => 'number',
            'prompt_text' => 'Qual é a sua idade?',
            'active' => 1,
            'required_for_completion' => 1,
        ],
        [
            'field_key' => 'preferred_schedule',
            'label' => 'Preferência de agenda',
            'field_type' => 'text',
            'prompt_text' => 'Você tem preferência por algum dia da semana e período?',
            'active' => 1,
            'required_for_completion' => 1,
        ],
    ],
];

$triage = new AgentTriageService();
$demandTurn = $triage->simulateTurn(
    $profile,
    ['collected' => [], 'current_field_key' => 'custom_demanda', 'status' => 'collecting'],
    'Sim, estou me sinto triste demais depois que perdi uma pessoa.'
);
$storedDemand = trim((string) ($demandTurn['collected']['custom_demanda'] ?? ''));
$check(str_contains(strtolower($storedDemand), 'perdi uma pessoa'), 'relato do contato é preservado no campo Demanda');
$check(($demandTurn['state']['current_field_key'] ?? '') === 'patient_age', 'depois da demanda o fluxo segue para idade');

$invalidAgeAsDemand = $triage->simulateTurn(
    $profile,
    ['collected' => [], 'current_field_key' => 'custom_demanda', 'status' => 'collecting'],
    '23'
);
$check(empty($invalidAgeAsDemand['collected']['custom_demanda'] ?? null), 'idade isolada não pode ser gravada como Demanda');
$check(($invalidAgeAsDemand['state']['current_field_key'] ?? '') === 'custom_demanda', 'Demanda continua pendente quando a resposta pertence a outro campo');

$ageTurn = $triage->simulateTurn($profile, (array) ($demandTurn['state'] ?? []), '23');
$check(($ageTurn['collected']['patient_age'] ?? null) === 23, 'idade continua sendo coletada normalmente');
$check(($ageTurn['state']['current_field_key'] ?? '') === 'preferred_schedule', 'depois da idade o fluxo segue para preferência');

$preferenceTurn = $triage->simulateTurn($profile, (array) ($ageTurn['state'] ?? []), 'Quinta feira pela manhã');
$check(str_contains(strtolower((string) ($preferenceTurn['collected']['custom_demanda'] ?? '')), 'perdi uma pessoa'), 'preferência de agenda não sobrescreve a demanda já coletada');
$check(str_contains(strtolower((string) ($preferenceTurn['collected']['preferred_schedule'] ?? '')), 'quinta'), 'quinta-feira pela manhã é gravada somente como preferência de agenda');

$staleState = [
    'collected' => [
        'custom_demanda' => 'Prefiro na quinta feira, na parte da manhã',
        'patient_age' => 23,
    ],
    'current_field_key' => 'preferred_schedule',
    'status' => 'collecting',
];
$repairTurn = $triage->simulateTurn($profile, $staleState, 'Quinta feira pela manhã');
$check(empty($repairTurn['collected']['custom_demanda'] ?? null), 'valor antigo de preferência salvo por engano em Demanda é descartado');
$check(($repairTurn['state']['current_field_key'] ?? '') === 'custom_demanda', 'com Demanda inválida removida, o fluxo volta a solicitar a informação correta');
$check(str_contains(strtolower((string) ($repairTurn['collected']['preferred_schedule'] ?? '')), 'quinta'), 'preferência continua preservada mesmo quando a Demanda precisa ser refeita');

$version = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/AppVersionService.php');
$check(str_contains($version, 'RS Connect 36.42.2'), 'pacote identifica a versão 36.42.2');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - 36.42.2 humaniza a antecedência e protege Demanda contra preferência de agenda.\n";
