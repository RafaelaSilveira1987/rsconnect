<?php

declare(strict_types=1);

if (!function_exists('mb_substr')) { function mb_substr(string $v, int $s, ?int $l = null, ?string $e = null): string { return $l === null ? substr($v, $s) : substr($v, $s, $l); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $v, ?string $e = null): int { return strlen($v); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $v, ?string $e = null): string { return strtolower($v); } }

$root = dirname(__DIR__, 2);
require $root . '/app/Services/AgentConversationBehaviorService.php';

use App\Services\AgentConversationBehaviorService;
$behaviorSource = (string) file_get_contents($root . '/app/Services/AgentConversationBehaviorService.php');
$messageSource = (string) file_get_contents($root . '/app/Services/ConversationAutomationMessageService.php');
$view = (string) file_get_contents($root . '/app/Views/agents/index.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$service = new AgentConversationBehaviorService();
$normalized = AgentConversationBehaviorService::normalizeConfiguration([
    'response_delivery' => ['mode' => 'auto', 'max_blocks' => 3, 'scope' => 'asked_only'],
]);
$check(($normalized['response_delivery']['scope'] ?? '') === 'asked_only', 'escopo do turno é persistido na configuração');

$profile = [
    'config' => [
        'conversation_behavior' => [
            'response_delivery' => ['mode' => 'auto', 'max_blocks' => 3, 'scope' => 'asked_only'],
            'payment' => ['enabled' => true, 'value_text' => 'R$ 200', 'methods' => ['Pix']],
        ],
    ],
    'triage_fields' => [],
];
$prompt = $service->promptBlock($profile);
$check(str_contains($prompt, 'Não antecipe valor') && str_contains($prompt, 'PRÓXIMA etapa obrigatória'), 'prompt proíbe antecipar assuntos e limita avanço a uma etapa');

$reply = 'O atendimento pode ser realizado online ou presencial e cada sessão dura aproximadamente cinquenta minutos. A modalidade é escolhida conforme sua preferência e a disponibilidade real da agenda. O atendimento seria para você mesmo?';
$blocks = $service->splitReplyWithSettings(['response_delivery' => ['mode'=>'auto','max_blocks'=>3,'scope'=>'asked_only']], $reply);
$check(count($blocks) >= 2 && count($blocks) <= 3, 'modo automático quebra resposta longa em múltiplos balões');
$check(str_ends_with(trim((string) end($blocks)), '?'), 'pergunta final fica em bloco próprio quando possível');

$check(str_contains($view, 'Ritmo da conversa') && str_contains($view, 'Responder só ao que foi perguntado e avançar 1 etapa'), 'tela expõe configuração simples de ritmo');
$check(str_contains($messageSource, 'AgentConversationBehaviorService())->splitReply'), 'mensagens determinísticas também passam pelo divisor configurável');
$check(str_contains($behaviorSource, "'scope' => 'asked_only'"), 'padrão seguro é responder somente ao turno atual');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.36.21 — Ritmo conversacional e entrega em blocos'"), 'pacote identifica a versão 36.36.21');
$check(str_contains($version, "REQUIRED_MIGRATION = '119_agent_workflow_runtime_contract.sql'"), 'não exige migration nova além da 119');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - ritmo por turno e entrega em blocos validados.\n";
