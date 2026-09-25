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

$root = dirname(__DIR__, 2);
$fail = [];
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $fail[] = $label;
};

$baseProfile = [
    'status' => 'active',
    'config' => ['conversation_behavior' => [
        'demand' => ['enabled'=>true,'required_before_schedule'=>true,'prompt'=>'Conte brevemente o motivo do atendimento.'],
        'response_delivery' => ['mode'=>'auto','max_blocks'=>3,'scope'=>'asked_only'],
    ]],
    'capabilities' => ['triage.enabled'=>true,'eligibility.enabled'=>true,'calendar.pre_schedule'=>true],
    'policies' => [],
    'triage_fields' => [
        ['field_key'=>'is_for_self','label'=>'Quem será atendido','field_type'=>'boolean','prompt_text'=>'O atendimento é para você?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'patient_name','label'=>'Nome','field_type'=>'text','prompt_text'=>'Qual é o nome da pessoa que será atendida?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'patient_age','label'=>'Idade','field_type'=>'number','prompt_text'=>'Qual é a idade da pessoa que será atendida?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'brief_demand','label'=>'Demanda','field_type'=>'textarea','prompt_text'=>'Tem acontecido alguma coisa que fez você perceber que seria importante buscar atendimento?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'modality','label'=>'Modalidade','field_type'=>'select','prompt_text'=>'Você prefere atendimento online ou presencial?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
    ],
];

$triage = new AgentTriageService();

$natural = $baseProfile;
$natural['interaction_mode'] = 'hybrid';
$state = ['collected'=>[], 'last_intent'=>'conversation', 'status'=>'collecting'];
$r1 = $triage->simulateTurn($natural, $state, 'Quero agendar para minha filha');
$check(!empty($r1['should_use_ai']), 'modo Natural com regras usa a IA para redigir a pergunta obrigatória');
$check(($r1['decision']['code'] ?? '') === 'triage_incomplete', 'agenda continua bloqueada enquanto falta a etapa configurada');
$check(($r1['state']['current_field_key'] ?? '') === 'patient_name' || ($r1['state']['current_field_key'] ?? '') === 'is_for_self', 'backend continua escolhendo a próxima etapa da ordem');

$form = $baseProfile;
$form['interaction_mode'] = 'form';
$r2 = $triage->simulateTurn($form, $state, 'Quero agendar para minha filha');
$check(empty($r2['should_use_ai']), 'modo de perguntas exatas preserva o envio literal quando escolhido');

$behavior = new AgentConversationBehaviorService();
$check($behavior->hasInformationalQuestion('Quero saber mais sobre o atendimento'), '"quero saber mais" é reconhecido como pedido informativo');
$check($behavior->hasInformationalQuestion('Pode me explicar melhor como funciona?'), 'pedido de explicação é reconhecido como informativo');

$behaviorPrompt = $behavior->promptBlock($natural, 'Ela está muito ansiosa por causa da faculdade');
$check(str_contains($behaviorPrompt, 'objetivo da etapa, não uma resposta pronta'), 'prompt operacional trata pergunta cadastrada como objetivo, não texto travado');

$modelSource = (string) file_get_contents($root . '/app/Services/AiModelService.php');
$check(str_contains($modelSource, 'Um pedido informativo não precisa terminar com ponto de interrogação'), 'prompt central prioriza pedidos informativos mesmo sem interrogação');
$check(str_contains($modelSource, 'nunca devolva apenas a pergunta se o turno atual trouxer contexto, relato emocional ou informação'), 'prompt central exige transição humana antes da próxima pergunta');
$check(!str_contains($modelSource, 'Perguntar como funciona a terapia não equivale'), 'motor central não mantém regra específica de nicho para terapia');

$view = (string) file_get_contents($root . '/app/Views/agents/index.php');
$check(str_contains($view, 'Perguntas exatamente como cadastradas'), 'tela diferencia claramente o modo literal do modo natural');

$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$check(str_contains($version, 'RS Connect 36.36.25 — Respostas naturais guiadas pelas regras'), 'pacote identifica a versão 36.36.25');

if ($fail !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "OK - redação natural orientada pelas regras validada.\n";
