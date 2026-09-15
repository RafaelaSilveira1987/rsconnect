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

$service = $read('app/Services/OperationalLoadService.php');
$controller = $read('app/Controllers/OperationalLoadController.php');
$view = $read('app/Views/operational_load/index.php');
$routes = $read('routes/web.php');
$layout = $read('app/Views/layouts/app.php');
$css = $read('public/assets/css/app.css');
$version = $read('app/Services/AppVersionService.php');
$manifest = $read('manifest.json');
$guide = $read('TESTE_DA_VERSAO.md');

$check(str_contains($routes, "'/carga-operacional'") && str_contains($routes, "'/carga-operacional/snapshot'"), 'Rotas da carga operacional e snapshot foram registradas.');
$check(str_contains($routes, "permission:conversations.view"), 'A tela reutiliza a permissão de visualização das conversas.');
$check(str_contains($layout, '>Carga operacional<') && str_contains($layout, "Auth::can('conversations.view')"), 'Menu exibe Carga operacional sem depender da permissão de fila.');
$check(str_contains($service, 'final class OperationalLoadService') && str_contains($service, 'SlaPolicyService'), 'Serviço dedicado reutiliza a política oficial de SLA.');
$check(str_contains($service, "c.status IN (\"open\",\"pending\")") && str_contains($service, 'c.assigned_user_id IS NULL'), 'Carga considera conversas ativas e identifica sem responsável.');
$check(str_contains($service, "'sla_warning'") && str_contains($service, "'sla_breached'") && str_contains($service, "'awaiting_first_response'"), 'Resumo operacional contém risco, violação e primeira resposta pendente.');
$check(str_contains($service, "'breached' => 'SLA violado'") && str_contains($service, "'warning' => 'SLA em risco'"), 'Estados visuais seguem a mesma nomenclatura da Fase C.');
$check(str_contains($service, 'TenantLifecycleService') && str_contains($service, 'isLive'), 'SLA oficial respeita o Go-Live do tenant.');
$check(!str_contains($service, 'service_departments') && !str_contains($service, 'service_department_members'), 'Carga operacional não depende de filas ou setores.');
$check(str_contains($view, 'Sem responsável') && str_contains($view, 'Em atendimento humano') && str_contains($view, 'Aguardando 1ª resposta'), 'KPIs principais estão presentes na interface.');
$check(str_contains($view, 'Carga por responsável') && str_contains($view, 'Conversas que pedem atenção'), 'Tela contém visão de equipe e lista acionável.');
$check(str_contains($view, "setInterval(check, 30000)"), 'Snapshot verifica alterações automaticamente a cada 30 segundos.');
$check(str_contains($css, 'RS Connect 36.35.0') && str_contains($css, '.operational-load-kpis'), 'CSS responsivo da nova tela foi incluído.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.35.0 — Production Readiness: Carga operacional'") && str_contains($version, "REQUIRED_MIGRATION = '116_sla_trigger_mysql_compat.sql'"), 'Versão foi atualizada sem migration nova.');
$check(str_contains($manifest, '"package_version": "36.35.0"') && str_contains($manifest, '"required_migration": "116_sla_trigger_mysql_compat.sql"'), 'Manifesto identifica a Fase D e mantém migration 116.');
$check(str_contains($guide, 'Cenário A — conversa sem responsável') && str_contains($guide, 'Cenário E — SLA violado') && str_contains($guide, 'nenhuma configuração de fila é exigida'), 'Roteiro de homologação cobre a operação sem filas.');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} falha(s); {$passes} verificação(ões) aprovada(s).\n");
    exit(1);
}

echo "\nResumo carga operacional 36.35.0: {$passes} verificações aprovadas.\n";
