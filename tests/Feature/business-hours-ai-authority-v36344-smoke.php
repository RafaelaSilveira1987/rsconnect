<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bootstrap.php';

use App\Services\AiAutomationService;
use App\Services\AiModelService;

$passes = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$passes, &$failures): void {
    if ($condition) {
        $passes++;
        echo "[OK] {$label}\n";
        return;
    }
    $failures[] = $label;
    echo "[FAIL] {$label}\n";
};

$model = new AiModelService();
$method = new ReflectionMethod(AiModelService::class, 'buildSystemPrompt');
$method->setAccessible(true);
$agent = [
    'name' => 'Digi',
    'business_timezone' => 'America/Sao_Paulo',
    'system_prompt' => 'Atenda com naturalidade.',
    '_operating_policy' => [
        'enforced' => true,
        'inside' => true,
        'reason' => 'inside_business_hours',
        'current_at' => '2026-09-14 16:20:00-03:00',
    ],
];
$prompt = (string) $method->invoke($model, $agent, [], ['tenant_id' => 0]);
$check(str_contains($prompt, 'DENTRO do horário'), 'Prompt recebe o estado operacional atual confirmado pelo backend.');
$check(str_contains($prompt, 'Mensagens antigas de ausência no histórico são apenas histórico'), 'Prompt invalida ausência histórica como estado atual.');
$check(str_contains($prompt, '2026-09-14 16:20:00-03:00'), 'Prompt inclui o instante operacional usado na decisão.');

$automation = new AiAutomationService();
$guard = new ReflectionMethod(AiAutomationService::class, 'isStaleAfterHoursReply');
$guard->setAccessible(true);
$policy = ['enforced' => true, 'inside' => true, 'reason' => 'inside_business_hours'];
$guardAgent = ['after_hours_message' => 'Estamos fora do horário de atendimento agora.'];
$check($guard->invoke($automation, 'Estamos fora do horário de atendimento agora.', $guardAgent, $policy) === true, 'Guarda bloqueia exatamente a mensagem de ausência durante expediente aberto.');
$check($guard->invoke($automation, 'Estamos fora do horário de atendimento agora. Retornaremos depois.', $guardAgent, $policy) === true, 'Guarda bloqueia continuação da mensagem de ausência durante expediente aberto.');
$check($guard->invoke($automation, 'Não estamos fora do horário; podemos continuar.', $guardAgent, $policy) === false, 'Guarda não bloqueia negação explícita de fechamento.');
$check($guard->invoke($automation, 'Claro, posso ajudar com a remarcação.', $guardAgent, $policy) === false, 'Guarda não interfere em resposta normal dentro do expediente.');
$check($guard->invoke($automation, 'Estamos fora do horário de atendimento agora.', $guardAgent, ['enforced' => true, 'inside' => false]) === false, 'Guarda não bloqueia ausência quando a política realmente está fora do expediente.');

$contextSource = file_get_contents($root . '/app/Services/AiContextBuilder.php') ?: '';
$automationSource = file_get_contents($root . '/app/Services/AiAutomationService.php') ?: '';
$versionSource = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$manifestSource = file_get_contents($root . '/manifest.json') ?: '';
$check(str_contains($contextSource, 'normalizeOperationalMessage') && str_contains($contextSource, 'operacional de ausência'), 'Context builder remove ausência operacional antiga do histórico enviado ao LLM quando aberto.');
$check(str_contains($automationSource, "'ai.operating_policy.blocked'"), 'Há defesa final antes do envio contra resposta incompatível com o expediente atual.');
$check(str_contains($versionSource, "PACKAGE_LABEL = 'RS Connect 36.34.4 — Hotfix de autoridade do horário na IA'"), 'Pacote identifica a versão 36.34.4.');
$check(str_contains($versionSource, "REQUIRED_MIGRATION = '116_sla_trigger_mysql_compat.sql'"), 'Hotfix não cria migration nova.');
$check(str_contains($manifestSource, '"package_version": "36.34.4"'), 'Manifest identifica a 36.34.4.');

if ($failures !== []) {
    fwrite(STDERR, "\nFalhas: " . implode('; ', $failures) . "\n");
    exit(1);
}

echo "\nResumo autoridade de horário 36.34.4: {$passes} verificações aprovadas.\n";
