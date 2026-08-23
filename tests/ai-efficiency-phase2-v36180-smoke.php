<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Services/AiLocalReplyService.php';

use App\Services\AiLocalReplyService;

$local = new AiLocalReplyService();
$agent = [
    'ai_local_replies_enabled' => 1,
    'ai_greeting_reply' => 'Olá! Como posso ajudar?',
    'ai_gratitude_reply' => 'Por nada!',
    'ai_farewell_reply' => 'Até mais!',
    'ai_menu_reply' => "1. Vendas\n2. Suporte",
];

$checks = [
    'saudação exata usa resposta local' => ($local->match($agent, 'Olá!')['type'] ?? null) === 'greeting',
    'agradecimento exato usa resposta local' => ($local->match($agent, 'Muito obrigada')['type'] ?? null) === 'gratitude',
    'mensagem composta continua na IA' => empty($local->match($agent, 'Olá, preciso alterar o meu pedido')['matched']),
    'cache seguro possui invalidação de contexto' => str_contains((string) file_get_contents($root . '/app/Services/AiExactCacheService.php'), 'contextHash($agent)')
        && str_contains((string) file_get_contents($root . '/app/Services/AiExactCacheService.php'), 'ai_exact_cache_enabled')
        && str_contains((string) file_get_contents($root . '/app/Services/AiExactCacheService.php'), 'agendar|remarcar|cancelar|disponibilidade'),
    'automação consulta zero-token antes da franquia' => strpos((string) file_get_contents($root . '/app/Services/AiAutomationService.php'), 'AiLocalReplyService')
        < strpos((string) file_get_contents($root . '/app/Services/AiAutomationService.php'), 'reserveAutoReply'),
    'telemetria registra chamadas evitadas' => str_contains((string) file_get_contents($root . '/app/Services/AiUsageService.php'), 'recordAvoidedAutoReply')
        && str_contains((string) file_get_contents($root . '/database/migrations/079_ai_efficiency_phase2_and_report_cleanup.sql'), 'provider_calls_avoided'),
    'configuração está disponível no assistente' => str_contains((string) file_get_contents($root . '/app/Views/agents/index.php'), 'Respostas sem consumir tokens')
        && str_contains((string) file_get_contents($root . '/app/Controllers/AgentController.php'), 'aiLocalAutomationFromPost'),
    'arquivos temporários removidos' => !is_file($root . '/app/Controllers.tmp') && !is_file($root . '/app/Controllers.tmp.php'),
    'pacote exige migration 079' => str_contains((string) file_get_contents($root . '/app/Services/AppVersionService.php'), "079_ai_efficiency_phase2_and_report_cleanup.sql"),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - economia de IA fase 2, telemetria e limpeza técnica validadas.\n";
