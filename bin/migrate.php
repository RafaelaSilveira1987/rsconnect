#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Autoloader;
use App\Core\Database;
use App\Core\Env;
use App\Services\MigrationService;

$root = dirname(__DIR__);
require_once $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/app');
Env::load($root . '/.env');
date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));

$args = array_values(array_slice($argv, 1));
$command = strtolower((string) ($args[0] ?? 'help'));
$yes = in_array('--yes', $args, true) || in_array('-y', $args, true);
$dryRun = in_array('--dry-run', $args, true);
$through = 'latest';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--through=')) {
        $through = substr($arg, strlen('--through='));
    }
}

$usage = static function (): void {
    echo <<<'TXT'
RS Connect — executor seguro de migrations

Uso:
  php bin/migrate.php verify
  php bin/migrate.php status
  php bin/migrate.php install --yes
  php bin/migrate.php baseline --through=088 --yes
  php bin/migrate.php up [--dry-run]
  php bin/migrate.php seed --yes
  php bin/migrate.php bootstrap --yes

Comandos:
  verify     Valida manifesto, ordem, checksums calculáveis e parser SQL sem acessar o banco.
  status     Exibe migrations aplicadas, pendentes e divergências de checksum.
  install    Instala schema + seed em banco vazio e registra o baseline completo.
  baseline   Adota com segurança um banco existente sem histórico, após validar a estrutura.
  up         Executa somente migrations pendentes registradas no manifesto.
  seed       Reaplica dados de referência idempotentes após todas as migrations.
  bootstrap  Para Docker dev: instala banco vazio, adota banco atual ou aplica pendências.
TXT;
};

try {
    if (in_array($command, ['help', '--help', '-h'], true)) {
        $usage();
        exit(0);
    }

    if ($command === 'verify') {
        $service = new MigrationService(null, $root);
        $result = $service->verifyOffline();
        echo "[OK] Manifesto: {$result['files']} migrations de subida.\n";
        echo "[OK] Parser SQL: {$result['statements']} instruções reconhecidas.\n";
        echo "[OK] Rollbacks isolados: " . count($result['rollbacks']) . ".\n";
        if ($result['duplicate_numbers'] !== []) {
            echo "[INFO] Numerações históricas duplicadas, ordenadas pelo manifesto:\n";
            foreach ($result['duplicate_numbers'] as $number => $files) {
                echo '  ' . $number . ': ' . implode(' -> ', $files) . "\n";
            }
        }
        exit(0);
    }

    $service = new MigrationService(Database::connection(), $root);

    if ($command === 'status') {
        $result = $service->status();
        echo 'Registro schema_migrations: ' . ($result['registry'] ? 'disponível' : 'ausente') . "\n";
        foreach ($result['rows'] as $row) {
            $marker = match ($row['state']) {
                'applied' => '[OK]',
                'drift' => '[DRIFT]',
                default => '[PENDING]',
            };
            echo sprintf(
                "%s %03d %s%s\n",
                $marker,
                $row['sequence'],
                $row['file'],
                $row['source'] ? ' (' . $row['source'] . ')' : ''
            );
        }
        echo "\nResumo: {$result['applied']} aplicada(s), {$result['pending']} pendente(s), " . count($result['drift']) . " divergente(s).\n";
        exit($result['drift'] === [] ? 0 : 2);
    }

    if (in_array($command, ['install', 'baseline', 'seed', 'bootstrap'], true) && !$yes) {
        throw new RuntimeException('Comando destrutivo/controlado exige confirmação explícita com --yes.');
    }

    if ($command === 'install') {
        $files = $service->install();
        echo '[OK] Instalação concluída. ' . count($files) . " migrations registradas.\n";
        exit(0);
    }

    if ($command === 'baseline') {
        $files = $service->baseline($through);
        echo '[OK] Baseline registrado até ' . $through . '. ' . count($files) . " entradas adicionadas.\n";
        exit(0);
    }

    if ($command === 'seed') {
        $service->seed();
        echo "[OK] Dados de referência reconciliados.\n";
        exit(0);
    }

    if ($command === 'up') {
        $files = $service->up($dryRun);
        if ($dryRun) {
            echo $files === [] ? "Nenhuma migration pendente.\n" : "Migrations que seriam executadas:\n- " . implode("\n- ", $files) . "\n";
            exit(0);
        }
        echo $files === [] ? "[OK] Banco já atualizado.\n" : '[OK] ' . count($files) . " migration(s) executada(s):\n- " . implode("\n- ", $files) . "\n";
        exit(0);
    }

    if ($command === 'bootstrap') {
        $files = $service->bootstrap();
        echo $files === [] ? "[OK] Banco já atualizado.\n" : '[OK] Bootstrap concluído. ' . count($files) . " ação(ões) registradas/executadas.\n";
        exit(0);
    }

    $usage();
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERRO] ' . $exception->getMessage() . "\n");
    if (filter_var(Env::get('APP_DEBUG', false), FILTER_VALIDATE_BOOL) && $exception->getPrevious()) {
        fwrite(STDERR, '[DEBUG] ' . $exception->getPrevious()->getMessage() . "\n");
    }
    exit(1);
}
