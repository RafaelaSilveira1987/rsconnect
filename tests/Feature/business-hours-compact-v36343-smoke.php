<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/Services/AgentOperatingPolicyService.php';

use App\Services\AgentOperatingPolicyService;

$passes = 0;
$failures = 0;
$check = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $passes++ : $failures++;
};

$service = new AgentOperatingPolicyService();
$compact = [
    'business_hours_enabled' => 1,
    'business_timezone' => 'America/Sao_Paulo',
    'business_hours_json' => json_encode([
        'days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
        'start' => '08:00',
        'end' => '18:00',
    ], JSON_UNESCAPED_SLASHES),
];

$monday = new DateTimeImmutable('2026-09-14 15:59:00', new DateTimeZone('America/Sao_Paulo'));
$status = $service->status($compact, $monday);
$check(($status['day'] ?? '') === 'mon', '14/09/2026 é interpretado como segunda-feira.');
$check(!empty($status['inside']), 'Formato compacto libera segunda às 15:59 dentro de 08:00–18:00.');
$check(($status['reason'] ?? '') === 'inside_business_hours', 'Motivo operacional é inside_business_hours.');
$check(($status['ranges'][0][0] ?? '') === '08:00' && ($status['ranges'][0][1] ?? '') === '18:00', 'Faixa compacta é normalizada para 08:00–18:00.');

$after = $service->status($compact, new DateTimeImmutable('2026-09-14 18:01:00', new DateTimeZone('America/Sao_Paulo')));
$check(empty($after['inside']) && ($after['reason'] ?? '') === 'outside_time_range', 'Segunda às 18:01 fica fora do expediente.');

$sunday = $service->status($compact, new DateTimeImmutable('2026-09-13 15:59:00', new DateTimeZone('America/Sao_Paulo')));
$check(empty($sunday['inside']) && ($sunday['reason'] ?? '') === 'day_closed', 'Domingo continua fechado no formato compacto.');

$next = $service->nextOpeningAt($compact, new DateTimeImmutable('2026-09-13 15:59:00', new DateTimeZone('America/Sao_Paulo')));
$check($next instanceof DateTimeImmutable && $next->format('Y-m-d H:i') === '2026-09-14 08:00', 'Próxima abertura do domingo é segunda às 08:00.');

$detailed = $compact;
$detailed['business_hours_json'] = json_encode(['mon' => [['08:00', '17:00']]], JSON_UNESCAPED_SLASHES);
$detailedStatus = $service->status($detailed, new DateTimeImmutable('2026-09-14 16:00:00', new DateTimeZone('America/Sao_Paulo')));
$check(!empty($detailedStatus['inside']), 'Formato detalhado já existente continua suportado.');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} falha(s); {$passes} verificação(ões) aprovada(s).\n");
    exit(1);
}

echo "\nResumo horário compacto 36.34.3: {$passes} verificações aprovadas.\n";
