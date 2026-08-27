<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$webhook = file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php') ?: '';
$automation = file_get_contents($root . '/app/Services/AiAutomationService.php') ?: '';
$recovery = file_get_contents($root . '/app/Services/AiAfterHoursRecoveryService.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$templateRaw = file_get_contents($root . '/docs/n8n_templates/template-fila-rapida-ia.json') ?: '';
$template = json_decode($templateRaw, true);

$httpNode = null;
$scheduleNode = null;
foreach (($template['nodes'] ?? []) as $node) {
    if (($node['type'] ?? '') === 'n8n-nodes-base.httpRequest') {
        $httpNode = $node;
    }
    if (($node['type'] ?? '') === 'n8n-nodes-base.scheduleTrigger') {
        $scheduleNode = $node;
    }
}

$checks = [
    'webhook trata IA e humano antes da IA' => str_contains($webhook, "\$attendanceMode === 'ai' ? 'pending' : 'blocked_human'")
        && str_contains($webhook, 'sendAfterHoursAcknowledgement')
        && str_contains($webhook, "'skip_ai' => true"),
    'aviso operacional possui deduplicação' => str_contains($automation, 'public function sendAfterHoursAcknowledgement')
        && str_contains($automation, 'rs_after_hours_ack_')
        && str_contains($automation, 'already_acknowledged')
        && str_contains($automation, 'tenant_onboarding_settings'),
    'pendência aponta para abertura exata' => str_contains($recovery, 'nextOpeningAt($agentSchedule)')
        && str_contains($recovery, 'private function deferUntil')
        && str_contains($recovery, 'Clock::STORAGE_TIMEZONE'),
    'fila rápida chama endpoint a cada minuto' => is_array($httpNode)
        && (($httpNode['parameters']['url'] ?? '') === 'https://SEU_DOMINIO_RS_CONNECT/webhooks/ai-reprocess/queue')
        && is_array($scheduleNode)
        && ((int) ($scheduleNode['parameters']['rule']['interval'][0]['minutesInterval'] ?? 0) === 1),
    'versão atualizada' => str_contains($version, 'RS Connect 36.20.10'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, 'Falhas: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "OK - aviso fora do horário e retomada na abertura validados.\n";
