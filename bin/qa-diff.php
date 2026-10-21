<?php

declare(strict_types=1);

/** Compara dois relatórios JSON da mesma suíte, sem esconder falhas anteriores. */
if ($argc !== 3) {
    fwrite(STDERR, "Uso: php bin/qa-diff.php RELATORIO_ANTERIOR.json RELATORIO_ATUAL.json\n");
    exit(2);
}
function qaReadReport(string $path): array
{
    if (!is_file($path)) throw new RuntimeException('Relatório não encontrado: ' . $path);
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
        throw new RuntimeException('Formato de relatório inválido: ' . $path);
    }
    return $data;
}
try {
    $before = qaReadReport($argv[1]);
    $after = qaReadReport($argv[2]);
    if (($before['suite'] ?? null) !== ($after['suite'] ?? null)) {
        throw new RuntimeException('Os relatórios devem ser da mesma suíte.');
    }
    $previous = [];
    foreach ($before['results'] as $row) $previous[$row['name']] = $row['status'];
    $current = [];
    foreach ($after['results'] as $row) $current[$row['name']] = $row['status'];
    $newFailures = [];
    $oldFailures = [];
    $fixed = [];
    foreach ($current as $file => $status) {
        if ($status === 'failed') {
            if (($previous[$file] ?? null) === 'failed') $oldFailures[] = $file;
            else $newFailures[] = $file;
        } elseif (($previous[$file] ?? null) === 'failed') {
            $fixed[] = $file;
        }
    }
    $removed = array_values(array_diff(array_keys($previous), array_keys($current)));
    echo 'Falhas novas: ' . count($newFailures) . PHP_EOL;
    foreach ($newFailures as $file) echo '  [REGRESSÃO] ' . $file . PHP_EOL;
    echo 'Falhas anteriores ainda existentes: ' . count($oldFailures) . PHP_EOL;
    echo 'Falhas corrigidas: ' . count($fixed) . PHP_EOL;
    foreach ($fixed as $file) echo '  [CORRIGIDO] ' . $file . PHP_EOL;
    echo 'Testes removidos: ' . count($removed) . PHP_EOL;
    foreach ($removed as $file) echo '  [REVISAR] ' . $file . PHP_EOL;
    if ($oldFailures !== []) echo "ATENÇÃO: a suíte ainda não está totalmente verde.\n";
    exit($newFailures !== [] || $removed !== [] ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
}
