<?php

declare(strict_types=1);

if (!function_exists('mb_strlen')) { function mb_strlen(string $v, ?string $e = null): int { return strlen($v); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $v, ?string $e = null): string { return strtolower($v); } }
if (!function_exists('mb_substr')) { function mb_substr(string $v, int $s, ?int $l = null, ?string $e = null): string { return $l === null ? substr($v, $s) : substr($v, $s, $l); } }

$root = dirname(__DIR__, 2);
require $root . '/app/Services/AgentConversationBehaviorService.php';

use App\Services\AgentConversationBehaviorService;

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$service = new AgentConversationBehaviorService();
$settings = AgentConversationBehaviorService::normalizeConfiguration([
    'response_delivery' => ['mode' => 'auto', 'max_blocks' => 3, 'scope' => 'asked_only'],
    'payment' => ['enabled' => true, 'value_text' => 'R$ 200 por sessão', 'methods' => ['Pix', 'Cartão']],
]);

$reply = 'Olá! O atendimento pode ser realizado presencial ou online. As consultas têm duração de aproximadamente uma hora e o valor é de R$ 200,00, tanto para o atendimento presencial quanto online. Tem acontecido alguma coisa que fez você perceber que seria importante iniciar a psicoterapia?';
$scoped = $service->enforceTurnScopeWithSettings($settings, 'Gostaria de saber como funciona o atendimento', $reply);
$check(str_contains($scoped, 'duração de aproximadamente uma hora'), 'mantém informação relacionada ao funcionamento');
$check(!str_contains($scoped, 'R$ 200') && !str_contains(mb_strtolower($scoped), 'valor é'), 'remove valor não perguntado do turno');
$check(substr_count($scoped, '?') === 1, 'mantém no máximo uma pergunta de coleta');

$asked = $service->enforceTurnScopeWithSettings($settings, 'Como funciona e qual o valor?', $reply);
$check(str_contains($asked, 'R$ 200'), 'mantém valor quando o cliente pergunta explicitamente');

$profile = ['config' => ['conversation_behavior' => [
    'response_delivery' => ['mode' => 'auto', 'max_blocks' => 3, 'scope' => 'asked_only'],
    'payment' => ['enabled' => true, 'value_text' => 'R$ 200 por sessão', 'methods' => ['Pix']],
]], 'triage_fields' => []];
$promptNoPrice = $service->promptBlock($profile, 'Gostaria de saber como funciona o atendimento');
$promptPrice = $service->promptBlock($profile, 'Qual o valor e como posso pagar?');
$check(!str_contains($promptNoPrice, 'R$ 200') && str_contains($promptNoPrice, 'OCULTOS neste turno'), 'prompt não expõe valor configurado quando não foi perguntado');
$check(str_contains($promptPrice, 'R$ 200') && str_contains($promptPrice, 'Pix'), 'prompt expõe pagamento quando o turno pede esse tema');

$aiSource = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$modelSource = (string) file_get_contents($root . '/app/Services/AiModelService.php');
$check(substr_count($aiSource, 'enforceTurnScope(') >= 2, 'respostas novas e cache passam pela guarda determinística de escopo');
$check(str_contains($modelSource, 'promptBlock($agentProfile, $currentTurnText)'), 'prompt operacional recebe o turno atual');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - escopo asked_only validado no prompt e antes da entrega.\n";
