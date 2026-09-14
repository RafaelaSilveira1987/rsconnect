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

require_once $root . '/app/Core/Clock.php';
require_once $root . '/app/Services/SlaPolicyService.php';

use App\Services\SlaPolicyService;

$reflection = new ReflectionClass(SlaPolicyService::class);
/** @var SlaPolicyService $service */
$service = $reflection->newInstanceWithoutConstructor();
$policy = [
    'enabled' => 1,
    'target_minutes' => 30,
    'warning_percent' => 80,
    'count_outside_business_hours' => 0,
    'timezone' => 'America/Sao_Paulo',
    'business_hours_json' => '{"days":["mon","tue","wed","thu","fri"],"start":"08:00","end":"18:00"}',
];

$check(
    $service->elapsedSeconds(1, '2026-09-14 11:00:00', '2026-09-14 11:24:00', $policy) === 1440,
    'Relógio de expediente mede 24 minutos entre 08:00 e 08:24 local.'
);
$warning = $service->state(1, '2026-09-14 11:00:00', null, $policy, '2026-09-14 11:24:00');
$check(($warning['status'] ?? '') === 'warning' && (int) ($warning['percent'] ?? 0) === 80, '80% da meta gera estado preventivo warning.');
$breached = $service->state(1, '2026-09-14 11:00:00', null, $policy, '2026-09-14 11:30:00');
$check(($breached['status'] ?? '') === 'breached', '100% da meta sem resposta gera SLA violado.');
$met = $service->state(1, '2026-09-14 11:00:00', '2026-09-14 11:29:59', $policy);
$check(($met['status'] ?? '') === 'met', 'Resposta humana antes da meta encerra o ciclo dentro do SLA.');
$weekend = $service->elapsedSeconds(1, '2026-09-18 22:00:00', '2026-09-21 11:10:00', $policy);
$check($weekend === 600, 'Mensagem de sexta após o expediente consome somente 10 min na abertura de segunda.');
$overnight = $service->elapsedSeconds(1, '2026-09-15 01:00:00', '2026-09-15 11:05:00', $policy);
$check($overnight === 300, 'Madrugada fica pausada e o relógio acumula 5 min após a abertura.');
$continuous = $policy;
$continuous['count_outside_business_hours'] = 1;
$check(
    $service->elapsedSeconds(1, '2026-09-15 01:00:00', '2026-09-15 11:05:00', $continuous) === 36300,
    'Modo relógio corrido contabiliza também o tempo fora do expediente.'
);

$migration = $read('database/migrations/115_sla_operational_policy.sql');
$manifestMigrations = $read('database/migrations/manifest.php');
$onboarding = $read('app/Views/onboarding/index.php');
$conversation = $read('app/Views/conversations/index.php');
$conversationController = $read('app/Controllers/ConversationController.php');
$js = $read('public/assets/js/app.js');
$executive = $read('app/Services/ExecutiveMetricsPolicyService.php');
$team = $read('app/Services/TeamProfessionalReportService.php');
$reportController = $read('app/Controllers/ReportController.php');
$version = $read('app/Services/AppVersionService.php');
$manifest = $read('manifest.json');
$guide = $read('TESTE_DA_VERSAO.md');

$check(str_contains($migration, 'CREATE TABLE IF NOT EXISTS tenant_sla_settings') && str_contains($migration, 'sla_warning_percent'), 'Migration persiste política e snapshot do SLA.');
$check(str_contains($manifestMigrations, "['sequence' => 122, 'file' => '115_sla_operational_policy.sql']"), 'Manifesto registra migration 115 na sequência 122.');
$check(str_contains($onboarding, 'sla_target_minutes') && str_contains($onboarding, 'sla_warning_percent') && str_contains($onboarding, 'sla_count_outside_hours'), 'Onboarding permite configurar meta, alerta e relógio fora do expediente.');
$check(str_contains($conversation, 'SLA em risco') && str_contains($conversation, 'SLA violado') && str_contains($conversation, 'conversation-sla-banner'), 'Caixa de entrada possui estados visuais de risco e violação.');
$check(str_contains($conversationController, 'SlaPolicyService') && str_contains($conversationController, 'TenantLifecycleService') && str_contains($conversationController, 'isLive'), 'Alertas de SLA são calculados apenas para tenant em produção.');
$check(str_contains($js, 'slaStatusMemory') && str_contains($js, 'SLA em risco') && str_contains($js, 'SLA violado'), 'Polling notifica transições do SLA em tempo real.');
$check(substr_count($executive, 'elapsedSeconds(') >= 3 && str_contains($executive, 'SlaPolicyService'), 'Relatório executivo usa relógio operacional para resposta e espera.');
$check(substr_count($team, 'elapsedSeconds(') >= 4 && str_contains($team, 'slaPolicy'), 'Relatório de equipe usa a mesma política operacional.');
$check(str_contains($reportController, 'resolvedSlaMinutes') && str_contains($reportController, "['target_minutes']"), 'Relatórios usam a meta persistida como padrão quando não há override.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.34.0 — Production Readiness: SLA operacional'") && str_contains($version, "REQUIRED_MIGRATION = '115_sla_operational_policy.sql'"), 'Versão e migration obrigatória estão atualizadas.');
$check(str_contains($manifest, '"package_version": "36.34.0"') && str_contains($manifest, '115_sla_operational_policy.sql'), 'manifest.json identifica a Fase C.');
$check(str_contains($guide, 'Cenário B — alerta preventivo em 80%') && str_contains($guide, 'IA não encerra o SLA humano') && str_contains($guide, 'Critérios para aprovar a Fase C'), 'Roteiro cobre alerta, IA, expediente e critérios de homologação.');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} falha(s); {$passes} verificação(ões) aprovada(s).\n");
    exit(1);
}

echo "\nResumo SLA operacional 36.34.0: {$passes} verificações aprovadas.\n";
