<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$automationFile = $root . '/app/Services/AiAutomationService.php';
$modelFile = $root . '/app/Services/AiModelService.php';
$viewFile = $root . '/app/Views/conversations/index.php';
$controllerFile = $root . '/app/Controllers/ConversationController.php';
$jsFile = $root . '/public/assets/js/app.js';
$versionFile = $root . '/app/Services/AppVersionService.php';
$layoutFile = $root . '/app/Views/layouts/app.php';

foreach ([$automationFile, $modelFile, $viewFile, $controllerFile, $jsFile, $versionFile, $layoutFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FALHA: arquivo ausente: {$file}\n");
        exit(1);
    }
}

$automation = (string) file_get_contents($automationFile);
$model = (string) file_get_contents($modelFile);
$view = (string) file_get_contents($viewFile);
$controller = (string) file_get_contents($controllerFile);
$js = (string) file_get_contents($jsFile);
$version = (string) file_get_contents($versionFile);
$layout = (string) file_get_contents($layoutFile);

$checks = [
    'motor detecta troca real do pin' => str_contains($automation, '$previousPinnedAgentId')
        && str_contains($automation, "'ai.routing.handoff'")
        && str_contains($automation, "(int) (\$routedConversation['ai_agent_id'] ?? 0) === \$currentAgentId"),
    'novo especialista recebe contexto da transferência' => str_contains($automation, "_routing_handoff_from_agent_name")
        && str_contains($automation, "_routing_handoff_to_agent_name")
        && str_contains($model, 'TRANSFERÊNCIA INTERNA CONFIRMADA PELO RS CONNECT'),
    'primeira resposta do especialista evita cache descontextualizado' => str_contains($automation, '!$afterHoursRecovery && $routingTransition === null'),
    'prompt proíbe falsa promessa de transferência' => str_contains($model, 'Nunca afirme que uma transferência para outro assistente virtual ou setor automatizado já aconteceu'),
    'mensagem automática grava nome da IA' => str_contains($automation, 'sender_display_name')
        && str_contains($automation, "return \$name !== '' ? 'IA - ' . \$name : 'IA';"),
    'histórico usa nome gravado para IA' => str_contains($view, "\$message['sender_type'] === 'ai' ? (\$message['sender_user_name'] ?: 'IA')"),
    'polling recebe sender_display_name' => str_contains($controller, 'COALESCE(m.sender_display_name, u.whatsapp_display_name, u.name) AS sender_user_name')
        && str_contains($controller, "'sender_name' => (string) (\$message['sender_user_name'] ?? '')"),
    'javascript mostra IA com nome' => str_contains($js, "message.sender_type === 'ai' ? (message.sender_name || 'IA')"),
    'pacote identifica versão 36.27.3' => str_contains($version, 'RS Connect 36.27.3')
        && str_contains($version, 'Handoff multiagente identificável'),
    'cache visual renovado' => str_contains($layout, 'app.css?v=36.27.3')
        && str_contains($layout, 'app.js?v=36.27.3'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . "\n";
}

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - handoff IA→IA contextual e autoria por assistente validados.\n";
