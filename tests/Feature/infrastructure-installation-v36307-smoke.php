<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$passes = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        echo "[OK] {$label}\n";
        $passes++;
        return;
    }
    echo "[FAIL] {$label}\n";
    $failures[] = $label;
};

$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$composerRaw = $read('composer.json');
$manifestRaw = $read('manifest.json');
$composer = json_decode($composerRaw, true);
$manifest = json_decode($manifestRaw, true);
$compose = $read('docker-compose.yml');
$dockerfile = $read('Dockerfile');
$dockerignore = $read('.dockerignore');
$gitignore = $read('.gitignore');
$env = $read('.env.example');
$envLocal = $read('.env.local.example');
$envVps = $read('.env.vps.example');
$builder = $read('build-full-release.sh');
$database = $read('app/Core/Database.php');
$docs = $read('docs/INSTALACAO-E-TESTES.md');
$version = $read('app/Services/AppVersionService.php');

$check(is_array($composer) && ($composer['name'] ?? '') === 'rs-automacao-digital/rs-connect', 'composer.json é JSON válido do projeto');
$check(($composer['require']['php'] ?? '') === '>=8.2' && isset($composer['require']['ext-pdo_mysql']), 'Composer declara PHP e PDO MySQL');
$check(($composer['autoload']['psr-4']['App\\'] ?? '') === 'app/', 'Composer declara autoload PSR-4 compatível');
$check(is_array($manifest) && ($manifest['package_version'] ?? '') === '36.30.7', 'manifest.json é JSON válido da release');
$check(($manifest['database']['driver'] ?? '') === 'mysql', 'manifesto declara MySQL como driver canônico');

foreach (['.env.example' => $env, '.env.local.example' => $envLocal, '.env.vps.example' => $envVps] as $name => $content) {
    $check(str_contains($content, 'DB_HOST=') && str_contains($content, 'APP_KEY=') && !str_starts_with(ltrim($content), 'FROM php:') && !str_starts_with(ltrim($content), '-- '), $name . ' contém variáveis de ambiente válidas');
}
$check(str_contains($env, 'DEFAULT_COUNTRY_CODE=55') && str_contains($env, 'N8N_API_KEY=') && str_contains($env, 'OPERATIONS_HEALTH_DIGEST_TIME='), '.env.example cobre variáveis operacionais atuais');
$check(str_contains($dockerignore, '.env') && str_contains($dockerignore, 'storage/logs/*') && !str_contains($dockerignore, 'APP_NAME='), '.dockerignore protege segredos e estado de execução');
$check(str_contains($gitignore, '.env') && !str_starts_with(ltrim($gitignore), '-- '), '.gitignore voltou a ser arquivo de padrões');

$check(str_contains($compose, 'image: mysql:8.4') && str_contains($compose, 'service_completed_successfully'), 'Docker Compose sobe MySQL e aguarda migrations');
$check(str_contains($compose, 'bin/migrate.php", "bootstrap"') && !str_contains($compose, '/docker-entrypoint-initdb.d/'), 'Compose usa o runner canônico de migrations');
$check(str_contains($compose, 'http://127.0.0.1/health/live'), 'Compose possui healthcheck de liveness');
$check(str_contains($dockerfile, 'FROM php:8.3-apache') && str_contains($dockerfile, 'pdo_mysql') && str_contains($dockerfile, 'php /var/www/html/bin/migrate.php verify'), 'Dockerfile contém runtime e validação esperados');

$check(str_starts_with($builder, '#!/usr/bin/env bash') && str_contains($builder, 'set -euo pipefail') && str_contains($builder, 'SHA256SUMS.txt'), 'build-full-release.sh é Bash seguro e gera checksums');
$check(str_contains($database, 'mysql:host=') && !str_contains($database, 'pgsql:'), 'Database usa MySQL/PDO e não PostgreSQL');
$check(str_contains($docs, 'MySQL/MariaDB') && str_contains($docs, 'EasyPanel') && str_contains($docs, 'docker compose up --build -d'), 'guia canônico cobre banco, VPS e instalação local');
$check(str_contains($version, 'RS Connect 36.30.7') && str_contains($version, 'Infraestrutura de instalação reproduzível'), 'versão 36.30.7 registrada');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo infraestrutura 36.30.7: {$passes} verificações aprovadas.\n";
