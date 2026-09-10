#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\AgentSimulationService;

$options = getopt('', [
    'tenant::',
    'agent::',
    'scenario::',
    'mode::',
    'prepare-psychology',
    'list-tenants',
    'list-agents',
    'list-scenarios',
    'version',
    'help',
]);

$labVersion = '36.29.5';
$service = new AgentSimulationService();

$printTenants = static function (array $tenants): void {
    if ($tenants === []) {
        echo "Nenhuma empresa encontrada no banco.\n";
        return;
    }
    echo "Empresas disponíveis:\n";
    foreach ($tenants as $tenant) {
        $id = (int) ($tenant['id'] ?? 0);
        $name = (string) ($tenant['name'] ?? 'Sem nome');
        $slug = trim((string) ($tenant['slug'] ?? ''));
        $status = (string) ($tenant['status'] ?? '');
        $ref = $slug !== '' ? " | slug={$slug}" : '';
        echo "  {$id} | {$name}{$ref} | {$status}\n";
    }
};

$printAgents = static function (array $agents, int $tenantId): void {
    if ($agents === []) {
        echo "Nenhum assistente encontrado para a empresa #{$tenantId}.\n";
        return;
    }
    echo "Assistentes da empresa #{$tenantId}:\n";
    foreach ($agents as $agent) {
        $id = (int) ($agent['id'] ?? 0);
        $name = (string) ($agent['name'] ?? 'Sem nome');
        $status = (string) ($agent['status'] ?? '');
        $provider = trim((string) ($agent['model_provider'] ?? ''));
        $model = trim((string) ($agent['model_name'] ?? ''));
        $modelLabel = trim($provider . ($model !== '' ? '/' . $model : ''), '/');
        echo "  {$id} | {$name} | {$status}" . ($modelLabel !== '' ? " | {$modelLabel}" : '') . "\n";
    }
};

$printScenarios = static function (array $scenarios, int $tenantId): void {
    if ($scenarios === []) {
        echo "Nenhum cenário encontrado para a empresa #{$tenantId}.\n";
        return;
    }
    echo "Cenários da empresa #{$tenantId}:\n";
    foreach ($scenarios as $scenario) {
        $id = (int) ($scenario['id'] ?? 0);
        $slug = (string) ($scenario['slug'] ?? '');
        $name = (string) ($scenario['name'] ?? 'Sem nome');
        $last = trim((string) ($scenario['last_status'] ?? ''));
        echo "  {$id} | {$slug} | {$name}" . ($last !== '' ? " | último={$last}" : '') . "\n";
    }
};

$help = static function () use ($printTenants, $service): void {
    echo "RS Connect — Laboratório de assistentes 36.29.5\n\n";
    echo "Primeiro descubra os IDs reais do seu banco:\n";
    echo "  php bin/test-agent.php --list-tenants\n";
    echo "  php bin/test-agent.php --tenant=ID_EMPRESA --list-agents\n\n";
    echo "Preparar cenários de Psicologia:\n";
    echo "  php bin/test-agent.php --tenant=ID_EMPRESA --agent=ID_ASSISTENTE --prepare-psychology\n\n";
    echo "Executar cenários:\n";
    echo "  php bin/test-agent.php --tenant=ID_EMPRESA --agent=ID_ASSISTENTE --mode=quick\n";
    echo "  php bin/test-agent.php --tenant=ID_EMPRESA --agent=ID_ASSISTENTE --scenario=psi-menor-indicacao-regressao --mode=real\n\n";
    echo "Outros comandos:\n";
    echo "  --list-scenarios    Lista os cenários da empresa\n";
    echo "  --version           Mostra a versão do laboratório instalada\n";
    echo "  --help              Mostra esta ajuda\n\n";
    echo "Observação: os números 12 e 4 usados em exemplos antigos eram ilustrativos;\n";
    echo "use sempre os IDs existentes no seu próprio banco.\n\n";
    try {
        $printTenants($service->tenants());
    } catch (Throwable $e) {
        echo "\nNão foi possível listar as empresas agora: {$e->getMessage()}\n";
    }
};

if (isset($options['version'])) {
    echo "RS Connect Agent Lab {$labVersion}\n";
    exit(0);
}

if (isset($options['help'])) {
    $help();
    exit(0);
}

$tenants = $service->tenants();
if (isset($options['list-tenants'])) {
    $printTenants($tenants);
    exit(0);
}

$tenantRef = trim((string) ($options['tenant'] ?? ''));
if ($tenantRef === '') {
    fwrite(STDERR, "Informe a empresa. Use --list-tenants para ver os IDs disponíveis.\n\n");
    $printTenants($tenants);
    exit(2);
}

$tenant = null;
foreach ($tenants as $candidate) {
    $idMatch = ctype_digit($tenantRef) && (int) $tenantRef === (int) ($candidate['id'] ?? 0);
    $slugMatch = $tenantRef !== '' && strcasecmp($tenantRef, (string) ($candidate['slug'] ?? '')) === 0;
    if ($idMatch || $slugMatch) {
        $tenant = $candidate;
        break;
    }
}

if (!$tenant) {
    fwrite(STDERR, "A empresa '{$tenantRef}' não existe neste banco.\n\n");
    $printTenants($tenants);
    exit(2);
}

$tenantId = (int) $tenant['id'];
$agents = $service->agents($tenantId);
if (isset($options['list-agents'])) {
    $printAgents($agents, $tenantId);
    exit(0);
}

$agentRef = trim((string) ($options['agent'] ?? ''));
$agent = null;
if ($agentRef !== '') {
    foreach ($agents as $candidate) {
        $idMatch = ctype_digit($agentRef) && (int) $agentRef === (int) ($candidate['id'] ?? 0);
        $nameMatch = strcasecmp($agentRef, (string) ($candidate['name'] ?? '')) === 0;
        if ($idMatch || $nameMatch) {
            $agent = $candidate;
            break;
        }
    }
    if (!$agent) {
        fwrite(STDERR, "O assistente '{$agentRef}' não pertence à empresa #{$tenantId}.\n\n");
        $printAgents($agents, $tenantId);
        exit(2);
    }
} elseif (count($agents) === 1) {
    $agent = $agents[0];
    echo "Assistente selecionado automaticamente: #{$agent['id']} {$agent['name']}\n";
} else {
    fwrite(STDERR, "Informe o assistente. Use --tenant={$tenantId} --list-agents para ver os IDs disponíveis.\n\n");
    $printAgents($agents, $tenantId);
    exit(2);
}

$agentId = (int) $agent['id'];
$mode = in_array((string) ($options['mode'] ?? 'quick'), ['quick', 'real'], true)
    ? (string) ($options['mode'] ?? 'quick')
    : 'quick';
$scenarioFilter = trim((string) ($options['scenario'] ?? ''));

if (isset($options['prepare-psychology'])) {
    try {
        $count = $service->createPsychologyDefaults($tenantId, $agentId, null);
        echo "Preparados {$count} cenário(s) padrão de Psicologia para {$tenant['name']} / {$agent['name']}.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Falha ao preparar os cenários: {$e->getMessage()}\n");
        exit(1);
    }
}

$scenarios = $service->scenarios($tenantId);
if (isset($options['list-scenarios'])) {
    $printScenarios($scenarios, $tenantId);
    exit(0);
}

if ($scenarioFilter !== '') {
    $scenarios = array_values(array_filter($scenarios, static function (array $row) use ($scenarioFilter): bool {
        return (string) ($row['slug'] ?? '') === $scenarioFilter || (string) ($row['id'] ?? '') === $scenarioFilter;
    }));
}

if ($scenarios === []) {
    fwrite(STDERR, "Nenhum cenário encontrado para a empresa selecionada.\n");
    fwrite(STDERR, "Prepare os padrões com:\n");
    fwrite(STDERR, "  php bin/test-agent.php --tenant={$tenantId} --agent={$agentId} --prepare-psychology\n");
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
