<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$migration = $read('database/migrations/080_ai_memory_and_usage_intelligence.sql');
$diagnostic = $read('database/diagnostics/ai_memory_usage_v36.19.0.sql');
$memory = $read('app/Services/AiProgressiveMemoryService.php');
$model = $read('app/Services/AiModelService.php');
$context = $read('app/Services/AiContextBuilder.php');
$automation = $read('app/Services/AiAutomationService.php');
$agentController = $read('app/Controllers/AgentController.php');
$agentView = $read('app/Views/agents/index.php');
$conversationController = $read('app/Controllers/ConversationController.php');
$conversationView = $read('app/Views/conversations/index.php');
$env = $read('.env.vps.example');
$version = $read('app/Services/AppVersionService.php');
$layout = $read('app/Views/layouts/app.php');

$checks = [
    'migration cria memória e configuração por assistente' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS conversation_ai_memory')
        && str_contains($migration, 'ai_progressive_memory_enabled')
        && str_contains($migration, 'ai_memory_refresh_messages')
        && str_contains($migration, 'ai_memory_max_chars')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS contact_ai_memory'),
    'diagnóstico da memória disponível' => str_contains($diagnostic, 'ai_memory_tables')
        && str_contains($diagnostic, 'execution_strategy'),
    'memória atualiza somente por intervalo' => str_contains($memory, 'refreshIfNeeded')
        && str_contains($memory, '$newCount <')
        && str_contains($memory, '$threshold'),
    'memória exige JSON e evita invenção' => str_contains($memory, 'Retorne SOMENTE JSON válido')
        && str_contains($memory, 'não invente dados')
        && str_contains($memory, 'pending_items')
        && str_contains($memory, 'next_action')
        && str_contains($memory, 'contact_ai_memory')
        && str_contains($memory, "'scope' => \$scope"),
    'tarefa compacta não usa prompt comercial completo' => str_contains($model, 'generateCompactTask')
        && str_contains($model, 'AI_MEMORY_MODEL_OPENAI')
        && str_contains($model, 'AI_MEMORY_MODEL_GOOGLE'),
    'contexto injeta resumo e reduz histórico' => str_contains($context, '_conversation_memory_summary')
        && str_contains($context, 'progressive_memory_used')
        && str_contains($context, 'default => 6'),
    'atualização ocorre depois da resposta principal' => str_contains($automation, 'refreshIfNeeded')
        && str_contains($automation, 'ai.memory.refreshed'),
    'assistente permite controlar a memória' => str_contains($agentController, 'ai_progressive_memory_enabled')
        && str_contains($agentController, 'ai_memory_refresh_messages')
        && str_contains($agentView, 'Memória progressiva da conversa'),
    'conversa mostra memória com compatibilidade pré-migration' => str_contains($conversationController, "hasTable(\$pdo, 'conversation_ai_memory')")
        && str_contains($conversationView, 'Memória da IA')
        && str_contains($conversationView, 'Próximo passo:')
        && str_contains($conversationView, 'Memória preservada do contato'),
    'modelos opcionais documentados' => str_contains($env, 'AI_MEMORY_MODEL_OPENAI=')
        && str_contains($env, 'AI_MEMORY_MODEL_GOOGLE='),
    'versão e migration corretas' => str_contains($version, 'RS Connect 36.19.0')
        && str_contains($version, '080_ai_memory_and_usage_intelligence.sql')
        && str_contains($layout, 'app.css?v=36.19.3')
        && str_contains($version, '081_ai_cost_attribution.sql')
        && str_contains($version, '082_ai_budget_governance.sql'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
exit($failed === [] ? 0 : 1);
