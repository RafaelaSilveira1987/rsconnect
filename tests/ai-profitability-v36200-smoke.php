<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$service = $read('app/Services/AiProfitabilityHistoryService.php');
$commercial = $read('app/Services/AiCommercialMarginService.php');
$controller = $read('app/Controllers/AiProfitabilityController.php');
$routes = $read('routes/web.php');
$view = $read('app/Views/ai_profitability/index.php');
$layout = $read('app/Views/layouts/app.php');
$migration = $read('database/migrations/084_ai_profitability_history.sql');
$diagnostic = $read('database/diagnostics/ai_profitability_history_v36.20.0.sql');
$docs = $read('docs/guias/guia-rentabilidade-historica-ia.md');
$version = $read('app/Services/AppVersionService.php');
$script = $read('bin/ai-profitability-snapshot.php');
$css = $read('public/assets/css/app.css');

$checks = [
    'migration cria histórico de políticas e snapshots mensais' => str_contains($migration, 'tenant_ai_commercial_policy_history')
        && str_contains($migration, 'tenant_ai_profitability_snapshots')
        && str_contains($migration, 'uq_ai_profitability_tenant_month'),
    'política comercial passa a registrar mudanças no histórico' => str_contains($commercial, 'recordPolicyHistory')
        && str_contains($commercial, 'tenant_ai_commercial_policy_history'),
    'serviço calcula histórico com receita e custo por período' => str_contains($service, 'invoiceRevenueForPeriod')
        && str_contains($service, 'subscriptionForPeriod')
        && str_contains($service, 'aiUsageForPeriod')
        && str_contains($service, 'credential_owner = "rs_connect"'),
    'serviço calcula MRR e MRR sob revisão' => str_contains($service, "'mrr_brl'")
        && str_contains($service, "'mrr_under_target_brl'")
        && str_contains($service, "'margin_rate'"),
    'simulação considera capacidade e margem' => str_contains($service, 'capacityCheck')
        && str_contains($service, 'recommended_revenue_brl')
        && str_contains($service, "'capacity_ok'")
        && str_contains($service, "'meets_margin'"),
    'rota e menu da rentabilidade existem' => str_contains($routes, '/ai-profitability')
        && str_contains($layout, 'Resultados por cliente')
        && str_contains($layout, 'app.css?v=36.20.2'),
    'painel exibe histórico e simulador em linguagem simples' => str_contains($view, 'Receita mensal analisada')
        && str_contains($view, 'Evolução mensal do valor que sobra')
        && str_contains($view, 'Comparar planos e valores')
        && str_contains($view, 'Simular outro valor mensal'),
    'snapshot CLI disponível' => str_contains($script, 'AiProfitabilityHistoryService')
        && str_contains($script, 'monthMetrics'),
    'diagnóstico e documentação presentes' => str_contains($diagnostic, 'estrutura_rentabilidade_historica')
        && str_contains($docs, 'Qualidade da receita')
        && str_contains($docs, 'Simulação de plano'),
    'versão exige migration 084' => str_contains($version, 'RS Connect 36.20.0')
        && str_contains($version, '084_ai_profitability_history.sql')
        && str_contains($css, 'RS Connect 36.20.1'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
exit($failed === [] ? 0 : 1);
