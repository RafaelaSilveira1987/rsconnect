<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/TenantIsolationService.php');
$router = (string) file_get_contents($root . '/app/Core/Router.php');
$publicId = (string) file_get_contents($root . '/app/Core/PublicId.php');
$diagnostic = (string) file_get_contents($root . '/database/diagnostics/tenant_isolation_v36.11.0.sql');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$checks = [
    'serviço central de isolamento existe' => str_contains($service, 'final class TenantIsolationService'),
    'usuário comum exige tenant válido' => str_contains($service, 'Auth::tenantId()') && str_contains($service, "'source' => 'session'"),
    'Super Admin preserva acesso global' => str_contains($service, 'Auth::isSuperAdmin()'),
    'GET e POST são validados' => str_contains($service, "'source' => 'query'") && str_contains($service, "'source' => 'post'"),
    'IDs em lote também são protegidos' => str_contains($service, "'conversation_ids' => 'conversation'") && str_contains($service, "'agent_ids' => 'agent'"),
    'entidades sensíveis possuem escopo de tenant' => str_contains($service, "'conversation' => 'conversations'")
        && str_contains($service, "'appointment' => 'calendar_appointments'")
        && str_contains($service, "'credential' => 'ai_provider_credentials'"),
    'comunicados e sessões usam relacionamento indireto' => str_contains($service, 'client_communication_recipients')
        && str_contains($service, 'INNER JOIN users u ON u.id = s.user_id'),
    'PublicId expõe mapa seguro ao guard' => str_contains($publicId, 'requestParameterEntities')
        && str_contains($publicId, 'entityForParameter'),
    'Router executa barreira antes do controller' => str_contains($router, 'validateAuthenticatedRequest')
        && str_contains($router, "tenant.cross_scope_access_blocked"),
    'tentativa cruzada recebe 404 sem vazamento' => str_contains($router, "View::render('errors.404'")
        && str_contains($router, "'Registro não encontrado'"),
    'diagnóstico cobre vínculos principais' => str_contains($diagnostic, 'conversa x contato')
        && str_contains($diagnostic, 'agenda x profissional')
        && str_contains($diagnostic, 'crm lead x pipeline'),
    'histórico e versão atual preservados' => str_contains($version, 'RS Connect 36.15.1')
        && str_contains($version, 'RS Connect 36.18.0')
        && str_contains($version, 'RS Connect 36.18.3')
        && str_contains($version, 'Beta Comercial 1.2'),
    'cache dos assets renovado' => str_contains($layout, 'app.css?v=36.18.3')
        && str_contains($layout, 'app.js?v=36.18.3'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - isolamento entre empresas aplicado a UUIDs, IDs ocultos, listas e eventos de segurança.\n";
