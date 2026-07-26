<?php

declare(strict_types=1);

// Smoke test sem banco, focado nas decisoes puras que causaram os desvios da 36.6.14.
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}

require_once __DIR__ . '/../app/Services/AgentOperatingPolicyService.php';
require_once __DIR__ . '/../app/Services/ConversationFlowService.php';
require_once __DIR__ . '/../app/Services/PreSchedulingService.php';
require_once __DIR__ . '/../app/Services/AiModelService.php';

use App\Services\AgentOperatingPolicyService;
use App\Services\AiModelService;
use App\Services\ConversationFlowService;
use App\Services\PreSchedulingService;

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$agent = [
    'business_hours_enabled' => 1,
    'business_timezone' => 'America/Sao_Paulo',
    'business_hours_json' => json_encode([
        'mon' => [['08:00', '18:00']],
        'tue' => [['08:00', '18:00']],
        'wed' => [['08:00', '18:00']],
        'thu' => [['08:00', '18:00']],
        'fri' => [['08:00', '18:00']],
    ]),
];
$policy = new AgentOperatingPolicyService();
$assert(!$policy->allowsConversationalAutomation($agent, new DateTimeImmutable('2026-07-26 13:14:00', new DateTimeZone('America/Sao_Paulo'))), 'domingo deve bloquear automacao');
$assert($policy->allowsConversationalAutomation($agent, new DateTimeImmutable('2026-07-27 10:00:00', new DateTimeZone('America/Sao_Paulo'))), 'segunda 10h deve liberar automacao');
$agent['business_hours_enabled'] = 0;
$assert($policy->allowsConversationalAutomation($agent, new DateTimeImmutable('2026-07-26 13:14:00', new DateTimeZone('America/Sao_Paulo'))), 'horario desativado deve permitir 24h');

$pre = new PreSchedulingService();
$detect = new ReflectionMethod($pre, 'detectIntent');
$detect->setAccessible(true);
$cases = [
    ['Vou tentar configurar hoje a tarde/noite', false, false],
    ['Quero agendar amanha a tarde', false, true],
    ['Preciso de um horario amanha', false, true],
    ['Qual o horario de atendimento de voces?', false, false],
    ['Pode ser terca as 15h', false, false],
    ['Pode ser terca as 15h', true, true],
    ['Me confirma o horario de hoje?', false, true],
    ['Preciso das 10 maiores empresas em Cuiaba', false, false],
];
foreach ($cases as [$text, $continuation, $expected]) {
    $result = $detect->invoke($pre, $text, $continuation);
    $assert((bool) ($result['has_intent'] ?? false) === $expected, 'pre-agendamento: ' . $text . ' continuation=' . ($continuation ? '1' : '0'));
}

$flow = new ConversationFlowService();
$intent = new ReflectionMethod($flow, 'intent');
$intent->setAccessible(true);
$assert($intent->invoke($flow, 'Vou tentar configurar hoje a tarde/noite') === 'conversation', 'fluxo geral nao pode virar agenda por data casual');
$assert($intent->invoke($flow, 'Quero agendar amanha') === 'schedule', 'pedido explicito deve virar agenda');
$assert($intent->invoke($flow, 'Qual o horario de atendimento?') === 'conversation', 'horario de funcionamento nao e agenda');
$assert($intent->invoke($flow, 'Preciso das 10 maiores empresas em Cuiaba') === 'conversation', 'assunto comercial geral nao e agenda');

$model = new AiModelService();
$buildPrompt = new ReflectionMethod($model, 'buildSystemPrompt');
$buildPrompt->setAccessible(true);
$prompt = (string) $buildPrompt->invoke($model,
    ['id' => 1, 'system_prompt' => 'Atenda sites, automacoes e sistemas.', 'business_timezone' => 'America/Sao_Paulo'],
    ['name' => 'Cliente Teste', 'status' => 'customer', 'contact_group' => 'customer', 'tags_json' => json_encode(['cliente'])],
    ['tenant_id' => 0, 'contact_status' => 'customer', 'contact_group' => 'customer', 'flow_stage' => 'qualified', 'demand_status' => 'not_required', 'last_intent' => 'conversation']
);
$assert(!str_contains($prompt, 'Configurações de pré-agendamento do cliente:'), 'prompt geral nao deve receber bloco de agenda');
$assert(str_contains($prompt, 'NÃO está em contexto de agenda'), 'prompt geral deve conter trava anti-agenda');
$assert(str_contains($prompt, 'já é cliente/paciente da empresa'), 'classificacao Cliente deve chegar ao prompt como fonte de verdade');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - politica de horario, intencao de agenda, contexto geral e classificacao validados.\n";
