<?php

declare(strict_types=1);

if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $start) : substr($value, $start, $length); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); }
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentTriageService;

$root = dirname(__DIR__, 2);
$fail = [];
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $fail[] = $label;
};

$profile = [
    'status' => 'active',
    'interaction_mode' => 'hybrid',
    'config' => ['conversation_behavior' => [
        'service_mode' => ['mode' => 'choice', 'fixed_modality' => 'presencial'],
        'modalities' => ['online' => ['enabled' => true], 'presencial' => ['enabled' => true]],
    ]],
    'capabilities' => ['triage.enabled' => true],
    'triage_fields' => [
        ['field_key' => 'is_for_self', 'label' => 'Atendimento é para a própria pessoa?', 'field_type' => 'boolean', 'active' => 1, 'required_for_completion' => 1],
        ['field_key' => 'patient_name', 'label' => 'Nome da pessoa que será atendida', 'field_type' => 'text', 'active' => 1, 'required_for_completion' => 1],
        ['field_key' => 'patient_age', 'label' => 'Idade', 'field_type' => 'number', 'active' => 1, 'required_for_completion' => 1],
        ['field_key' => 'modality', 'label' => 'Modalidade', 'field_type' => 'select', 'active' => 1, 'required_for_completion' => 1],
        ['field_key' => 'custom_demanda', 'label' => 'Demanda', 'field_type' => 'text', 'prompt_text' => 'O que levou você a buscar atendimento?', 'active' => 1, 'required_for_completion' => 1],
        ['field_key' => 'preferred_schedule', 'label' => 'Preferência de agenda', 'field_type' => 'text', 'active' => 1, 'required_for_completion' => 1],
    ],
];

$triage = new AgentTriageService();
$burst = $triage->simulateMessageBurst(
    $profile,
    ['collected' => ['is_for_self' => false, 'relationship' => 'filha'], 'current_field_key' => 'patient_name'],
    ['Cris', 'ela tem 19 anos', 'e está sofrendo muito com a perda do avô']
);

$check(($burst['collected']['patient_name'] ?? '') === 'Cris', 'nome atual substitui qualquer beneficiário antigo');
$check(($burst['collected']['patient_age'] ?? null) === 19, 'idade enviada no mesmo burst é preservada');
$check(str_contains((string) ($burst['collected']['custom_demanda'] ?? ''), 'perda do avô'), 'resposta antecipada e inequívoca de campo futuro é preservada');
$check(($burst['current_field_key'] ?? '') === 'modality', 'ordem do atendimento continua exigindo modalidade antes de avançar');

$next = $triage->simulateTurn($profile, [
    'collected' => $burst['collected'],
    'current_field_key' => 'modality',
], 'prefiro online');
$check(($next['state']['collected']['modality'] ?? '') === 'online', 'modalidade atual é registrada');
$check(($next['state']['current_field_key'] ?? '') === 'preferred_schedule', 'demanda já informada não é perguntada novamente depois da modalidade');

$contextBuilder = (string) file_get_contents($root . '/app/Services/AiContextBuilder.php');
$lifecycle = (string) file_get_contents($root . '/app/Services/ConversationLifecycleService.php');
$model = (string) file_get_contents($root . '/app/Services/AiModelService.php');
$reset = (string) file_get_contents($root . '/bin/reset-test-conversation.php');

$check(str_contains($contextBuilder, 'hasActiveStructuredTriage') && str_contains($contextBuilder, '$memory = null;'), 'memória anterior é suspensa durante coleta estruturada ativa');
$check(str_contains($lifecycle, "'conversation_ai_memory'"), 'novo ciclo limpa memória progressiva da conversa anterior');
$check(str_contains($model, 'Não use nome de beneficiário/paciente vindo do histórico ou da memória de outro atendimento.'), 'prompt protege identidade atual contra nomes de ciclos anteriores');
$check(str_contains($reset, '--purge-messages') && str_contains($reset, 'contact_ai_memory'), 'utilitário de homologação permite limpeza explícita e limitada do contato de teste');

$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$check(str_contains($version, 'RS Connect 36.40.1'), 'pacote identifica a versão 36.40.1');

if ($fail !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "OK - isolamento de ciclo, identidade e captura antecipada conferidos.\n";
