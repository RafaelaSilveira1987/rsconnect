<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('u', 32));
$_SERVER['APP_KEY'] = $_ENV['APP_KEY'];
$_ENV['APP_URL'] = 'https://rsconnect.example';
$_SERVER['APP_URL'] = $_ENV['APP_URL'];

require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app');

use App\Core\PublicId;
use App\Core\Router;

$tenantUuid = PublicId::encode('tenant', 2);
$contactUuid = PublicId::encode('contact', 25);
$conversationUuid = PublicId::encode('conversation', 91);

$tampered = substr($tenantUuid, 0, -1) . (substr($tenantUuid, -1) === 'a' ? 'b' : 'a');
$publicContactUrl = Router::url('/contacts?tenant_id=2&contact_id=25');
$publicCompanyUrl = Router::url('/companies/overview?id=2');
$publicConversationUrl = Router::url('/conversations?tenant_id=2&conversation_id=91');
$publicIcsUrl = Router::url('/calendar/ics?id=44&tenant_id=2');
$publicOperationsUrl = Router::url('/central-operacao?diagnostico=evolution&tenant=2');
$whiteLabelLoginUrl = Router::url('/login?tenant=empresa-slug');

$_GET = ['tenant_uuid' => $tenantUuid, 'contact_uuid' => $contactUuid];
$_POST = [];
$hydrated = PublicId::hydrateRequest('/contacts');
$hydratedTenantId = $_GET['tenant_id'] ?? 0;
$hydratedContactId = $_GET['contact_id'] ?? 0;
$publicAliasesRemoved = !isset($_GET['tenant_uuid'], $_GET['contact_uuid']);

$_GET = ['company_uuid' => $tenantUuid];
$companyHydrated = PublicId::hydrateRequest('/companies/overview');
$companyInternalId = $_GET['id'] ?? 0;

$_GET = ['tenant_uuid' => $tenantUuid];
$operationsHydrated = PublicId::hydrateRequest('/central-operacao');
$operationsTenantId = $_GET['tenant'] ?? 0;

$_GET = ['user_uuid' => '', 'start' => '2026-07-31', 'end' => '2026-07-31'];
$emptyOptionalUuidHydrated = PublicId::hydrateRequest('/reports/team');
$emptyOptionalUuidRemoved = !isset($_GET['user_uuid'])
    && ($_GET['start'] ?? '') === '2026-07-31'
    && ($_GET['end'] ?? '') === '2026-07-31';

$_GET = ['user_uuid' => 'uuid-invalido'];
$invalidFilledUuidRejected = !PublicId::hydrateRequest('/reports/team');

$routerSource = (string) file_get_contents($root . '/app/Core/Router.php');
$instanceController = (string) file_get_contents($root . '/app/Controllers/InstanceController.php');
$conversationController = (string) file_get_contents($root . '/app/Controllers/ConversationController.php');
$conversationView = (string) file_get_contents($root . '/app/Views/conversations/index.php');
$javascript = (string) file_get_contents($root . '/public/assets/js/app.js');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'UUID público segue formato RFC 4122 v4' => PublicId::isUuid($tenantUuid),
    'UUID é estável para o mesmo registro' => PublicId::encode('tenant', 2) === $tenantUuid,
    'UUID muda entre entidades e registros' => $tenantUuid !== $contactUuid && $tenantUuid !== $conversationUuid,
    'UUID retorna ao ID interno correto' => PublicId::decode('tenant', $tenantUuid) === 2,
    'UUID é vinculado ao tipo da entidade' => PublicId::decode('contact', $tenantUuid) === null,
    'UUID adulterado é rejeitado' => PublicId::decode('tenant', $tampered) === null,
    'URL de contatos não expõe tenant_id numérico' => str_contains($publicContactUrl, 'tenant_uuid=' . $tenantUuid)
        && str_contains($publicContactUrl, 'contact_uuid=' . $contactUuid)
        && !str_contains($publicContactUrl, 'tenant_id=2')
        && !str_contains($publicContactUrl, 'contact_id=25'),
    'parâmetro id de empresa vira company UUID' => str_contains($publicCompanyUrl, 'company_uuid=' . $tenantUuid)
        && !str_contains($publicCompanyUrl, 'id=2'),
    'conversa usa UUID público' => str_contains($publicConversationUrl, 'conversation_uuid=' . $conversationUuid)
        && !str_contains($publicConversationUrl, 'conversation_id=91'),
    'arquivo ICS usa appointment UUID' => str_contains($publicIcsUrl, 'appointment_uuid=' . PublicId::encode('appointment', 44))
        && !str_contains($publicIcsUrl, 'id=44'),
    'central de operação protege tenant numérico ambíguo' => str_contains($publicOperationsUrl, 'tenant_uuid=' . $tenantUuid)
        && !str_contains($publicOperationsUrl, 'tenant=2'),
    'slug white-label continua legível e funcional' => str_contains($whiteLabelLoginUrl, 'tenant=empresa-slug')
        && !str_contains($whiteLabelLoginUrl, 'tenant_uuid='),
    'requisição pública é hidratada para controllers legados' => $hydrated
        && $hydratedTenantId === 2
        && $hydratedContactId === 25
        && $publicAliasesRemoved,
    'alias específico de empresa volta ao parâmetro id legado' => $companyHydrated && $companyInternalId === 2,
    'alias de tenant na central volta ao parâmetro tenant legado' => $operationsHydrated && $operationsTenantId === 2,
    'UUID opcional vazio é ignorado sem gerar falso 404' => $emptyOptionalUuidHydrated && $emptyOptionalUuidRemoved,
    'UUID preenchido e inválido continua rejeitado' => $invalidFilledUuidRejected,
    'router redireciona links numéricos antigos' => str_contains($routerSource, 'hasLegacyPublicQuery')
        && str_contains($routerSource, 'PublicId::hydrateRequest'),
    'webhook Evolution novo recebe UUID' => str_contains($instanceController, "Router::url('/webhooks/evolution?instance_id='"),
    'JSON de conversa entrega UUID público' => str_contains($conversationController, "'public_id' => PublicId::encode('conversation'")
        && str_contains($conversationController, "'conversation_public_id'"),
    'histórico do navegador usa conversation UUID' => str_contains($javascript, "url.searchParams.set('conversation_uuid', selectedConversationPublicId)")
        && str_contains($conversationView, 'data-conversation-public-id'),
    'cache e versão atualizados' => str_contains($layout, 'app.js?v=36.15.1')
        && str_contains($layout, 'app.css?v=36.15.1')
        && str_contains($version, 'RS Connect 36.15.1'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - IDs numéricos protegidos por UUID público, links antigos compatíveis e URLs canônicas sem PKs sequenciais.\n";
