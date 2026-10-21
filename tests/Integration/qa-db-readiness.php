<?php

declare(strict_types=1);

/** Somente leitura. Executar exclusivamente em homologação com autorização explícita. */
$environment = strtolower(trim((string) getenv('QA_TARGET')));
if ($environment !== 'homologation' || getenv('QA_ALLOW_DB') !== '1') {
    fwrite(STDERR, "BLOQUEADO: definir QA_TARGET=homologation e QA_ALLOW_DB=1.\n");
    exit(2);
}
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Database;
use App\Core\Env;

$appEnv = strtolower((string) Env::get('APP_ENV', ''));
$dbName = strtolower((string) Env::get('DB_DATABASE', ''));
if (in_array($appEnv, ['production', 'prod'], true) || preg_match('/(prod|production)/i', $dbName)) {
    fwrite(STDERR, "BLOQUEADO: a conexão aparenta ser de produção.\n");
    exit(2);
}
$pdo = Database::connection();
$pdo->query('SELECT 1')->fetchColumn();
$tables = ['calendar_appointments', 'calendar_internal_slots', 'calendar_internal_slot_allocations', 'tenant_triage_fields'];
$placeholders = implode(',', array_fill(0, count($tables), '?'));
$stmt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (' . $placeholders . ')');
$stmt->execute($tables);
$found = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
$missing = array_diff($tables, $found);
foreach ($tables as $table) {
    echo (in_array($table, $found, true) ? '[OK] ' : '[FALHA] ') . 'Tabela ' . $table . PHP_EOL;
}
if ($missing !== []) {
    fwrite(STDERR, 'Faltam tabelas necessárias. Confira as migrations em homologação.' . PHP_EOL);
    exit(1);
}
echo "OK — conexão e tabelas verificadas em modo somente leitura. Não testa concorrência.\n";
