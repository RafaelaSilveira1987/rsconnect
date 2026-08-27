<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$tests = $root . '/tests';
$forbiddenApplicationCopies = [
    'app',
    'public',
    'routes',
    'database',
    'docs',
    'bin',
    'scripts',
    'storage',
    'bootstrap.php',
    'composer.json',
    'docker-compose.yml',
    'Dockerfile',
];

$checks = [
    'estrutura obrigatória criada' => array_reduce(
        ['Unit', 'Integration', 'Feature', 'Contract', 'Support'],
        static fn (bool $ok, string $directory): bool => $ok && is_dir($tests . '/' . $directory),
        true
    ),
    'segunda aplicação removida' => array_reduce(
        $forbiddenApplicationCopies,
        static fn (bool $ok, string $path): bool => $ok && !file_exists($tests . '/' . $path),
        true
    ),
    'manifesto lógico preservado' => is_file($root . '/docs/diagnostics/ENT-026-tests-original-manifest.json'),
    'runner central disponível' => is_file($tests . '/Support/run-smoke-tests.php'),
    'contratos isolados em fixtures' => count(glob($tests . '/Contract/Fixtures/*.json') ?: []) === 29,
    'versão do pacote atualizada' => str_contains(
        (string) file_get_contents($root . '/app/Services/AppVersionService.php'),
        'RS Connect 36.20.12'
    ),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$ok;
}

exit($failed ? 1 : 0);
