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

use App\Services\AgentConversationBehaviorService;
use App\Services\AgentTriageService;
use App\Services\AiResponseContractService;

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
        'demand' => ['enabled'=>true,'required_before_schedule'=>true,'prompt'=>'Tem acontecido alguma coisa que fez você perceber que seria importante iniciar a psicoterapia?'],
        'response_delivery' => ['mode'=>'auto','max_blocks'=>3,'scope'=>'asked_only'],
    ]],
    'capabilities' => ['triage.enabled'=>true,'eligibility.enabled'=>true,'calendar.pre_schedule'=>true],
    'policies' => [],
    'triage_fields' => [
        ['field_key'=>'is_for_self','label'=>'Quem será atendido','field_type'=>'boolean','prompt_text'=>'O atendimento seria para você mesmo?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'patient_name','label'=>'Nome','field_type'=>'text','prompt_text'=>'Qual é o nome da pessoa que seria atendida?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'patient_age','label'=>'Idade','field_type'=>'number','prompt_text'=>'Qual é a idade da pessoa que seria atendida?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'brief_demand','label'=>'Demanda','field_type'=>'textarea','prompt_text'=>'Tem acontecido alguma coisa que fez você perceber que seria importante iniciar a psicoterapia?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'modality','label'=>'Modalidade','field_type'=>'select','prompt_text'=>'Você prefere atendimento online ou presencial?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
    ],
];

$triage = new AgentTriageService();
$burst = $triage->simulateMessageBurst(
    $profile,
    [
        'collected' => ['is_for_self' => false, 'relationship' => 'filha'],
        'current_field_key' => 'patient_name',
    ],
    [
        'Aline',
        'tem 19 anos',
        'Queria saber mais sobre o atendimento psicológico.',
        'Sim, ela está começando a faculdade e está muito ansiosa',
        'Será que a profissional consegue ajudar?',
    ]
);

$check(($burst['collected']['patient_name'] ?? '') === 'Aline', 'burst preserva o nome informado em balão separado');
$check(($burst['collected']['patient_age'] ?? null) === 19, 'burst avança e captura a idade no balão seguinte');
$check(str_contains((string) ($burst['collected']['brief_demand'] ?? ''), 'faculdade'), 'burst captura a demanda somente quando a etapa chega nela');
$check(($burst['current_field_key'] ?? '') === 'modality', 'pedido informativo posterior não reinicia nome/idade/demanda e mantém a próxima etapa correta');
$check(($burst['last_processed_incoming_id'] ?? 0) === 5, 'cursor do burst avança até a última mensagem consumida');

$behavior = new AgentConversationBehaviorService();
$check($behavior->hasInformationalQuestion('Será q a Mariana consegue ajudar?'), 'pergunta “consegue ajudar?” é classificada como informativa');

$contract = new AiResponseContractService();
$context = [
    'current_field_key' => 'modality',
    'collected' => $burst['collected'],
    'missing' => ['modality'],
];
$bad = $contract->validate(
    $profile,
    $context,
    'Será que a profissional consegue ajudar?',
    "Entendi.\n\nVou verificar com a profissional e te retorno assim que possível."
);
$check(empty($bad['ok']), 'contrato rejeita promessa de consulta humana inventada');
$check(in_array('unsupported_human_check', $bad['violations'] ?? [], true), 'validação identifica consulta humana inexistente');
$check(in_array('missing_pending_question', $bad['violations'] ?? [], true), 'validação identifica perda da próxima etapa obrigatória');

$good = $contract->validate(
    $profile,
    $context,
    'Será que a profissional consegue ajudar?',
    'Entendo a preocupação. Pelo que está cadastrado, o atendimento pode acolher essa demanda e a avaliação acontece ao longo das sessões, sem promessa de resultado. Para eu seguir com o atendimento da Aline, vocês preferem online ou presencial?'
);
$check(!empty($good['ok']), 'resposta informativa + próxima etapa natural passa no contrato');

$contractPrompt = $contract->promptBlock($profile, $context, 'Será que a profissional consegue ajudar?');
$check(str_contains($contractPrompt, 'REGRAS DO CONTRATO'), 'prompt recebe contrato explícito de runtime');
$check(str_contains($contractPrompt, 'NÃO anexe nem repita mecanicamente'), 'modo natural proíbe reinserção literal da pergunta');

$behaviorSource = (string) file_get_contents($root . '/app/Services/AgentConversationBehaviorService.php');
$check(str_contains($behaviorSource, "\$interactionMode === 'form' && !str_contains(\$reply, '?')"), 'pergunta literal é anexada somente no modo Formulário');

$automationSource = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$check(str_contains($automationSource, 'ai.generate.contract_repair'), 'backend executa uma segunda geração quando o contrato falha');
$check(str_contains($automationSource, 'AiResponseContractService'), 'validação ocorre antes do envio da resposta');

$triageSource = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$check(str_contains($triageSource, 'last_processed_incoming_id'), 'triagem persiste cursor por mensagem recebida');
$check(str_contains($triageSource, 'incomingTurnMessages'), 'runtime lê mensagens agrupadas individualmente');

$migration = (string) file_get_contents($root . '/database/migrations/120_agent_turn_state_cursor.sql');
$check(str_contains($migration, 'last_processed_incoming_id'), 'migration adiciona cursor de processamento ao estado da triagem');

$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$check(str_contains($version, 'RS Connect 36.36.26 — Motor de IA com contrato de resposta'), 'pacote identifica a versão 36.36.26');
$check(str_contains($version, "REQUIRED_MIGRATION = '120_agent_turn_state_cursor.sql'"), 'release exige a migration do cursor de turno');

if ($fail !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "OK - contrato de resposta, burst sequencial e validação do runtime conferidos.\n";
