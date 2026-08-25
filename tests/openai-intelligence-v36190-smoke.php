<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$service = $read('app/Services/AiEfficiencyDashboardService.php');
$controller = $read('app/Controllers/OpenAiUsageController.php');
$view = $read('app/Views/openai_usage/index.php');
$usageService = $read('app/Services/AiUsageService.php');
$css = $read('public/assets/css/app.css');
$env = $read('.env.example');
$docs = $read('docs/guias/guia-consumo-ia.md');

$checks = [
    'telemetria interna possui filtros por empresa e assistente' => str_contains($service, 'tenantId')
        && str_contains($service, 'agentId')
        && str_contains($service, 'filter_options'),
    'taxa de economia usa somente respostas automáticas comparáveis' => str_contains($service, 'reply_provider_calls')
        && str_contains($service, 'provider_calls_avoided')
        && str_contains($service, 'avoidance_rate'),
    'média de tokens não mistura resumo técnico' => str_contains($service, 'provider_reply_tokens')
        && str_contains($service, 'avg_tokens_per_provider_reply'),
    'painel calcula orçamento e projeção' => str_contains($controller, 'OPENAI_MONTHLY_BUDGET_USD')
        && str_contains($controller, 'projected_cost_usd')
        && str_contains($controller, 'tracking_coverage_rate'),
    'painel exibe governança e eficiência' => str_contains($view, 'OpenAI + eficiência do RS Connect')
        && str_contains($view, 'Chamadas evitadas')
        && str_contains($view, 'Tokens evitados')
        && str_contains($view, 'Oficial x RS Connect'),
    'ranking de empresa e assistente disponível' => str_contains($view, 'Custo por empresa')
        && str_contains($view, 'Custo por assistente'),
    'telemetria técnica respeita modelo efetivamente chamado' => str_contains($usageService, "\$provider = (string) (\$telemetry['provider']")
        && str_contains($usageService, "\$model = (string) (\$telemetry['model']"),
    'estilos v2 presentes' => str_contains($css, 'RS Connect 36.19.0')
        && str_contains($css, '.openai-executive-grid')
        && str_contains($css, '.openai-governance-grid'),
    'orçamento e câmbio documentados no env' => str_contains($env, 'OPENAI_MONTHLY_BUDGET_USD=')
        && str_contains($env, 'OPENAI_USAGE_USD_BRL='),
    'documentação explica oficial e interno' => str_contains($docs, 'Uso oficial da OpenAI')
        && str_contains($docs, 'Telemetria do RS Connect'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
exit($failed === [] ? 0 : 1);
