<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/Services/CalendarGoogleLifecycleService.php') ?: '';
$controller = file_get_contents($root . '/app/Controllers/CalendarAvailabilityController.php') ?: '';
$view = file_get_contents($root . '/app/Views/calendar_availability/index.php') ?: '';
$templateRaw = file_get_contents($root . '/docs/n8n_templates/template-calendar-maintenance.json') ?: '';
$template = json_decode($templateRaw, true);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FALHA: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($controller, 'HTTP_X_RS_CALENDAR_MAINTENANCE_TOKEN'), 'endpoint deve aceitar token por header');
$assert(str_contains($controller, 'HTTP_X_RS_AUTOMATION_ORIGIN'), 'endpoint deve registrar origem da automação');
$assert(substr_count($service, 'responded_at IS NULL') >= 2, 'card e rotina devem usar responded_at IS NULL');
$assert(str_contains($service, 'startRun($currentTenantId, $origin)'), 'execução global deve registrar resultado por empresa');
$assert(str_contains($view, 'Execução manual direta no RS Connect — não chama o n8n.'), 'tela deve explicar que botão manual não chama n8n');
$assert(str_contains($view, 'Callbacks pendentes vencidos'), 'card deve deixar claro que conta somente pendências');
$assert(is_array($template), 'template n8n deve ser JSON válido');

$headers = [];
foreach (($template['nodes'] ?? []) as $node) {
    if (($node['id'] ?? '') === 'CallCalendarMaintenance') {
        foreach (($node['parameters']['headerParameters']['parameters'] ?? []) as $header) {
            $headers[(string) ($header['name'] ?? '')] = (string) ($header['value'] ?? '');
        }
    }
}
$assert(($headers['X-RS-Calendar-Maintenance-Token'] ?? '') !== '', 'template deve enviar token de manutenção');
$assert(($headers['X-RS-Automation-Origin'] ?? '') === 'n8n', 'template deve identificar origem n8n');

echo "OK - manutenção manual/automática, callbacks e histórico alinhados.\n";
