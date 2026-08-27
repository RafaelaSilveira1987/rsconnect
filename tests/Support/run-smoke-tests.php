<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$featureDir = $projectRoot . '/tests/Feature';
$files = glob($featureDir . '/*-smoke.php') ?: [];
sort($files, SORT_STRING);

$passed = 0;
$failed = [];

foreach ($files as $file) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    $relative = str_replace($projectRoot . '/', '', $file);
    if ($exitCode === 0) {
        $passed++;
        echo "[OK] {$relative}\n";
        continue;
    }

    $failed[] = [
        'file' => $relative,
        'exit_code' => $exitCode,
        'output' => implode(PHP_EOL, $output),
    ];
    echo "[FAIL] {$relative}\n";
}

echo PHP_EOL;
echo sprintf("Resumo: %d aprovado(s), %d reprovado(s), %d total.\n", $passed, count($failed), count($files));

if ($failed !== []) {
    echo PHP_EOL . "Falhas detalhadas:" . PHP_EOL;
    foreach ($failed as $failure) {
        echo PHP_EOL . '--- ' . $failure['file'] . ' (exit ' . $failure['exit_code'] . ') ---' . PHP_EOL;
        echo $failure['output'] . PHP_EOL;
    }
    exit(1);
}
