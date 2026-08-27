<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Core/Env.php';
require_once __DIR__ . '/../../app/Services/BackupSchedulePolicyService.php';

use App\Services\BackupSchedulePolicyService;

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$policy = new BackupSchedulePolicyService();
$base = [
    'id' => 1,
    'frequency' => 'daily',
    'preferred_time' => '03:00',
    'timezone' => 'America/Sao_Paulo',
    'max_age_hours' => 24,
];

// Caso real do bug: houve solicitação hoje, mas o último sucesso continua velho.
$decision = $policy->evaluate($base + [
    'last_success_at' => '2026-07-25 12:00:00',
    'last_requested_at' => '2026-07-27 03:00:00',
], new DateTimeImmutable('2026-07-27 09:45:00', new DateTimeZone('America/Sao_Paulo')));
$assert($decision['due'] === true, 'tentativa antiga com backup vencido deve voltar a ficar elegivel no mesmo dia');
$assert($decision['reason'] === 'backup_overdue', 'backup acima de 24h deve ser classificado como vencido');

// Nova tentativa recente deve apenas aplicar cooldown, sem considerar o ciclo concluído.
$decision = $policy->evaluate($base + [
    'last_success_at' => '2026-07-25 12:00:00',
    'last_requested_at' => '2026-07-27 09:30:00',
], new DateTimeImmutable('2026-07-27 09:45:00', new DateTimeZone('America/Sao_Paulo')));
$assert($decision['due'] === false, 'nao deve disparar novamente dentro do cooldown de retry');
$assert($decision['reason'] === 'retry_cooldown', 'deve explicar que esta aguardando nova tentativa');
$assert(!empty($decision['next_retry_at']), 'deve informar quando podera tentar novamente');

// Depois do cooldown, tenta novamente sem esperar o dia seguinte.
$decision = $policy->evaluate($base + [
    'last_success_at' => '2026-07-25 12:00:00',
    'last_requested_at' => '2026-07-27 09:30:00',
], new DateTimeImmutable('2026-07-27 10:01:00', new DateTimeZone('America/Sao_Paulo')));
$assert($decision['due'] === true, 'apos cooldown a rotina vencida deve tentar novamente');

// Sucesso atual cobre a janela e impede duplicidade.
$decision = $policy->evaluate($base + [
    'last_success_at' => '2026-07-27 03:08:00',
    'last_requested_at' => '2026-07-27 03:00:00',
], new DateTimeImmutable('2026-07-27 09:45:00', new DateTimeZone('America/Sao_Paulo')));
$assert($decision['due'] === false, 'backup concluido hoje nao pode ser duplicado');
$assert($decision['reason'] === 'covered', 'sucesso atual deve cobrir a janela');

// Primeira execução ainda respeita o horário configurado.
$decision = $policy->evaluate($base + [
    'last_success_at' => null,
    'last_requested_at' => null,
], new DateTimeImmutable('2026-07-27 02:30:00', new DateTimeZone('America/Sao_Paulo')));
$assert($decision['due'] === false && $decision['reason'] === 'before_schedule', 'primeiro backup deve aguardar horario preferido');

// Depois do horário, primeira execução fica elegível.
$decision = $policy->evaluate($base + [
    'last_success_at' => null,
    'last_requested_at' => null,
], new DateTimeImmutable('2026-07-27 03:05:00', new DateTimeZone('America/Sao_Paulo')));
$assert($decision['due'] === true, 'primeiro backup deve disparar depois do horario');

$source = file_get_contents(__DIR__ . '/../../app/Services/BackupAutomationService.php') ?: '';
$assert(str_contains($source, "'evaluated' => \$evaluated"), 'dispatcher deve explicar por que cada rotina foi ou nao foi elegivel');
$assert(str_contains($source, "'eligible' =>"), 'dispatcher deve informar quantidade elegivel');
$assert(str_contains($source, 'BackupSchedulePolicyService'), 'dispatcher deve usar politica baseada em sucesso real');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - backup vencido volta a tentar no mesmo dia e last_requested_at virou apenas cooldown.\n";
