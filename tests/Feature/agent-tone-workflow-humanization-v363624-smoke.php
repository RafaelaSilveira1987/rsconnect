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

use App\Services\AiModelService;
use App\Services\AgentConversationBehaviorService;
use App\Services\AgentTriageService;

$root = dirname(__DIR__, 2);
$fail = [];
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $fail[] = $label;
};

$view = (string) file_get_contents($root . '/app/Views/agents/index.php');
$controller = (string) file_get_contents($root . '/app/Controllers/AgentController.php');
$blueprint = (string) file_get_contents($root . '/app/Services/AgentBlueprintService.php');
$automation = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$check(str_contains($view, 'Tom do atendimento') && str_contains($view, 'Acolhedor e empático'), 'configuração do agente expõe tom simples e acolhedor');
$check(str_contains($controller, 'promptBuilderJsonWithToneFromPost') && str_contains($controller, 'prompt_builder_json = :prompt_builder_json'), 'tom é persistido de forma estruturada no agente');
$check(str_contains($view, 'Adicionar uma informação já cadastrada') && str_contains($view, 'Criar uma nova pergunta'), 'Ordem do atendimento permite ampliar o roteiro sem editar código');
$check(str_contains($blueprint, 'addExistingFieldToWorkflow') && str_contains($blueprint, 'addCustomCollectionStep'), 'backend cria etapas a partir da configuração do usuário');
$check(str_contains($blueprint, "'config_json' => json_encode(['field_keys' => [\$fieldKey]]"), 'nova etapa grava contrato executável no banco');
$check(str_contains($automation, '$conversationId') && str_contains($automation, 'enforceTurnScope'), 'validação final recebe a conversa para respeitar a etapa pendente');

$model = new AiModelService();
$toneMethod = new ReflectionMethod($model, 'conversationToneInstruction');
$toneMethod->setAccessible(true);
$tone = (string) $toneMethod->invoke($model, [
    'prompt_builder_json' => json_encode(['conversation_tone' => ['preset' => 'warm', 'custom' => 'Use frases naturais.']], JSON_UNESCAPED_UNICODE),
]);
$check(str_contains($tone, 'Acolhedor') && str_contains($tone, 'relatos emocionalmente relevantes') && str_contains($tone, 'Use frases naturais.'), 'preset acolhedor gera orientação de humanização e preserva complemento do cliente');

$behavior = new AgentConversationBehaviorService();
$stripMethod = new ReflectionMethod($behavior, 'stripPrematureCalendarActionClaims');
$stripMethod->setAccessible(true);
$filtered = (string) $stripMethod->invoke($behavior, 'Entendi. Vou registrar sua preferência e encaminhar para verificar a disponibilidade. Assim que confirmar, aviso você.');
$check($filtered === 'Entendi.', 'promessa prematura de consulta da agenda é removida antes do envio');

$aiSource = (string) file_get_contents($root . '/app/Services/AiModelService.php');
$check(str_contains($aiSource, 'se tiver vaga') && str_contains($aiSource, 'não registre isso como preferência completa'), 'interesse condicional sem dia/período não vira preferência de agenda');

$triage = new AgentTriageService();
$profile = [
    'status' => 'active',
    'interaction_mode' => 'hybrid',
    'config' => ['conversation_behavior' => ['demand' => ['enabled'=>true,'required_before_schedule'=>true,'prompt'=>'Conte brevemente o motivo.']]],
    'capabilities' => ['triage.enabled'=>true,'eligibility.enabled'=>true,'calendar.pre_schedule'=>true],
    'policies' => [],
    'triage_fields' => [
        ['field_key'=>'modality','label'=>'Modalidade','field_type'=>'select','prompt_text'=>'Você prefere online ou presencial?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'preferred_schedule','label'=>'Preferência','field_type'=>'text','prompt_text'=>'Qual dia e período ou horário ficam melhores para você?','active'=>1,'required_before_schedule'=>0,'required_for_completion'=>1],
    ],
];
$state = ['collected'=>[], 'current_field_key'=>'modality', 'last_intent'=>'conversation', 'status'=>'collecting'];
$turn = $triage->simulateTurn($profile, $state, 'Se tiver vaga, prefiro online');
$check(($turn['state']['collected']['modality'] ?? '') === 'online', 'resposta condicional registra somente a modalidade');
$check(empty($turn['state']['collected']['preferred_schedule'] ?? null), '"se tiver vaga" não é gravado como preferência de dia/período');
$check(($turn['state']['current_field_key'] ?? '') === 'preferred_schedule', 'após modalidade o fluxo aguarda a preferência configurada');
$check(str_contains($version, 'RS Connect 36.36.24'), 'pacote identifica a versão 36.36.24');
$check(str_contains($layout, 'app.css?v=36.36.24'), 'cache do CSS foi renovado');

if ($fail !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "OK - tom, humanização, fluxo configurável e trava de agenda validados.\n";
