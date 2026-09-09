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
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); }
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentPolicyEngineService;

$engine = new AgentPolicyEngineService();
$profile = [
    'status' => 'active',
    'capabilities' => [
        'triage.enabled' => true,
        'eligibility.enabled' => true,
        'calendar.read' => true,
        'calendar.pre_schedule' => true,
    ],
    'triage_fields' => [],
    'policies' => [[
        'policy_key' => 'minimum_age',
        'policy_type' => 'number',
        'value' => 14,
        'action_key' => 'block_schedule',
        'customer_message' => 'Atendimento somente a partir de {{minimum_age}} anos.',
        'enabled' => 1,
    ]],
];

$checks = [];

$conversation = $engine->evaluate($profile, ['patient_age' => 8], 'conversation');
$checks['idade abaixo do limite não bloqueia a conversa'] = !empty($conversation['allowed'])
    && ($conversation['decision'] ?? '') === 'warn'
    && ($conversation['code'] ?? '') === 'minimum_age';
$checks['aviso mantém escopo somente de agenda'] = (($conversation['evidence']['restriction_scope'] ?? '') === 'calendar')
    && (($conversation['evidence']['action_key'] ?? '') === 'block_schedule');
$checks['mensagem configurada continua disponível'] = str_contains((string) ($conversation['message'] ?? ''), '14');

$calendar = $engine->evaluate($profile, ['patient_age' => 8], 'calendar.pre_schedule');
$checks['idade abaixo do limite continua bloqueando a agenda'] = empty($calendar['allowed'])
    && ($calendar['decision'] ?? '') === 'block'
    && ($calendar['code'] ?? '') === 'minimum_age';
$checks['bloqueio técnico também carrega escopo de agenda'] = (($calendar['evidence']['restriction_scope'] ?? '') === 'calendar');

$root = dirname(__DIR__, 2);
$triageSource = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$settingsSource = (string) file_get_contents($root . '/app/Views/companies/settings.php');
$controllerSource = (string) file_get_contents($root . '/app/Controllers/CompanyController.php');

$checks['restrição de agenda encerra intenção mas não a conversa'] = str_contains($triageSource, "$" . "lastIntent = 'conversation';")
    && str_contains($triageSource, "conversation_continues")
    && str_contains($triageSource, "$" . "result['conversation_continues'] = true;");
$checks['restrição é informada uma única vez por conversa'] = str_contains($triageSource, 'hasPriorPolicyDecision')
    && str_contains($triageSource, 'agent.policy.calendar_restricted');
$checks['interface explica que a conversa continua'] = str_contains($settingsSource, 'Não permitir agenda (a conversa continua)')
    && str_contains($settingsSource, 'continua a conversa, mas não consulta nem reserva horário');
$checks['histórico recebe evidência para diferenciar agenda de conversa'] = str_contains($controllerSource, 'd.evidence_json')
    && str_contains($settingsSource, 'Agenda não liberada');

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
}
if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - regras com ação 'Não permitir agenda' informam o cliente sem bloquear a conversa.\n";
