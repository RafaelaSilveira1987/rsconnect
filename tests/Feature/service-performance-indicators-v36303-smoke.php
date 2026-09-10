<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => is_file($root . '/' . $path) ? (string) file_get_contents($root . '/' . $path) : '';
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$controller = $read('app/Controllers/ReportController.php');
$policy = $read('app/Services/ExecutiveMetricsPolicyService.php');
$admin = $read('app/Services/AdminExecutiveReportService.php');
$tenant = $read('app/Services/TenantExecutiveReportService.php');
$team = $read('app/Services/TeamProfessionalReportService.php');
$clientView = $read('app/Views/reports/index.php');
$adminView = $read('app/Views/reports/admin.php');
$teamView = $read('app/Views/reports/team.php');
$css = $read('public/assets/css/reports.css');
$version = $read('app/Services/AppVersionService.php');
$layout = $read('app/Views/layouts/app.php');
$docs = $read('docs/ATUALIZACAO-v36.30.3.md');

$check(substr_count($controller, "'sla_minutes' => max(5, min(1440") >= 2, 'Filtros não normalizam a meta de SLA.');
$check(str_contains($policy, 'function operationalServiceMetrics') && str_contains($policy, 'avg_service_duration_seconds') && str_contains($policy, 'waiting_over_sla'), 'Política executiva não calcula duração/SLA/espera.');
$check(str_contains($policy, 'first_response_user_id IS NOT NULL'), 'SLA executivo não está restrito à primeira resposta humana.');
$check(str_contains($admin, 'operationalServiceMetrics') && str_contains($tenant, 'operationalServiceMetrics'), 'Relatórios executivo/admin não usam a política de serviço.');
$check(str_contains($team, 'function serviceQuality') && str_contains($team, 'function serviceDurations') && str_contains($team, 'function currentWaiting'), 'Relatório de equipe não possui métricas de serviço.');
$check(str_contains($team, 'function departmentPerformance') && str_contains($team, 'function departmentMemberships'), 'Relatório de equipe não integra setores.');
$check(str_contains($team, "enabled(\$tenantId, 'queue')"), 'Carga por setor não respeita o módulo opcional de fila.');
$check(str_contains($controller, 'sla_primeira_resposta_percentual') && str_contains($controller, 'tempo_medio_ciclo_atendimento_segundos') && str_contains($controller, 'dentro_sla'), 'CSV não exporta os novos indicadores.');
$check(str_contains($clientView, 'SLA da 1ª resposta humana') && str_contains($clientView, 'Tempo médio do ciclo de atendimento'), 'Painel do cliente não exibe SLA/duração.');
$check(str_contains($adminView, 'SLA da 1ª resposta humana') && str_contains($adminView, 'Aguardando 1ª resposta agora'), 'Painel RS Admin não exibe indicadores de atendimento.');
$check(str_contains($teamView, 'Carga atual por setor') && str_contains($teamView, 'team-report-department-grid'), 'Relatório por setor não foi exposto no frontend.');
$check(str_contains($teamView, 'name="sla_minutes"') && str_contains($teamView, 'Dentro do SLA'), 'Relatório de equipe não permite auditar a meta de SLA.');
$check(str_contains($css, 'RS Connect 36.30.3 — indicadores operacionais de atendimento') && str_contains($css, '.team-report-department-grid'), 'CSS dos novos indicadores não foi adicionado.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.30.3 — Indicadores de atendimento'"), 'Versão 36.30.3 não registrada.');
$check(str_contains($version, "REQUIRED_MIGRATION = '107_service_department_memberships.sql'"), 'Migration obrigatória foi alterada indevidamente.');
$check(str_contains($layout, 'app.css?v=36.30.3') && str_contains($layout, 'app.js?v=36.30.3'), 'Cache principal não foi atualizado.');
$check(str_contains($docs, 'não atribui retroativamente desempenho histórico a setores'), 'Documentação não declara o limite da métrica histórica por setor.');

if ($failures !== []) {
    fwrite(STDERR, "FAIL service-performance-indicators-v36303-smoke\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK service-performance-indicators-v36303-smoke\n";
