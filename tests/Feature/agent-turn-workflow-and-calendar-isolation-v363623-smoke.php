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
use App\Services\CalendarConversationService;

$fail = [];
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $fail[] = $label;
};

$profile = [
    'status' => 'active',
    'interaction_mode' => 'hybrid',
    'config' => [
        'conversation_behavior' => [
            'demand' => ['enabled'=>true,'required_before_schedule'=>true,'prompt'=>'Conte brevemente o motivo do atendimento.'],
        ],
    ],
    'capabilities' => ['triage.enabled'=>true,'eligibility.enabled'=>true,'calendar.pre_schedule'=>true],
    'policies' => [],
    'triage_fields' => [
        ['field_key'=>'is_for_self','label'=>'Quem será atendido','field_type'=>'boolean','prompt_text'=>'O atendimento seria para você mesmo?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'patient_age','label'=>'Idade','field_type'=>'number','prompt_text'=>'Qual é a idade da pessoa que será atendida?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'brief_demand','label'=>'Demanda','field_type'=>'textarea','prompt_text'=>'Conte brevemente o motivo do atendimento.','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'modality','label'=>'Modalidade','field_type'=>'select','prompt_text'=>'Você prefere atendimento online ou presencial?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'preferred_schedule','label'=>'Preferência','field_type'=>'text','prompt_text'=>'Qual dia e período você prefere?','active'=>1,'required_before_schedule'=>0,'required_for_completion'=>1],
    ],
];

$triage = new AgentTriageService();
$state = ['collected'=>[], 'last_intent'=>'conversation', 'status'=>'collecting'];
$r1 = $triage->simulateTurn($profile, $state, 'Gostaria de saber como funciona o atendimento');
$check(($r1['state']['current_field_key'] ?? '') === 'is_for_self', 'turno informativo mantém cursor na primeira etapa configurada');
$check(($r1['state']['last_intent'] ?? '') === 'conversation', 'pergunta informativa não vira agenda');

$r2 = $triage->simulateTurn($profile, $r1['state'], 'Sim, estou passando por uma perda muito importante');
$check(($r2['state']['current_field_key'] ?? '') === 'patient_age', 'resposta da primeira etapa avança somente para idade');
$check(($r2['state']['collected']['is_for_self'] ?? null) === true, 'sim contextual preenche quem será atendido');
$check(empty($r2['state']['collected']['brief_demand'] ?? null), 'texto emocional não pula a ordem para preencher demanda');

$calendar = new CalendarConversationService();
$method = new ReflectionMethod($calendar, 'confirmationDecision');
$method->setAccessible(true);
$check($method->invoke($calendar, 'sim') === 'affirmative', 'confirmação curta isolada continua reconhecida');
$check($method->invoke($calendar, 'Sim, pode confirmar esse horário') === 'affirmative', 'confirmação explícita de agenda continua reconhecida');
$check($method->invoke($calendar, 'Sim, estou passando por uma perda muito importante') === '', 'sim em resposta de triagem não é confundido com confirmação de agenda');

$calendarSource = (string) file_get_contents($root = dirname(__DIR__, 2) . '/app/Services/CalendarConversationService.php');
$check(!str_contains($calendarSource, 'AND (a.conversation_id = :conversation_id OR a.contact_id = :contact_id)'), 'seleção/confirmação não reaproveita pré-agendamento de outra conversa pelo contato');

$aiSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/AiModelService.php');
$check(str_contains($aiSource, 'Pergunta configurada para a próxima informação'), 'prompt recebe a pergunta configurada da etapa atual');
$check(str_contains($aiSource, "avance SOMENTE para o campo indicado"), 'IA recebe trava explícita para não saltar etapas');

if ($fail !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "OK - ordem por turno e isolamento da agenda validados.\n";
