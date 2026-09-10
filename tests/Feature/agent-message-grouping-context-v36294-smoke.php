<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); } }
if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $start) : substr($value, $start, $length); } }
if (!function_exists('mb_stripos')) { function mb_stripos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false { return stripos($haystack, $needle, $offset); } }

require_once __DIR__ . '/../../app/Core/Env.php';
require_once __DIR__ . '/../../app/Services/ConversationFlowService.php';
require_once __DIR__ . '/../../app/Services/AiModelService.php';

use App\Services\AiModelService;

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/app/Controllers/AgentController.php');
$webhook = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$automation = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$context = (string) file_get_contents($root . '/app/Services/AiContextBuilder.php');
$view = (string) file_get_contents($root . '/app/Views/agents/index.php');
$migration = (string) file_get_contents($root . '/database/migrations/106_agent_message_grouping_context_priority.sql');
$manifest = (string) file_get_contents($root . '/database/migrations/manifest.php');

$assert(str_contains($migration, 'message_grouping_enabled'), 'migration deve criar flag de agrupamento');
$assert(str_contains($migration, 'prioritize_current_turn'), 'migration deve criar prioridade do turno atual');
$assert(str_contains($manifest, '106_agent_message_grouping_context_priority.sql'), 'migration 106 deve estar no manifesto');
$assert(str_contains($controller, 'message_grouping_enabled'), 'controller deve salvar agrupamento');
$assert(str_contains($controller, 'prioritize_current_turn'), 'controller deve salvar prioridade do turno');
$assert(str_contains($webhook, "message_grouping_enabled"), 'webhook deve respeitar agrupamento antes da retomada');
$assert(str_contains($automation, 'AiTurnContextService'), 'automacao deve reconstruir o turno atual');
$assert(str_contains($automation, '$currentTurnCount === 1'), 'cache/regra local nao deve reduzir bloco de varias mensagens ao ultimo balao');
$assert(str_contains($context, "_current_turn_text"), 'context builder deve transportar o turno atual ao modelo');
$assert(str_contains($view, 'Aguardar o cliente terminar e agrupar as mensagens antes de responder'), 'tela deve expor agrupamento em linguagem simples');
$assert(str_contains($view, 'Responder primeiro o que o cliente acabou de perguntar'), 'tela deve expor prioridade da pergunta atual');

$model = new AiModelService();
$method = new ReflectionMethod($model, 'buildSystemPrompt');
$method->setAccessible(true);
$prompt = (string) $method->invoke($model, [
    'id' => 5,
    'name' => 'Rafa, Assistente da psicóloga Mariana Bernardes',
    'segment' => 'Recepção',
    'system_prompt' => 'Atenda de forma natural.',
    'prioritize_current_turn' => 1,
    '_current_turn_count' => 2,
    '_current_turn_text' => "ok, quero indicação.\ncom quem eu falo mesmo?",
], [
    'name' => 'Rafaela',
    'phone' => '5532999999999',
], [
    'tenant_id' => 0,
    'contact_group' => 'lead',
    'contact_status' => 'lead',
    'flow_stage' => 'qualified',
    'demand_status' => 'collected',
    'last_intent' => 'conversation',
]);

$assert(str_contains($prompt, 'TURNO ATUAL DO CLIENTE'), 'prompt deve destacar o bloco atual');
$assert(str_contains($prompt, 'ok, quero indicação.'), 'prompt deve conter o primeiro balao do turno');
$assert(str_contains($prompt, 'com quem eu falo mesmo?'), 'prompt deve conter a pergunta seguinte do mesmo turno');
$assert(str_contains($prompt, 'Rafa, Assistente da psicóloga Mariana Bernardes'), 'prompt deve informar a identidade real do assistente');
$assert(str_contains($prompt, 'responda essa pergunta primeiro'), 'prompt deve priorizar pergunta direta antes do roteiro');
$assert(str_contains($prompt, 'não peça o telefone novamente'), 'prompt deve impedir pedir telefone ja conhecido');

$guard = new ReflectionMethod($model, 'applyCurrentTurnContinuityGuard');
$guard->setAccessible(true);
$guarded = (string) $guard->invoke($model,
    'Vou encaminhar seu pedido. Você pode me informar seu nome e telefone?',
    [
        'name' => 'Rafa, Assistente da psicóloga Mariana Bernardes',
        'prioritize_current_turn' => 1,
        '_current_turn_text' => "ok, quero indicação.\ncom quem eu falo mesmo?",
    ],
    [
        'name' => 'Rafaela',
        'phone' => '5532999999999',
    ]
);
$assert(str_contains($guarded, 'Você está falando com Rafa, Assistente da psicóloga Mariana Bernardes.'), 'barreira final deve responder identidade conhecida quando o modelo ignorar a pergunta');
$assert(!str_contains(mb_strtolower($guarded), 'nome e telefone'), 'barreira final nao deve pedir novamente telefone conhecido');
$assert(str_contains(mb_strtolower($guarded), 'nome'), 'barreira final pode preservar coleta do nome ainda necessario');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - agrupamento de mensagens e prioridade do turno atual validados.\n";
