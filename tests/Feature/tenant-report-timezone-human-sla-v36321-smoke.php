<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => is_file($root . '/' . $path) ? (string) file_get_contents($root . '/' . $path) : '';
$passes = 0;
$failures = 0;
$check = static function (bool $ok, string $message) use (&$passes, &$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $message . PHP_EOL;
    $ok ? $passes++ : $failures++;
};

require_once $root . '/app/Core/Env.php';
require_once $root . '/app/Core/Clock.php';

use App\Core\Clock;

$tenantReport = $read('app/Services/TenantExecutiveReportService.php');
$policy = $read('app/Services/ExecutiveMetricsPolicyService.php');
$migration = $read('database/migrations/113_human_first_response_report_consistency.sql');
$manifestMigrations = $read('database/migrations/manifest.php');
$version = $read('app/Services/AppVersionService.php');
$manifest = $read('manifest.json');
$guide = $read('TESTE_DA_VERSAO.md');

$range = Clock::localRangeToUtc('2026-09-11', '2026-09-11', 'America/Sao_Paulo');
$check($range['start'] === '2026-09-11 03:00:00', 'Início de 11/09 em São Paulo é convertido para 03:00 UTC.');
$check($range['end'] === '2026-09-12 02:59:59', 'Fim de 11/09 em São Paulo alcança 12/09 02:59:59 UTC.');

$check(str_contains($tenantReport, 'tenantTimezone($tenantId)') && str_contains($tenantReport, 'Clock::localRangeToUtc'), 'Relatório executivo converte o filtro local para UTC pelo fuso da empresa.');
$check(str_contains($tenantReport, 'report_daily_metrics v2 materializa dias em UTC') && str_contains($tenantReport, '$aggregateTotals = [];'), 'Cards do tenant não reutilizam cache diário UTC na correção 36.32.1.');
$check(str_contains($tenantReport, 'messageSeries') && str_contains($tenantReport, "Clock::utcToLocal(\$utcHour, \$timezone, 'Y-m-d')"), 'Séries diária/horária são remontadas no fuso local sem depender do timezone do MySQL.');
$check(str_contains($tenantReport, 'previousDateParams($filters, $timezone)'), 'Período comparativo usa o mesmo contrato de fuso do período principal.');

$firstResponseBlock = strstr($policy, 'public function operationalFirstResponses') ?: '';
$check(str_contains($firstResponseBlock, 'first_response_user_id IS NOT NULL'), 'Tempo médio exige atribuição humana real, igual ao SLA.');
$check(substr_count($policy, 'first_response_user_id IS NOT NULL') >= 2, 'Tempo médio e SLA compartilham o critério de resposta humana atribuída.');

$check(str_contains($migration, 'tmp_rs_first_human_cycle_response') && str_contains($migration, "sender_type = 'user'") && str_contains($migration, 'sender_user_id IS NOT NULL'), 'Migration repara ciclos a partir da primeira mensagem humana atribuída.');
$check(str_contains($migration, 'AFTER UPDATE ON conversation_messages') && str_contains($migration, 'trg_rs_messages_after_update_human_metrics'), 'Migration cobre corrida Evolution/painel via AFTER UPDATE.');
$check(str_contains($manifestMigrations, "['sequence' => 120, 'file' => '113_human_first_response_report_consistency.sql']"), 'Manifesto de migrations registra a sequência 120.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.32.1 — Hotfix de relatório e SLA humano'") && str_contains($version, "REQUIRED_MIGRATION = '113_human_first_response_report_consistency.sql'"), 'Versão 36.32.1 e migration obrigatória estão publicadas.');
$check(str_contains($manifest, '"package_version": "36.32.1"') && str_contains($manifest, '113_human_first_response_report_consistency.sql'), 'manifest.json registra a release 36.32.1.');
$check(str_contains($guide, '11/09/2026') && str_contains($guide, '1/1 em até 30 min') && str_contains($guide, '12/09'), 'Roteiro reproduz exatamente o teste de virada UTC e SLA observado na homologação.');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} falha(s); {$passes} verificação(ões) aprovada(s).\n");
    exit(1);
}

echo "\nResumo hotfix relatório/SLA 36.32.1: {$passes} verificações aprovadas.\n";
