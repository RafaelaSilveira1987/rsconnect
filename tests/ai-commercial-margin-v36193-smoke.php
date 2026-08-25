<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$service = $read('app/Services/AiCommercialMarginService.php');
$controller = $read('app/Controllers/OpenAiUsageController.php');
$routes = $read('routes/web.php');
$view = $read('app/Views/openai_usage/index.php');
$migration = $read('database/migrations/083_ai_commercial_margin.sql');
$diagnostic = $read('database/diagnostics/ai_commercial_margin_v36.19.3.sql');
$docs = $read('docs/guias/guia-margem-comercial-ia.md');
$version = $read('app/Services/AppVersionService.php');

$checks = [
    'migration cria política comercial por tenant' => str_contains($migration, 'tenant_ai_commercial_policies')
        && str_contains($migration, "revenue_source ENUM('subscription','manual')")
        && str_contains($migration, 'target_margin_percent'),
    'serviço cruza assinatura e custo de IA RS' => str_contains($service, 'tenant_subscriptions')
        && str_contains($service, 'AiBudgetPolicyService')
        && str_contains($service, 'monthly_equivalent_brl'),
    'projeção calcula contribuição e margem conhecida' => str_contains($service, 'projected_contribution_brl')
        && str_contains($service, 'projected_margin_rate')
        && str_contains($service, 'recommended_revenue_brl'),
    'câmbio pode herdar ambiente ou usar override' => str_contains($service, "OPENAI_USAGE_USD_BRL")
        && str_contains($service, 'usd_brl_rate')
        && str_contains($service, "'fx_source'"),
    'controller e rota salvam política comercial' => str_contains($controller, 'saveCommercialPolicy')
        && str_contains($controller, 'ai.commercial_policy.updated')
        && str_contains($routes, '/openai-usage/commercial'),
    'painel exibe margem e preço de referência' => str_contains($view, 'Margem, custo e preço de referência')
        && str_contains($view, 'Receita mínima p/ alvo')
        && str_contains($view, 'Salvar política comercial'),
    'painel não chama margem de lucro líquido' => str_contains($view, 'não lucro líquido contábil')
        && str_contains($docs, 'não é lucro líquido'),
    'diagnóstico e documentação presentes' => str_contains($diagnostic, 'estrutura_margem_comercial_ia')
        && str_contains($docs, 'Receita de referência')
        && str_contains($docs, 'Orçamento de IA'),
    'versão exige migration 083' => str_contains($version, 'RS Connect 36.19.3')
        && str_contains($version, "083_ai_commercial_margin.sql"),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
exit($failed === [] ? 0 : 1);
