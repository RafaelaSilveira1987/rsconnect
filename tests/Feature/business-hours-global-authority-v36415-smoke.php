<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/Services/AgentOperatingPolicyService.php';

use App\Services\AgentOperatingPolicyService;

$passes = 0;
$failures = [];
$check = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($ok) {
        $passes++;
    } else {
        $failures[] = $label;
    }
};

// Replica o cenário visual homologado em 01/10/2026: quinta abre às 09:00,
// mas o contato envia uma mensagem às 08:02 no fuso de São Paulo.
$agent = [
    'business_hours_enabled' => 1,
    'business_timezone' => 'America/Sao_Paulo',
    'business_hours_json' => json_encode([
        'thu' => [['09:00', '20:00']],
        'fri' => [['09:00', '20:00']],
        'sat' => [['08:00', '12:00']],
    ], JSON_UNESCAPED_SLASHES),
];
$policy = new AgentOperatingPolicyService();
$at0802 = new DateTimeImmutable('2026-10-01 08:02:00', new DateTimeZone('America/Sao_Paulo'));
$status = $policy->status($agent, $at0802);
$next = $policy->nextOpeningAt($agent, $at0802);

$check(!empty($status['enforced']), 'Restrição de horário está tecnicamente ativa.');
$check(empty($status['inside']) && ($status['reason'] ?? '') === 'outside_time_range', 'Quinta às 08:02 fica fora do expediente 09:00–20:00.');
$check($next instanceof DateTimeImmutable && $next->format('Y-m-d H:i') === '2026-10-01 09:00', 'Próxima abertura calculada é a própria quinta às 09:00.');

$webhook = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$existing = (string) file_get_contents($root . '/app/Services/ExistingAppointmentConversationService.php');
$automation = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$settingsView = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true);

$queuePos = strpos($webhook, 'AiAfterHoursRecoveryService())->markPending');
$outsideHandledPos = strpos($webhook, "} elseif (\$outsideBusinessHours) {");
$normalExistingPos = strpos($webhook, 'antes da triagem de um novo atendimento');
$check($queuePos !== false && $outsideHandledPos !== false && $queuePos < $outsideHandledPos, 'Webhook grava/atualiza a fila antes de encerrar o processamento fora do horário.');
$check($normalExistingPos !== false && $outsideHandledPos < $normalExistingPos, 'Consulta de compromisso existente fica restrita ao caminho de expediente aberto.');
$check(!str_contains($webhook, 'existing_appointment_lookup_after_hours'), 'Webhook não possui mais atalho de Agenda que responda fora do horário.');
$check(str_contains($webhook, "'skip_ai' => true") && str_contains($webhook, "'outside_business_hours' => true"), 'Fora do horário bloqueia IA e encerra o turno como pendência operacional.');

$guardPos = strpos($existing, 'if ($outsideBusinessHours) {');
$communicationPos = strpos($existing, '$communication = new CalendarClientCommunicationService();');
$check($guardPos !== false && $communicationPos !== false && $guardPos < $communicationPos, 'Serviço de compromisso existente também falha fechado fora do horário.');
$check(str_contains($automation, 'processSchedulingDuringReprocess') && str_contains($automation, 'ExistingAppointmentConversationService'), 'Na reabertura, a fila reentra na Agenda/compromisso existente antes da IA.');

$check(str_contains($settingsView, 'Horário de atendimento é prioritário'), 'Tela explica que Agenda respeita o horário global.');
$check(!str_contains($settingsView, 'type="checkbox" name="client_lookup_outside_hours"'), 'Não existe mais opção visual para furar o expediente.');
$check(str_contains($settingsView, 'name="client_lookup_outside_hours" value="0"'), 'Ao salvar, configuração legada de bypass é zerada.');
$check(str_contains($version, 'RS Connect 36.41.5') && (($manifest['package_version'] ?? '') === '36.41.5'), 'Pacote identifica a versão 36.41.5.');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - {$passes} verificações: horário global, fila pós-horário e retomada da Agenda validados.\n";
