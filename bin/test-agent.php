#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Services\AgentSimulationService;

$options = getopt('', ['tenant:', 'agent:', 'scenario::', 'mode::', 'prepare-psychology', 'help']);
if (isset($options['help'])) {
    echo "RS Connect — Laboratório de assistentes\n\n";
    echo "Uso:\n";
    echo "  php bin/test-agent.php --tenant=12 --agent=4 --prepare-psychology\n";
    echo "  php bin/test-agent.php --tenant=12 --agent=4 --scenario=psi-menor-indicacao-regressao --mode=quick\n";
    echo "  php bin/test-agent.php --tenant=12 --agent=4 --mode=real\n";
    exit(0);
}

$tenantId = (int) ($options['tenant'] ?? 0);
$agentId = (int) ($options['agent'] ?? 0);
$mode = in_array((string) ($options['mode'] ?? 'quick'), ['quick', 'real'], true) ? (string) ($options['mode'] ?? 'quick') : 'quick';
$scenarioFilter = trim((string) ($options['scenario'] ?? ''));

if ($tenantId < 1 || $agentId < 1) {
    fwrite(STDERR, "Informe --tenant e --agent. Use --help para exemplos.\n");
    exit(2);
}

$service = new AgentSimulationService();
if (isset($options['prepare-psychology'])) {
    try {
        $count = $service->createPsychologyDefaults($tenantId, $agentId, null);
        echo "Preparados {$count} cenário(s) padrão de Psicologia.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Falha: {$e->getMessage()}\n");
        exit(1);
    }
}

$scenarios = $service->scenarios($tenantId);
if ($scenarioFilter !== '') {
    $scenarios = array_values(array_filter($scenarios, static function (array $row) use ($scenarioFilter): bool {
        return (string) ($row['slug'] ?? '') === $scenarioFilter || (string) ($row['id'] ?? '') === $scenarioFilter;
    }));
}

if ($scenarios === []) {
    fwrite(STDERR, "Nenhum cenário encontrado. Use --prepare-psychology ou crie cenários no RS Admin.\n");
    exit(3);
}

$passed = 0;
$failed = 0;
foreach ($scenarios as $scenario) {
    $name = (string) ($scenario['name'] ?? ('Cenário #' . ($scenario['id'] ?? '?')));
    echo "\n[TESTE] {$name}\n";
    try {
        $result = $service->runScenario((int) $scenario['id'], $agentId, $mode, null);
        foreach (($result['steps'] ?? []) as $step) {
            $label = ($step['status'] ?? '') === 'passed' ? 'OK' : 'FALHA';
            echo sprintf("  [%s] %02d. %s\n", $label, (int) ($step['order'] ?? 0), (string) ($step['message'] ?? ''));
            if (($step['status'] ?? '') !== 'passed') {
                foreach (($step['assertions']['checks'] ?? []) as $check) {
                    if (empty($check['ok'])) {
                        echo '       - ' . (string) ($check['name'] ?? 'Verificação') . ': esperado '
                            . json_encode($check['expected'] ?? null, JSON_UNESCAPED_UNICODE)
                            . ', obtido ' . json_encode($check['actual'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
                    }
                }
                foreach (($step['safety_findings'] ?? []) as $finding) {
                    echo '       - Segurança: ' . (string) ($finding['message'] ?? $finding['code'] ?? 'falha') . "\n";
                }
            }
        }
        if (($result['status'] ?? '') === 'passed') {
            $passed++;
        } else {
            $failed++;
        }
    } catch (Throwable $e) {
        $failed++;
        echo "  [ERRO] {$e->getMessage()}\n";
    }
}

echo "\nResumo: {$passed} aprovado(s), {$failed} reprovado(s).\n";
exit($failed === 0 ? 0 : 1);
