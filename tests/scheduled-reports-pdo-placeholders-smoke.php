<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/ScheduledReportService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$failed = [];

if (!str_contains($version, 'RS Connect 36.15.1-r4')) {
    $failed[] = 'A versão do hotfix não foi identificada.';
}

$expected = [
    ':last_run_at',
    ':last_attempt_at',
    ':sent_at',
    ':updated_at',
];

foreach ($expected as $placeholder) {
    if (!str_contains($service, $placeholder)) {
        $failed[] = 'Parâmetro esperado ausente: ' . $placeholder;
    }
}

$forbidden = [
    'last_run_at = :now, last_error',
    'updated_at = :now' . PHP_EOL . '                     WHERE id = :id',
    'last_attempt_at = :now',
    'sent_at = :now',
];

foreach ($forbidden as $pattern) {
    if (str_contains($service, $pattern)) {
        $failed[] = 'Parâmetro reutilizado ainda presente: ' . $pattern;
    }
}

preg_match_all('/prepare\(\s*([\'"])(.*?)\1\s*\)/s', $service, $matches, PREG_SET_ORDER);
foreach ($matches as $match) {
    preg_match_all('/(?<!:):([A-Za-z_][A-Za-z0-9_]*)/', $match[2], $params);
    $counts = array_count_values($params[1] ?? []);
    foreach ($counts as $name => $count) {
        if ($count > 1) {
            $failed[] = 'Parâmetro duplicado em consulta preparada: :' . $name;
        }
    }
}

if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - parâmetros PDO dos relatórios automáticos são exclusivos.\n";
