<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\PreSchedulingService;

$service = new PreSchedulingService();

$initial = $service->detectIntent('Quero marcar uma reuniao amanha as 10h', false);
if (empty($initial['has_intent']) || ($initial['location_type'] ?? '') !== 'indefinida') {
    throw new RuntimeException('Pedido de agenda sem modalidade deve permanecer indefinido.');
}

$online = $service->detectIntent('online', true);
if (empty($online['has_intent']) || ($online['location_type'] ?? '') !== 'online') {
    throw new RuntimeException('Resposta online deve continuar o fluxo e definir modalidade online.');
}

$presencial = $service->detectIntent('presencial', true);
if (empty($presencial['has_intent']) || ($presencial['location_type'] ?? '') !== 'presencial') {
    throw new RuntimeException('Resposta presencial deve continuar o fluxo e definir modalidade presencial.');
}

$availabilitySource = file_get_contents(dirname(__DIR__, 2) . '/app/Services/CalendarAvailabilityService.php');
if (!is_string($availabilitySource)
    || !str_contains($availabilitySource, "code' => 'modality_required")
    || !str_contains($availabilitySource, 'if ($modality !== $requestedModality)')) {
    throw new RuntimeException('CalendarAvailabilityService precisa bloquear busca sem modalidade e filtrar o callback pela modalidade solicitada.');
}

$template = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/n8n_templates/template-agenda-google-eventos-vago.json'), true);
if (!is_array($template)) {
    throw new RuntimeException('Template Eventos VAGO inválido.');
}
$code = '';
foreach (($template['nodes'] ?? []) as $node) {
    if (($node['name'] ?? '') === 'Normalizar operação') {
        $code = (string) ($node['parameters']['jsCode'] ?? '');
        break;
    }
}
if (!str_contains($code, "!['online', 'presencial'].includes(requestedModality)")) {
    throw new RuntimeException('Template VAGO precisa recusar busca sem Online/Presencial.');
}

fwrite(STDOUT, "OK - modalidade Online/Presencial é obrigatória antes da disponibilidade e filtra o Google Agenda.\n");
