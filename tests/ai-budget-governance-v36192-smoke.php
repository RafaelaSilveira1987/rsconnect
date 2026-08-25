<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$service = $read('app/Services/AiBudgetPolicyService.php');
$usage = $read('app/Services/AiUsageService.php');
$automation = $read('app/Services/AiAutomationService.php');
$memory = $read('app/Services/AiProgressiveMemoryService.php');
$conversation = $read('app/Controllers/ConversationController.php');
$controller = $read('app/Controllers/OpenAiUsageController.php');
$routes = $read('routes/web.php');
$view = $read('app/Views/openai_usage/index.php');
$migration = $read('database/migrations/082_ai_budget_governance.sql');
$diagnostic = $read('database/diagnostics/ai_budget_governance_v36.19.2.sql');
$docs = $read('docs/guias/guia-governanca-orcamento-ia.md');

$checks = [
    'migration cria política e histórico de thresholds' => str_contains($migration, 'tenant_ai_budget_policies')
        && str_contains($migration, 'ai_budget_threshold_events')
        && str_contains($migration, "block_rs_ai"),
    'serviço separa orçamento por tenant' => str_contains($service, 'monthly_budget_usd')
        && str_contains($service, 'credential_owner = "rs_connect"')
        && str_contains($service, 'usagePeriodForTenant'),
    'ações automáticas são seguras' => str_contains($service, "'economy'")
        && str_contains($service, "'block_rs_ai'")
        && str_contains($service, 'Regras locais, cache, atendimento humano'),
    'reserva comercial bloqueia somente IA RS no hard limit' => str_contains($usage, 'budget_blocked')
        && str_contains($usage, 'new AiBudgetPolicyService()')
        && str_contains($usage, 'if ($billable)'),
    'automação pode forçar modo econômico' => str_contains($automation, 'ai.budget.economy')
        && str_contains($automation, "['ai_efficiency_mode'] = 'economy'"),
    'memória respeita bloqueio financeiro' => str_contains($memory, "'budget_blocked'")
        && str_contains($memory, 'credentialOwner($agent)'),
    'sugestão humana assistida respeita orçamento' => str_contains($conversation, 'orçamento da IA custeada pela RS atingiu o limite')
        && str_contains($conversation, '$messageLimit'),
    'rota e controller permitem salvar política' => str_contains($routes, "/openai-usage/budget")
        && str_contains($controller, 'saveBudgetPolicy')
        && str_contains($controller, "ai.budget_policy.updated"),
    'painel possui configuração por empresa' => str_contains($view, 'Limite e proteção de gasto')
        && str_contains($view, 'bloquear IA RS')
        && str_contains($view, 'Salvar limite e proteção'),
    'diagnóstico e documentação presentes' => str_contains($diagnostic, 'estrutura_orcamento_ia')
        && str_contains($docs, 'atendimento humano')
        && str_contains($docs, 'credencial própria'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
exit($failed === [] ? 0 : 1);
