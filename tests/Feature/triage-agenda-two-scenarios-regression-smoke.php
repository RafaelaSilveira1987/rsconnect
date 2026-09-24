<?php

declare(strict_types=1);

// Regressões puras que não dependem de WhatsApp, MySQL ou tokens de IA.
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $text): string { return strtolower($text); }
}
if (!function_exists('mb_stripos')) {
    function mb_stripos(string $haystack, string $needle): int|false { return stripos($haystack, $needle); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $text): int { return strlen($text); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $text, int $start, ?int $length = null): string { return substr($text, $start, $length); }
}

$root = dirname(__DIR__, 2);
require $root . '/app/Services/FirstAutomatedReplyService.php';
require $root . '/app/Services/PreSchedulingService.php';
require $root . '/app/Services/CalendarConversationService.php';
require $root . '/app/Services/ConversationFlowService.php';

use App\Services\FirstAutomatedReplyService;
use App\Services\PreSchedulingService;
use App\Services\CalendarConversationService;
use App\Services\ConversationFlowService;

$failures = [];
$passes = 0;
$check = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $passes++ : $failures[] = $label;
};

$agent = ['name' => 'Rafa', 'ai_greeting_mode' => 'all_contacts'];
$first = FirstAutomatedReplyService::compose('O atendimento seria para você?', $agent, false);
$check(str_contains($first, 'Eu sou Rafa') && str_contains($first, 'O atendimento seria'), 'primeira resposta determinística apresenta a assistente antes da triagem');
$openingWithTemplate = FirstAutomatedReplyService::compose('Qual sua demanda?', ['name' => 'Rafa', 'ai_greeting_reply' => 'Olá {{nome}}'], false);
$check(!str_contains($openingWithTemplate, '{{') && str_contains($openingWithTemplate, 'Rafa'), 'saudação determinística não envia placeholders não resolvidos');
$check(FirstAutomatedReplyService::compose('O atendimento seria para você?', $agent, true) === 'O atendimento seria para você?', 'não repete apresentação depois da primeira resposta');
$check(FirstAutomatedReplyService::compose('O atendimento seria para você?', ['name' => 'Rafa', 'ai_greeting_mode' => 'disabled'], false) === 'O atendimento seria para você?', 'respeita a configuração de saudação desativada');
$check(str_contains(FirstAutomatedReplyService::compose('Rafaela quer atendimento.', $agent, false), 'Eu sou Rafa'), 'nome Rafaela do cliente não é confundido com nome Rafa da assistente');

$longAgent = [
    'name' => 'Rafa, Assistente da psicóloga Mariana Bernardes',
    'ai_greeting_mode' => 'all_contacts',
    'ai_greeting_reply' => 'Olá! Aqui é a Rafa, assistente do consultório da psicóloga Mariana Bernardes – CRP 04/62451. Em que posso ajudar?',
];
$longOpening = FirstAutomatedReplyService::compose('O atendimento seria para você mesmo?', $longAgent, false);
$check(substr_count($longOpening, 'Rafa') === 1 && !str_contains($longOpening, 'Eu sou Rafa'), 'saudação configurada que já apresenta Rafa não recebe uma segunda apresentação');
$longFallback = FirstAutomatedReplyService::compose('O atendimento seria para você mesmo?', [
    'name' => 'Rafa, Assistente da psicóloga Mariana Bernardes',
    'ai_greeting_mode' => 'all_contacts',
], false);
$check(str_contains($longFallback, 'Eu sou Rafa, assistente virtual.') && !str_contains($longFallback, 'Eu sou Rafa, Assistente da psicóloga'), 'fallback fala somente o nome curto quando o cadastro inclui função');
$alreadyIdentified = FirstAutomatedReplyService::compose('Olá! Aqui é a Rafa, assistente do consultório. Como posso ajudar?', $longAgent, false);
$check(substr_count($alreadyIdentified, 'Rafa') === 1, 'mensagem determinística já identificada não recebe prefixo duplicado');

$pre = new PreSchedulingService();
$plainQuestion = $pre->detectIntent('Quanto é? E como funciona?', true);
$check(empty($plainQuestion['has_intent']), 'pergunta normal após intenção antiga não reabre a agenda');
$dayPeriod = $pre->detectIntent('terça-feira, período da tarde', true);
$check(!empty($dayPeriod['has_intent']) && ($dayPeriod['preferred_day'] ?? '') === 'terça-feira' && ($dayPeriod['preferred_time'] ?? '') === 'tarde', 'dia e período são reconhecidos na continuação da triagem');
$check(empty($pre->detectIntent('terça-feira, período da tarde', false)['has_intent']), 'preferência sozinha exige contexto de agenda ativo');

$calendar = new CalendarConversationService();
$findExact = new ReflectionMethod($calendar, 'findExactRequestedSlot');
$slots = [['id' => 7, 'starts_at' => '2026-09-29 14:00:00']];
$periodAppointment = ['starts_at' => '2026-09-29 14:00:00', 'preferred_time_text' => 'tarde'];
$exactAppointment = ['starts_at' => '2026-09-29 14:00:00', 'preferred_time_text' => '14:00'];
$check($findExact->invoke($calendar, $periodAppointment, $slots) === null, '"terça à tarde" não vira escolha automática de 14h');
$check((int) ($findExact->invoke($calendar, $exactAppointment, $slots)['id'] ?? 0) === 7, '"terça às 14h" mantém escolha automática exata quando horário real estiver livre');

$flow = new ConversationFlowService();
$demandCandidate = new ReflectionMethod($flow, 'demandCandidate');
$check($demandCandidate->invoke($flow, 'Queria saber como funciona a terapia', 'queria saber como funciona a terapia') === '', 'pergunta sobre como funciona a terapia não é classificada como queixa');
$check($demandCandidate->invoke($flow, 'Estou com muita ansiedade', 'estou com muita ansiedade') !== '', 'queixa explícita de ansiedade continua reconhecida');

// A gravação da resposta curta pode ser verificada sem banco real por um PDO de
// gravação. Não valida o MySQL de produção; confere SQL e parâmetros usados.
class RecordingStatement extends PDOStatement
{
    public array $bound = [];
    public function __construct(public string $sql) {}
    public function execute(?array $params = null): bool { $this->bound = $params ?? []; return true; }
    public function rowCount(): int { return 1; }
}
class RecordingPDO extends PDO
{
    public ?RecordingStatement $recording = null;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->recording = new RecordingStatement($query);
        return $this->recording;
    }
}
$recordingPdo = new RecordingPDO();
$saved = $flow->recordStructuredDemand($recordingPdo, 11, 22, 'Ansiedade');
$check($saved && ($recordingPdo->recording?->bound['demand'] ?? '') === 'Ansiedade'
    && str_contains((string) ($recordingPdo->recording?->sql ?? ''), 'demand_status = "pending"'),
    'resposta curta "Ansiedade" gera atualização parametrizada somente da demanda pendente');
$check(!$flow->recordStructuredDemand($recordingPdo, 11, 22, ''), 'demanda vazia nunca é marcada como coletada');

$triageSrc = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$preSrc = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$flowSrc = (string) file_get_contents($root . '/app/Services/ConversationFlowService.php');
$availabilitySrc = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$check(str_contains($triageSrc, 'recordStructuredDemand(') && str_contains($flowSrc, 'demand_status = "collected"'), 'demanda curta coletada pela triagem é sincronizada ao estado de agendamento');
$check(str_contains($preSrc, 'hasCollectingTriageSchedule(') && str_contains($preSrc, '$this->hasAnyPreference($currentPreference)'), 'preferência atual retoma agenda somente se existir triagem de agenda ativa');
$check(str_contains($availabilitySrc, "'code' => 'period_preference'"), 'agenda interna não valida preferência de período como horário exato');

if ($failures !== []) {
    fwrite(STDERR, "Falhas: " . implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "OK: {$passes} verificações dos dois cenários.\n";
