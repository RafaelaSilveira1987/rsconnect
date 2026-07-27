<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}

require_once __DIR__ . '/../app/Services/PreSchedulingService.php';
require_once __DIR__ . '/../app/Services/AutomationWebhookService.php';

use App\Services\AutomationWebhookService;
use App\Services\PreSchedulingService;

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) $failures[] = $label;
};

$pre = new PreSchedulingService();
$detect = new ReflectionMethod($pre, 'detectIntent');
$detect->setAccessible(true);
$agendaCases = [
    ['Reunião amanhã às 10h', false, false],
    ['Consulta sexta à tarde', false, false],
    ['Tenho reunião hoje', false, false],
    ['Quero marcar uma reunião amanhã às 10h', false, true],
    ['Preciso de um horário amanhã', false, true],
    ['Pode ser terça às 15h', true, true],
];
foreach ($agendaCases as [$text, $continuation, $expected]) {
    $result = $detect->invoke($pre, $text, $continuation);
    $assert((bool) ($result['has_intent'] ?? false) === $expected, 'agenda: ' . $text);
}

$automation = new AutomationWebhookService();
$flowAllows = new ReflectionMethod($automation, 'flowAllowsEvent');
$flowAllows->setAccessible(true);
$agendaFlow = [
    'flow_key' => 'agenda-google-calendar',
    'template_key' => null,
    'name' => 'RS Connect - Agenda Google Calendar por Empresa',
    'events_json' => json_encode(['*']),
];
$assert($flowAllows->invoke($automation, $agendaFlow, 'calendar.appointment.created') === true, 'agenda google aceita criacao real');
$assert($flowAllows->invoke($automation, $agendaFlow, 'message.received') === false, 'agenda google bloqueia mensagem comum');
$assert($flowAllows->invoke($automation, $agendaFlow, 'appointment.pre_scheduled') === false, 'agenda google bloqueia pre-schedule sem contrato de evento');
$assert($flowAllows->invoke($automation, $agendaFlow, 'calendar.appointment.status_updated') === false, 'agenda google nao duplica evento em mudanca de status');

$genericFlow = [
    'flow_key' => 'crm-google-sheets',
    'name' => 'CRM Google Sheets',
    'events_json' => json_encode(['*']),
];
$assert($flowAllows->invoke($automation, $genericFlow, 'message.received') === true, 'wildcard generico continua compativel');

$agendaTemplate = json_decode((string) file_get_contents(__DIR__ . '/../docs/n8n_templates/template-agenda-google-calendar.json'), true);
$assert(is_array($agendaTemplate), 'template agenda JSON valido');
$nodeNames = array_map(static fn(array $node): string => (string) ($node['name'] ?? ''), $agendaTemplate['nodes'] ?? []);
$assert(in_array('É compromisso real?', $nodeNames, true), 'template agenda possui gate de compromisso');
$assert(in_array('Ignorar evento sem agenda', $nodeNames, true), 'template agenda responde eventos ignorados');
$normalizerCode = '';
foreach (($agendaTemplate['nodes'] ?? []) as $node) {
    if (($node['name'] ?? '') === 'Normalizar Agenda') { $normalizerCode = (string) ($node['parameters']['jsCode'] ?? ''); break; }
}
$assert(str_contains($normalizerCode, 'calendar_appointment_v1'), 'template agenda exige contrato assinado pelo RS Connect');
$assert(str_contains($normalizerCode, 'appointmentId > 0'), 'template agenda exige appointment_id real');
$assert(!str_contains($normalizerCode, "title: appointment.title || 'Compromisso RS Connect'"), 'template nao pode inventar titulo generico para mensagem comum');

$automationSource = (string) file_get_contents(__DIR__ . '/../app/Services/AutomationWebhookService.php');
$assert(str_contains($automationSource, 'explicitTargetGuard'), 'URL legada direta deve respeitar contrato do fluxo cadastrado');
$assert(str_contains($automationSource, 'Evento '), 'bloqueio de contrato deve ser auditavel');

$flowControllerSource = (string) file_get_contents(__DIR__ . '/../app/Controllers/N8nFlowController.php');
$assert(str_contains($flowControllerSource, "['calendar.appointment.created']"), 'Admin deve forçar contrato do writer de Google Calendar ao salvar fluxo');

$backupTemplate = json_decode((string) file_get_contents(__DIR__ . '/../docs/n8n_templates/template-backup-rsconnect.json'), true);
$assert(is_array($backupTemplate), 'template backup JSON valido');
$callback = null;
foreach (($backupTemplate['nodes'] ?? []) as $node) {
    if (($node['name'] ?? '') === 'Callback RS Connect') { $callback = $node; break; }
}
$headers = $callback['parameters']['headerParameters']['parameters'] ?? [];
$headerNames = array_map(static fn(array $header): string => (string) ($header['name'] ?? ''), $headers);
$assert(in_array('X-RS-Backup-Token', $headerNames, true), 'backup usa header dedicado');
$assert(in_array('X-RS-Connect-Token', $headerNames, true), 'backup mantem header legado compativel');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - agenda só cria compromisso real e callback de backup possui autenticação redundante.\n";
