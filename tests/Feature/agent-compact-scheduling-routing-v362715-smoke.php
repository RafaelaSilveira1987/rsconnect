<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$viewFile = $root . '/app/Views/agents/index.php';
$cssFile = $root . '/public/assets/css/app.css';
$jsFile = $root . '/public/assets/js/app.js';
$preScheduleFile = $root . '/app/Services/PreSchedulingService.php';
$routingFile = $root . '/app/Services/AgentRoutingService.php';
$migrationFile = $root . '/database/migrations/101_agent_scheduling_specialist_routing.sql';
$manifestFile = $root . '/database/migrations/manifest.php';
$versionFile = $root . '/app/Services/AppVersionService.php';
$layoutFile = $root . '/app/Views/layouts/app.php';

foreach ([$viewFile, $cssFile, $jsFile, $preScheduleFile, $routingFile, $migrationFile, $manifestFile, $versionFile, $layoutFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FALHA: arquivo ausente: {$file}\n");
        exit(1);
    }
}

$view = (string) file_get_contents($viewFile);
$css = (string) file_get_contents($cssFile);
$js = (string) file_get_contents($jsFile);
$preSchedule = (string) file_get_contents($preScheduleFile);
$routing = (string) file_get_contents($routingFile);
$migration = (string) file_get_contents($migrationFile);
$manifest = (string) file_get_contents($manifestFile);
$version = (string) file_get_contents($versionFile);
$layout = (string) file_get_contents($layoutFile);

$checks = [
    'cards usam resumo compacto' => str_contains($view, 'agent-data-compact')
        && str_contains($view, 'Papel no atendimento')
        && str_contains($view, 'Detalhes técnicos'),
    'configurações completas ficam recolhidas' => str_contains($view, 'agent-settings-details')
        && str_contains($view, 'Configurações completas')
        && str_contains($view, 'data-agent-settings-open'),
    'grid evita estouro com vários agentes' => str_contains($css, 'RS Connect 36.27.15 — cards compactos de assistentes')
        && str_contains($css, 'minmax(min(100%, 300px), 1fr)')
        && str_contains($css, 'align-content: start;')
        && str_contains($css, 'min-width: 0;'),
    'ação configurar abre o bloco correto' => str_contains($js, 'data-agent-settings-open')
        && str_contains($js, 'details.open = true'),
    'novo agente de agendamento recebe sugestão especialista' => str_contains($view, 'data-agent-segment')
        && str_contains($js, "routingMode.value = 'specialist'")
        && str_contains($js, 'agendar, agendamento, marcar, remarcar, reagendar, reservar'),
    'migration corrige agente de agendamento já existente sem sobrescrever configuração explícita' => str_contains($migration, "LOWER(TRIM(a.segment)) LIKE '%agend%'")
        && str_contains($migration, 'b.is_primary = 0')
        && str_contains($migration, "TRIM(b.routing_keywords) = ''")
        && !str_contains($migration, "a.name = 'Ana'"),
    'keyword existente continua transferindo pin para especialista' => str_contains($routing, '$keywordAgentId > 0 && $keywordAgentId !== $pinnedId')
        && str_contains($routing, 'transferPinToSpecialist('),
    'pré-agendamento envia com identidade do agente roteado' => str_contains($preSchedule, 'agendaSenderDisplayName(')
        && str_contains($preSchedule, 'withAiWhatsappSignature(')
        && str_contains($preSchedule, '$service->sendText($phone, $deliveredMessage)'),
    'pré-agendamento persiste sender_display_name quando disponível' => str_contains($preSchedule, "hasColumn(\$pdo, 'conversation_messages', 'sender_display_name')")
        && str_contains($preSchedule, ':sender_display_name'),
    'manifest inclui migration 101' => str_contains($manifest, "101_agent_scheduling_specialist_routing.sql"),
    'versão identifica 36.27.15' => str_contains($version, 'RS Connect 36.27.15')
        && str_contains($version, "REQUIRED_MIGRATION = '101_agent_scheduling_specialist_routing.sql'"),
    'cache de CSS e JS renovado' => str_contains($layout, 'app.css?v=36.27.15')
        && str_contains($layout, 'app.js?v=36.27.15'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . "\n";
}

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - UI compacta e roteamento do especialista de agendamento v36.27.15 validados.\n";
