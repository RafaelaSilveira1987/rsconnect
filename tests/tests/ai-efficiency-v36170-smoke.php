<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app');

use App\Services\AiEfficiencyPolicyService;

$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$policy = new AiEfficiencyPolicyService();
$economy = $policy->profile([
    'ai_efficiency_mode' => 'economy',
    'max_context_messages' => 30,
    'ai_selective_knowledge' => 1,
]);
$balanced = $policy->profile([
    'ai_efficiency_mode' => 'balanced',
    'max_context_messages' => 12,
    'ai_selective_knowledge' => 1,
]);
$quality = $policy->profile([
    'ai_efficiency_mode' => 'quality',
    'max_context_messages' => 30,
    'ai_selective_knowledge' => 1,
]);
$custom = $policy->profile([
    'ai_efficiency_mode' => 'economy',
    'max_context_messages' => 8,
    'ai_max_output_tokens' => 320,
    'ai_knowledge_budget_chars' => 9000,
    'ai_selective_knowledge' => 0,
]);

$automation = $read('app/Services/AiAutomationService.php');
$context = $read('app/Services/AiContextBuilder.php');
$router = $read('app/Services/AiRouterService.php');
$model = $read('app/Services/AiModelService.php');
$usage = $read('app/Services/AiUsageService.php');
$subscription = $read('app/Services/SubscriptionService.php');
$controller = $read('app/Controllers/AgentController.php');
$view = $read('app/Views/agents/index.php');
$billing = $read('app/Views/billing/subscription.php');
$migration = $read('database/migrations/077_ai_efficiency_foundation.sql');
$diagnostic = $read('database/diagnostics/ai_efficiency_v36.17.0.sql');
$version = $read('app/Services/AppVersionService.php');
$env = $read('.env.vps.example');

$checks = [
    'modo econômico reduz histórico' => ($economy['history_limit'] ?? 0) === 6,
    'modo econômico limita saída' => ($economy['max_output_tokens'] ?? 0) === 160,
    'modo equilibrado mantém teto seguro' => ($balanced['history_limit'] ?? 0) === 10 && ($balanced['max_output_tokens'] ?? 0) === 260,
    'modo qualidade respeita teto do agente' => ($quality['history_limit'] ?? 0) === 20 && ($quality['max_output_tokens'] ?? 0) === 420,
    'overrides explícitos prevalecem' => ($custom['max_output_tokens'] ?? 0) === 320
        && ($custom['knowledge_budget_chars'] ?? 0) === 9000
        && ($custom['selective_knowledge'] ?? true) === false,
    'roteador integrado antes da chamada' => str_contains($automation, 'new AiRouterService()')
        && str_contains($automation, 'new AiContextBuilder()')
        && str_contains($automation, '$this->ai->generateReply($generationAgent'),
    'contexto possui orçamento e telemetria' => str_contains($context, 'knowledge_budget_chars')
        && str_contains($context, 'estimated_input_tokens_avoided')
        && str_contains($context, 'baselineHistoryLimit'),
    'roteador não troca modelo sem opt-in' => str_contains($router, "if (\$profile['model_override'] !== '')")
        && str_contains($router, "'strategy' => 'provider_ai'"),
    'provedores recebem limite de saída' => str_contains($model, "'_ai_max_output_tokens'")
        && str_contains($model, "'_ai_selected_model'"),
    'telemetria persiste economia' => str_contains($usage, 'estimated_input_tokens_avoided')
        && str_contains($usage, 'efficiency_mode'),
    'painel mostra estratégia por agente' => str_contains($view, 'Estratégia de consumo')
        && str_contains($view, 'Enviar somente os trechos da base relacionados à conversa'),
    'controller salva política' => str_contains($controller, 'ai_efficiency_mode')
        && str_contains($controller, 'ai_knowledge_budget_chars'),
    'assinatura soma tokens evitados' => str_contains($subscription, 'estimated_input_tokens_avoided')
        && str_contains($billing, 'Entrada evitada'),
    'migration idempotente disponível' => str_contains($migration, "COLUMN_NAME = 'ai_efficiency_mode'")
        && str_contains($migration, 'idx_ai_usage_efficiency'),
    'diagnóstico disponível' => str_contains($diagnostic, 'tokens_entrada_evitados_estimados'),
    'model routing opcional documentado no env' => str_contains($env, 'AI_MODEL_OPENAI_ECONOMY=')
        && str_contains($env, 'AI_MODEL_OPENAI_QUALITY='),
    'pacote 36.17.0' => str_contains($version, 'RS Connect 36.17.0')
        && str_contains($version, '077_ai_efficiency_foundation.sql'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - camada de eficiência de IA v36.17.0 validada.\n";
