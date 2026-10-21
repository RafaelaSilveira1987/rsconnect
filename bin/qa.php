<?php

declare(strict_types=1);

/**
 * RS Connect QA — executor determinístico, sem dependências de PHPUnit.
 * Uso: php bin/qa.php --suite=critical --report-dir=/tmp/rs-qa
 */
$root = dirname(__DIR__);
$manifest = require $root . '/tests/Support/qa-suites.php';
$args = getopt('', ['suite:', 'report-dir:', 'timeout:', 'list', 'verbose', 'help']);
if (isset($args['help'])) {
    echo "Uso: php bin/qa.php --suite=critical|investigate|historical|all|lint|integration [--report-dir=CAMINHO] [--timeout=SEGUNDOS] [--list] [--verbose]\n";
    exit(0);
}
$suite = (string) ($args['suite'] ?? 'critical');
$timeout = (int) ($args['timeout'] ?? 45);
if ($timeout < 5 || $timeout > 600) {
    fwrite(STDERR, "Timeout deve estar entre 5 e 600 segundos.\n");
    exit(2);
}
$featureFiles = glob($root . '/tests/Feature/*-smoke.php') ?: [];
sort($featureFiles, SORT_STRING);
$featureTests = array_map(static fn (string $f): string => str_replace($root . '/', '', $f), $featureFiles);
$historical = array_values(array_diff($featureTests, $manifest['critical'], $manifest['investigate']));
$lintDirs = ['app', 'bin', 'routes', 'tests'];
$lintPaths = ['bootstrap.php', 'public/index.php'];
foreach ($lintDirs as $directory) {
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $lintPaths[] = str_replace($root . '/', '', $file->getPathname());
        }
    }
}
$lintPaths = array_values(array_unique($lintPaths));
sort($lintPaths, SORT_STRING);
$files = match ($suite) {
    'critical' => $manifest['critical'],
    'investigate' => $manifest['investigate'],
    'historical' => $historical,
    'integration' => $manifest['integration'],
    'all' => array_values(array_unique(array_merge($manifest['critical'], $manifest['investigate'], $historical))),
    'lint' => $lintPaths,
    default => null,
};
if ($files === null) {
    fwrite(STDERR, "Suíte desconhecida: {$suite}\n");
    exit(2);
}
if ($suite === 'integration' && (getenv('QA_TARGET') !== 'homologation' || getenv('QA_ALLOW_DB') !== '1')) {
    fwrite(STDERR, "Integração bloqueada: somente homologação. Defina QA_TARGET=homologation e QA_ALLOW_DB=1.\n");
    exit(2);
}
if (isset($args['list'])) {
    foreach ($files as $file) echo $file, PHP_EOL;
    exit(0);
}
$reportDir = (string) ($args['report-dir'] ?? ($root . '/storage/qa-reports'));
if (!is_dir($reportDir) && !mkdir($reportDir, 0775, true) && !is_dir($reportDir)) {
    fwrite(STDERR, "Não foi possível criar pasta de relatórios.\n");
    exit(2);
}
$results = [];
foreach ($files as $file) {
    $absolute = $root . '/' . $file;
    if (!is_file($absolute)) {
        $results[] = ['name' => $file, 'status' => 'failed', 'exit_code' => 2, 'seconds' => 0, 'output' => 'Arquivo de teste ausente.'];
        echo "[FALHA] {$file} (arquivo ausente)\n";
        continue;
    }
    $cmd = [PHP_BINARY, '-d', 'zend.assertions=1', '-d', 'assert.exception=1', '-d', 'display_errors=1'];
    if ($suite === 'lint') {
        $cmd[] = '-l';
    }
    $cmd[] = $absolute;
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $start = microtime(true);
    $process = proc_open($cmd, $descriptor, $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        $results[] = ['name' => $file, 'status' => 'failed', 'exit_code' => 2, 'seconds' => 0, 'output' => 'Não foi possível iniciar o PHP.'];
        continue;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $timedOut = false;
    $knownExit = null;
    do {
        $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $knownExit = $status['exitcode'];
            break;
        }
        if (microtime(true) - $start >= $timeout) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(25000);
    } while (true);
    $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $output = redactSensitiveText($output);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($process);
    $exitCode = $knownExit !== null && $knownExit >= 0 ? $knownExit : $closeCode;
    if ($timedOut) $exitCode = 124;
    // O bootstrap antigo captura exceções e pode imprimir erro 500 com exit 0.
    $silentError = str_contains($output, 'Ocorreu um erro interno. Consulte storage/logs/app.log.')
        || str_contains($output, 'Fatal error:') || str_contains($output, 'Uncaught ');
    $passed = $exitCode === 0 && !$silentError;
    $duration = round(microtime(true) - $start, 3);
    $results[] = [
        'name' => $file, 'status' => $passed ? 'passed' : 'failed',
        'exit_code' => $exitCode, 'seconds' => $duration,
        'output' => mbSafeLimit($output, 12000),
        'reason' => $timedOut ? 'timeout' : ($silentError ? 'erro fatal capturado pelo bootstrap' : ''),
    ];
    echo ($passed ? '[OK] ' : '[FALHA] '), $file, ' (', number_format($duration, 2), "s)\n";
    if (!$passed || isset($args['verbose'])) {
        $text = trim($output);
        if ($text !== '') echo mbSafeLimit($text, 1200), "\n";
    }
}
$passed = count(array_filter($results, static fn ($r) => $r['status'] === 'passed'));
$failed = count($results) - $passed;
$report = [
    'suite' => $suite, 'timestamp' => gmdate('c'),
    'summary' => ['passed' => $passed, 'failed' => $failed, 'total' => count($results)],
    'results' => $results,
];
$jsonFile = $reportDir . '/qa-' . $suite . '.json';
$xmlFile = $reportDir . '/qa-' . $suite . '.xml';
file_put_contents($jsonFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<testsuite name="RS Connect ' . htmlspecialchars($suite, ENT_XML1) . '" tests="' . count($results) . '" failures="' . $failed . '">' . "\n";
foreach ($results as $row) {
    $xml .= '  <testcase classname="RSConnect.QA" name="' . htmlspecialchars($row['name'], ENT_XML1) . '" time="' . $row['seconds'] . '">';
    if ($row['status'] !== 'passed') {
        $xml .= '<failure message="exit ' . $row['exit_code'] . '"><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $row['output']) . ']]></failure>';
    }
    $xml .= '</testcase>' . "\n";
}
$xml .= '</testsuite>' . "\n";
file_put_contents($xmlFile, $xml);
echo "\nSUÍTE {$suite}: {$passed} aprovado(s), {$failed} reprovado(s).\n";
echo "Relatórios: {$jsonFile} | {$xmlFile}\n";
exit($failed > 0 ? 1 : 0);

function mbSafeLimit(string $text, int $size): string
{
    return strlen($text) > $size ? substr($text, 0, $size) . "\n... (saída truncada)" : $text;
}

/** Impede vazamento acidental de tokens em relatórios de falhas. */
function redactSensitiveText(string $text): string
{
    $text = (string) preg_replace('/((?:apikey|api_key|authorization|token|password|secret)\s*[=:]\s*)([^\s,}\]]+)/i', '$1[REDACTED]', $text);
    return (string) preg_replace('/(Bearer\s+)\S+/i', '$1[REDACTED]', $text);
}
