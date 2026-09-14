<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$ok = 0;
$fail = 0;
$check = static function (bool $condition, string $label) use (&$ok, &$fail): void {
    if ($condition) {
        $ok++;
        echo "[OK] {$label}\n";
        return;
    }
    $fail++;
    echo "[FAIL] {$label}\n";
};

$migration = $read('database/migrations/116_sla_trigger_mysql_compat.sql');
$manifest = $read('database/migrations/manifest.php');
$version = $read('app/Services/AppVersionService.php');
$package = $read('manifest.json');

$check(str_contains($migration, 'DROP TRIGGER IF EXISTS trg_rs_messages_after_insert_metrics'), 'Migration recria o trigger canônico de métricas.');
$check(str_contains($migration, 'SELECT active_cycle.id') && str_contains($migration, 'INTO active_cycle_id'), 'Ciclo ativo é selecionado antes do UPDATE.');
$check((bool) preg_match('/UPDATE\s+conversation_service_cycles\s+SET\s+first_incoming_at/i', $migration), 'Atualização de entrada é single-table.');
$check(str_contains($migration, 'WHERE id = active_cycle_id'), 'UPDATE é limitado pelo id do ciclo selecionado.');
$check(!preg_match('/UPDATE\s+conversation_service_cycles\s+active_cycle\s+LEFT\s+JOIN[\s\S]{0,1800}?ORDER\s+BY/i', $migration), 'Migration 116 não usa UPDATE multi-tabela com ORDER BY.');
$check(str_contains($migration, "NEW.direction = 'outgoing' AND NEW.sender_type = 'user'"), 'Primeira resposta humana permanece coberta.');
$check(str_contains($manifest, "['sequence' => 123, 'file' => '116_sla_trigger_mysql_compat.sql']"), 'Manifesto registra migration 116 na sequência 123.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.34.2 — Hotfix do recebimento Evolution e trigger de SLA'") && str_contains($version, "REQUIRED_MIGRATION = '116_sla_trigger_mysql_compat.sql'"), 'Versão exige a migration 116.');
$check(str_contains($package, '"package_version": "36.34.2"') && str_contains($package, '116_sla_trigger_mysql_compat.sql'), 'manifest.json identifica o hotfix 36.34.2.');

echo "Resumo: {$ok} OK, {$fail} falha(s).\n";
exit($fail === 0 ? 0 : 1);
