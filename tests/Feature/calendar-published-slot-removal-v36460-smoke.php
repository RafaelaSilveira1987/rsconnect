<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('s', 32));
$_SERVER['APP_KEY'] = $_ENV['APP_KEY'];
$_ENV['APP_URL'] = 'https://rsconnect.example';
$_SERVER['APP_URL'] = $_ENV['APP_URL'];
require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app');

use App\Core\PublicId;
use App\Services\TenantIsolationService;

$route = '/calendar/availability/internal-slots/cancel';
$entities = PublicId::requestParameterEntities($route);
$other = PublicId::requestParameterEntities('/calendar/availability/apply');
$tables = (new ReflectionClass(TenantIsolationService::class))->getReflectionConstant('DIRECT_TABLES')->getValue();
$uuid = PublicId::encode('internal_slot', 321);
$routes = (string) file_get_contents($root . '/routes/web.php');
$view = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$service = (string) file_get_contents($root . '/app/Services/InternalCalendarSlotService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CalendarAvailabilityController.php');
$method = substr($service, strpos($service, 'public function cancelSlot('), strpos($service, 'public function availableForWindow(') - strpos($service, 'public function cancelSlot('));

$checks = [
    'remocao valida slot_id na entidade interna' => ($entities['slot_id'] ?? '') === 'internal_slot',
    'uuid especifico ligado ao tipo correto' => PublicId::decode('internal_slot', $uuid) === 321 && PublicId::decode('availability_slot', $uuid) === null,
    'alias especifico preserva integridade' => ($entities['internal_slot_uuid'] ?? '') === 'internal_slot',
    'outros usos de slot_id continuam inalterados' => ($other['slot_id'] ?? '') === 'availability_slot',
    'isolamento consulta calendar_internal_slots' => ($tables['internal_slot'] ?? '') === 'calendar_internal_slots',
    'rota POST autenticada CSRF e permissoes' => str_contains($routes, "post('" . $route . "'") && str_contains($routes, "'permission:calendar.manage', 'csrf'"),
    'botao envia tenant e slot ao controlador' => str_contains($view, "Router::url('" . $route . "')") && str_contains($view, 'name="slot_id"') && str_contains($view, 'Csrf::input()'),
    'controller invoca servico e redireciona' => str_contains($controller, 'function cancelInternalSlot(): void') && str_contains($controller, '->cancelSlot($tenantId, $slotId)'),
    'remocao trava horario antes de validar alocacoes' => strpos($method, 'FOR UPDATE') !== false && strpos($method, 'FOR UPDATE') < strpos($method, 'SELECT COUNT(*) FROM calendar_internal_slot_allocations'),
    'remocao nao permite reservas confirmadas' => str_contains($method, 'state IN ("held","booked")') && str_contains($method, 'if ((int) $count->fetchColumn() > 0)'),
    'operacao transacional finalizada somente depois do update' => str_contains($method, '$pdo->beginTransaction()') && str_contains($method, '$pdo->commit()') && str_contains($method, '$pdo->rollBack()'),
    'sem DELETE destrutivo de horarios e reservas' => !str_contains($method, 'DELETE FROM calendar_internal_slots') && !str_contains($method, 'DELETE FROM calendar_internal_slot_allocations'),
    'exclusao sempre isolada por empresa' => str_contains($method, 'WHERE id = :id AND tenant_id = :tenant_id'),
];
$failed = [];
foreach ($checks as $name => $pass) {
    echo ($pass ? '[OK] ' : '[FALHA] ') . $name . PHP_EOL;
    if (!$pass) $failed[] = $name;
}
if ($failed) {
    fwrite(STDERR, 'Falhas: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK - Exclusao publicada mapeada ao tenant e protegida por transacao.' . PHP_EOL;
