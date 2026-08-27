<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$service = $read('app/Services/AiCommercialAttentionService.php');
$controller = $read('app/Controllers/AiCommercialAttentionController.php');
$view = $read('app/Views/ai_attention/index.php');
$routes = $read('routes/web.php');
$layout = $read('app/Views/layouts/app.php');
$migration = $read('database/migrations/085_ai_commercial_attention_queue.sql');
$diagnostic = $read('database/diagnostics/ai_commercial_attention_v36.20.2.sql');
$version = $read('app/Services/AppVersionService.php');
$docs = $read('docs/guias/guia-clientes-que-precisam-atencao.md');
$css = $read('public/assets/css/app.css');

$checks = [
    'serviço reúne margem orçamento plano e tendência' => str_contains($service, 'AiCommercialMarginService')
        && str_contains($service, 'AiBudgetPolicyService')
        && str_contains($service, 'margin_falling')
        && str_contains($service, 'plan_capacity')
        && str_contains($service, 'budget_forecast'),
    'prioridades e ações usam linguagem simples' => str_contains($service, 'O valor mensal pode estar abaixo do necessário')
        && str_contains($service, 'Reduzir o gasto com IA')
        && str_contains($service, 'Completar os dados'),
    'acompanhamento permite reabrir nova mudança' => str_contains($service, 'signal_hash')
        && str_contains($service, 'reopened')
        && str_contains($migration, 'tenant_ai_commercial_attention_tracking'),
    'controller salva com auditoria' => str_contains($controller, 'ai.commercial_attention.updated')
        && str_contains($controller, 'saveTracking'),
    'rota e menu foram criados' => str_contains($routes, '/client-attention')
        && str_contains($layout, 'Clientes que precisam de atenção')
        && str_contains($layout, 'app.css?v=36.20.2'),
    'tela explica motivo e próximo passo' => str_contains($view, 'Por que aparece nesta lista?')
        && str_contains($view, 'Próximo passo sugerido')
        && str_contains($view, 'Nenhum preço, plano ou limite é alterado automaticamente'),
    'diagnóstico documentação e css presentes' => str_contains($diagnostic, 'estrutura_lista_clientes_atencao')
        && str_contains($docs, 'Ver agora')
        && str_contains($css, 'RS Connect 36.20.2'),
    'versão exige migration 085' => str_contains($version, 'RS Connect 36.20.2')
        && str_contains($version, '085_ai_commercial_attention_queue.sql'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
exit($failed === [] ? 0 : 1);
