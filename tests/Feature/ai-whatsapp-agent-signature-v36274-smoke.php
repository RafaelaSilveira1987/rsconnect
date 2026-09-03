<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$automationFile = $root . '/app/Services/AiAutomationService.php';
$versionFile = $root . '/app/Services/AppVersionService.php';
$layoutFile = $root . '/app/Views/layouts/app.php';

foreach ([$automationFile, $versionFile, $layoutFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FALHA: arquivo ausente: {$file}\n");
        exit(1);
    }
}

$automation = (string) file_get_contents($automationFile);
$version = (string) file_get_contents($versionFile);
$layout = (string) file_get_contents($layoutFile);

$checks = [
    'WhatsApp recebe assinatura da IA antes do texto' => str_contains($automation, '$deliveredReply = $this->withAiWhatsappSignature($reply, $senderDisplayName)')
        && str_contains($automation, 'sendText($phone, $deliveredReply)'),
    'assinatura usa negrito do WhatsApp' => str_contains($automation, 'return \'*\' . $signature . "*\\n" . $message;'),
    'agente geral usa IA - Nome' => str_contains($automation, 'return \'IA - \' . $agentName;'),
    'especialista usa área e nome' => str_contains($automation, 'return \'IA \' . $role . \' - \' . $agentName;')
        && str_contains($automation, "SELECT routing_keywords"),
    'área vem do cadastro do agente' => str_contains($automation, "SELECT name, segment FROM ai_agents"),
    'conteúdo do painel continua limpo' => str_contains($automation, "'content' => \$reply")
        && str_contains($automation, "'preview' => mb_substr(\$reply, 0, 255)"),
    'retry preserva identificação do emissor' => str_contains($automation, 'failed.sender_display_name')
        && str_contains($automation, "withAiWhatsappSignature((string) \$failedMessage['content'], \$senderDisplayName)"),
    'pacote identifica versão 36.27.7' => str_contains($version, 'RS Connect 36.27.7'),
    'cache visual renovado' => str_contains($layout, 'app.css?v=36.27.8')
        && str_contains($layout, 'app.js?v=36.27.8'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . "\n";
}

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - identificação do agente de IA no WhatsApp validada.\n";
