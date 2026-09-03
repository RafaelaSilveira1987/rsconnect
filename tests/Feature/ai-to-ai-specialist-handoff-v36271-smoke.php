<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$serviceFile = $root . '/app/Services/AgentRoutingService.php';
$auditFile = $root . '/bin/multiagent-audit.php';
$versionFile = $root . '/app/Services/AppVersionService.php';

foreach ([$serviceFile, $auditFile, $versionFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FALHA: arquivo ausente: {$file}\n");
        exit(1);
    }
}

$service = (string) file_get_contents($serviceFile);
$audit = (string) file_get_contents($auditFile);
$version = (string) file_get_contents($versionFile);

$checks = [
    'keyword pode substituir pin existente' => str_contains($service, '$keywordAgentId > 0 && $keywordAgentId !== $pinnedId'),
    'transferência força novo pin do especialista' => str_contains($service, 'transferPinToSpecialist(')
        && str_contains($service, '$this->pin($pdo, $tenantId, $instanceId, $conversationId, $agentId, true)'),
    'transferência usa lock de conversa' => str_contains($service, 'Transfere uma conversa já pinada para um especialista')
        && str_contains($service, 'FROM conversations')
        && str_contains($service, 'FOR UPDATE'),
    'transferência não depende de agente hardcoded' => !str_contains($service, "'Digi'")
        && !str_contains($service, "'Carlos'"),
    'automação só transfere para especialista disponível' => str_contains($service, '$keywordAgentId = $availableBindings !== []')
        && str_contains($service, 'allowsConversationalAutomation'),
    'auditor prova IA para IA' => str_contains($audit, 'TRANSFERÊNCIA IA → IA POR INTENÇÃO')
        && str_contains($audit, 'Novo especialista fica pinado na conversa')
        && str_contains($audit, 'não consome cursor do round-robin'),
    'versão documenta handoff IA para IA' => str_contains($version, '36.27.1')
        && str_contains($version, 'handoff IA→IA'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . "\n";
}

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - handoff IA→IA por intenção/especialista validado.\n";
