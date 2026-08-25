<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$calculator = $read('app/Services/AiCostCalculatorService.php');
$usage = $read('app/Services/AiUsageService.php');
$dashboard = $read('app/Services/AiEfficiencyDashboardService.php');
$view = $read('app/Views/openai_usage/index.php');
$migration = $read('database/migrations/081_ai_cost_attribution.sql');
$diagnostic = $read('database/diagnostics/ai_cost_attribution_v36.19.1.sql');
$env = $read('.env.example');

$checks = [
    'calculador possui catálogo OpenAI padrão' => str_contains($calculator, "'gpt-4o-mini'")
        && str_contains($calculator, "'gpt-4o'")
        && str_contains($calculator, "'gpt-5.6-luna'")
        && str_contains($calculator, 'DEFAULT_PRICING_SNAPSHOT'),
    'modelos especializados não recebem tarifa de texto por engano' => str_contains($calculator, "'tts'")
        && str_contains($calculator, "'transcribe'")
        && str_contains($calculator, 'isSpecializedModel'),
    'AI_COST_RATES_JSON continua tendo prioridade' => str_contains($calculator, 'configuredRate')
        && str_contains($calculator, "Env::get('AI_COST_RATES_JSON'"),
    'telemetria usa o novo calculador' => str_contains($usage, 'new AiCostCalculatorService()'),
    'ranking de empresa e assistente considera OpenAI' => substr_count($dashboard, 'AND e.provider = "openai"') >= 2,
    'painel exibe cobertura e modelos sem tarifa' => str_contains($view, 'Uso com preço conhecido')
        && str_contains($view, 'Modelos sem preço cadastrado')
        && str_contains($view, 'Uso por empresa'),
    'migration recalcula histórico conhecido' => str_contains($migration, "estimated_cost_currency = 'USD'")
        && str_contains($migration, "gpt-4o-mini")
        && str_contains($migration, "gpt-5.6-luna"),
    'diagnóstico lista custo por empresa' => str_contains($diagnostic, 'custo_estimado_usd')
        && str_contains($diagnostic, 'GROUP BY e.tenant_id'),
    'env explica fallback de tarifas' => str_contains($env, 'snapshot 2026-08-25'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
exit($failed === [] ? 0 : 1);
