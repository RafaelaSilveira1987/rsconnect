<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$clock = file_get_contents($root . '/app/Core/Clock.php') ?: '';
$database = file_get_contents($root . '/app/Core/Database.php') ?: '';
$webhook = file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php') ?: '';
$conversation = file_get_contents($root . '/app/Controllers/ConversationController.php') ?: '';
$team = file_get_contents($root . '/app/Services/TeamProfessionalReportService.php') ?: '';
$migration = file_get_contents($root . '/database/migrations/071_utc_datetime_contract_compat.sql') ?: '';
$diagnostic = file_get_contents($root . '/database/diagnostics/utc_datetime_contract_v36.10.4.sql') ?: '';

$checks = [
    'clock_now_utc' => str_contains($clock, 'function nowUtc') && str_contains($clock, "STORAGE_TIMEZONE = 'UTC'"),
    'clock_local_range' => str_contains($clock, 'function localRangeToUtc') && str_contains($clock, 'function utcToLocal'),
    'pdo_session_utc' => str_contains($database, "SET SESSION time_zone = '+00:00'"),
    'evolution_unix_utc' => str_contains($webhook, 'Clock::fromUnixUtc'),
    'human_message_utc' => str_contains($conversation, 'Clock::nowUtc()'),
    'conversation_display_local' => str_contains($conversation, 'Clock::formatUtc($dateTime, \'d/m H:i\')'),
    'team_report_utc_filter' => str_contains($team, 'localRangeToUtc') && str_contains($team, "'utc_start'"),
    'team_report_local_daily_group' => str_contains($team, 'localDateKey') && str_contains($team, 'occurred_at_local'),
    'migration_contract_table' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS rs_datetime_contract'),
    'migration_idempotent_normalization' => str_contains($migration, '@rs_normalize_history') && str_contains($migration, 'historical_normalized_at_utc'),
    'migration_utc_triggers' => substr_count($migration, 'UTC_TIMESTAMP()') >= 20,
    'diagnostic_available' => str_contains($diagnostic, '@@SESSION.time_zone') && str_contains($diagnostic, 'first_response_seconds'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, 'Falhas: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'OK: contrato UTC e conversão para o fuso da empresa validados.' . PHP_EOL;
