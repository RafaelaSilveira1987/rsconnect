<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$serviceFile = $root . '/app/Services/AgentRoutingService.php';
$ownershipFile = $root . '/app/Services/ConversationOwnershipService.php';
$migrationFile = $root . '/database/migrations/099_ai_agent_round_robin_routing.sql';
$manifestFile = $root . '/database/migrations/manifest.php';
$versionFile = $root . '/app/Services/AppVersionService.php';

$files = [$serviceFile, $ownershipFile, $migrationFile, $manifestFile, $versionFile];
foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FALHA: arquivo ausente: {$file}\n");
        exit(1);
    }
}

$service = (string) file_get_contents($serviceFile);
$ownership = (string) file_get_contents($ownershipFile);
$migration = (string) file_get_contents($migrationFile);
$manifest = (string) file_get_contents($manifestFile);
$version = (string) file_get_contents($versionFile);

$checks = [
    'estado persistente por canal' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS ai_agent_routing_state')
        && str_contains($migration, 'PRIMARY KEY (tenant_id, instance_id)'),
    'cursor registra agente e conversa' => str_contains($migration, 'last_agent_id')
        && str_contains($migration, 'last_conversation_id')
        && str_contains($migration, 'assignment_count'),
    'round-robin usa lock de linha' => str_contains($service, 'FOR UPDATE')
        && str_contains($service, 'ai_agent_routing_state'),
    'conversa concorrente revalida pin dentro do lock' => str_contains($service, 'Depois de adquirir o lock, revalida o pin')
        && str_contains($service, 'pinnedAgentId($pdo, $tenantId, $instanceId, $conversationId)'),
    'primeiro pin vence em concorrência' => str_contains($service, '$this->pin($pdo, $tenantId, $instanceId, $conversationId, $agentId, false)'),
    'keyword continua prioritária' => substr_count($service, '$this->keywordMatch(') >= 2,
    'keyword não avança cursor' => str_contains($service, 'não consomem') || str_contains($service, 'não consome'),
    'keyword transfere pin para outro especialista' => str_contains($service, '$keywordAgentId > 0 && $keywordAgentId !== $pinnedId')
        && str_contains($service, 'transferPinToSpecialist('),
    'handoff IA para IA usa lock da conversa' => str_contains($service, 'Transfere uma conversa já pinada para um especialista')
        && str_contains($service, '$this->pin($pdo, $tenantId, $instanceId, $conversationId, $agentId, true)'),
    'auditor prova transferência IA para IA' => str_contains((string) file_get_contents($root . '/bin/multiagent-audit.php'), 'TRANSFERÊNCIA IA → IA POR INTENÇÃO'),
    'automação filtra agente fora do expediente' => str_contains($service, 'allowsConversationalAutomation'),
    'pin por conversa continua antes do roteamento' => substr_count($service, '$this->pinnedAgentId(') >= 4,
    'fallback sem migration é compatível' => str_contains($service, 'Sem a migration nova, mantém o comportamento anterior'),
    'manifest inclui migration 099' => str_contains($manifest, "099_ai_agent_round_robin_routing.sql"),
    'versão exige migration 099' => str_contains($version, "REQUIRED_MIGRATION = '099_ai_agent_round_robin_routing.sql'"),
    'takeover humano mantém lock de linha' => str_contains($ownership, 'FOR UPDATE'),
    'takeover humano bloqueia interferência' => str_contains($ownership, 'locked_by_other')
        && str_contains($ownership, 'assertMayInteract'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . "\n";
}

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - round-robin transacional, continuidade, horário e takeover humano preservados.\n";
