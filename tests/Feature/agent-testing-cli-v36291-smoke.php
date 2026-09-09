<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$cli = (string) file_get_contents($root . '/bin/test-agent.php');
$service = (string) file_get_contents($root . '/app/Services/AgentSimulationService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$docs = (string) file_get_contents($root . '/docs/ATUALIZACAO-v36.29.0.md');

$checks = [
    'CLI lista empresas reais' => str_contains($cli, "--list-tenants") && str_contains($cli, 'Empresas disponíveis'),
    'CLI lista assistentes por empresa' => str_contains($cli, "--list-agents") && str_contains($cli, 'Assistentes da empresa'),
    'CLI lista cenários' => str_contains($cli, "--list-scenarios"),
    'CLI não usa IDs ilustrativos como instrução principal' => !str_contains($cli, '--tenant=12 --agent=4 --prepare-psychology'),
    'CLI informa que IDs antigos eram exemplos' => str_contains($cli, 'eram ilustrativos'),
    'prepare valida tenant antes de inserir' => str_contains($service, 'A empresa informada não existe'),
    'prepare valida agente da empresa' => str_contains($service, 'não existe ou não pertence à empresa'),
    'listagem de tenants inclui slug' => str_contains($service, 'SELECT id, name, slug, status FROM tenants'),
    'documentação antiga não induz IDs 12/4' => !str_contains($docs, '--tenant=12 --agent=4'),
    'pacote 36.29.1 identificado' => str_contains($version, 'RS Connect 36.29.1'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - CLI do laboratório orienta IDs reais e evita erro bruto de foreign key.\n";
