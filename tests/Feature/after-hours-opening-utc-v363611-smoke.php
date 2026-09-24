<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AiReplyTimingService;

$root = dirname(__DIR__, 2);
$failures = [];
$check = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$condition) $failures[] = $label;
};

$timing = new AiReplyTimingService();
$now = new DateTimeImmutable('2026-09-24 09:06:00', new DateTimeZone('America/Sao_Paulo'));
$check(
    $timing->remainingSeconds(120, '2026-09-24 11:32:00', null, $now) === 0,
    'mensagem das 08:32 local (11:32 UTC) está liberada às 09:06'
);

$nowAtOpening = new DateTimeImmutable('2026-09-24 09:00:10', new DateTimeZone('America/Sao_Paulo'));
$check(
    $timing->remainingSeconds(60, '2026-09-24 11:59:50', null, $nowAtOpening) === 40,
    'mensagem recebida 10s antes da abertura ainda respeita o silêncio restante'
);

$monitor = (string) file_get_contents($root . '/app/Services/AfterHoursMonitorService.php');
$recovery = (string) file_get_contents($root . '/app/Services/AiAfterHoursRecoveryService.php');
$ack = (string) file_get_contents($root . '/app/Services/AfterHoursAcknowledgementPolicyService.php');
$check(str_contains($monitor, 'storageTimestamp') && str_contains($monitor, 'Clock::STORAGE_TIMEZONE'), 'monitor interpreta last_run_at como UTC');
$check(str_contains($recovery, 'Clock::STORAGE_TIMEZONE') && str_contains($recovery, 'Clock::fromUnixUtc'), 'recuperação pós-horário usa UTC em expiração e fallback');
$check(str_contains($ack, 'Clock::STORAGE_TIMEZONE'), 'deduplicação do aviso lê timestamps técnicos em UTC');


$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$manifest = (string) file_get_contents($root . '/manifest.json');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.36.11 — Retomada pós-horário no fuso correto'"), 'pacote identifica o hotfix 36.36.11');
$check(str_contains($version, "REQUIRED_MIGRATION = '118_contact_origin.sql'"), 'hotfix não exige migration nova');
$check(str_contains($manifest, '"package_version": "36.36.11"'), 'manifest identifica 36.36.11');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - abertura do expediente e cooldown UTC validados.\n";
