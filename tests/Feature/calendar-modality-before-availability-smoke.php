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

use App\Services\AgentConversationBehaviorService;
use App\Services\PreSchedulingService;

$service = new PreSchedulingService();
$behavior = new AgentConversationBehaviorService();

$initial = $service->detectIntent('Quero marcar uma reuniao amanha as 10h', false);
if (empty($initial['has_intent']) || ($initial['location_type'] ?? '') !== 'indefinida') {
    throw new RuntimeException('Pedido de agenda sem modalidade deve permanecer indefinido no parser.');
}

$legacySingle = $behavior->settingsFromProfile([
    'config' => ['conversation_behavior' => [
        'modalities' => [
            'online' => ['enabled' => false],
            'presencial' => ['enabled' => true],
        ],
    ]],
    'triage_fields' => [],
]);
if (($legacySingle['service_mode']['mode'] ?? '') !== 'single' || ($legacySingle['service_mode']['fixed_modality'] ?? '') !== 'presencial') {
    throw new RuntimeException('Configuração legada somente presencial deve migrar em memória para modalidade única presencial.');
}

$choiceProfile = [
    'config' => ['conversation_behavior' => [
        'service_mode' => ['mode' => 'choice', 'fixed_modality' => 'presencial'],
        'modalities' => [
            'online' => ['enabled' => true],
            'presencial' => ['enabled' => true],
        ],
    ]],
    'triage_fields' => [
        ['field_key' => 'modality', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1],
    ],
];
$choiceEffective = $behavior->applyOperationalOverridesToProfile($choiceProfile);
if (empty($choiceEffective['triage_fields'][0]['active'])) {
    throw new RuntimeException('Quando existe escolha real, o campo modalidade deve continuar coletável.');
}

$singleProfile = $choiceProfile;
$singleProfile['config']['conversation_behavior']['service_mode']['mode'] = 'single';
$singleEffective = $behavior->applyOperationalOverridesToProfile($singleProfile);
if (!empty($singleEffective['triage_fields'][0]['active']) || empty($singleEffective['triage_fields'][0]['operationally_resolved'])) {
    throw new RuntimeException('Modalidade única deve resolver o campo sem perguntar ao contato.');
}

$availabilitySource = file_get_contents(dirname(__DIR__, 2) . '/app/Services/CalendarAvailabilityService.php');
if (!is_string($availabilitySource)
    || str_contains($availabilitySource, "'code' => 'modality_required'")
    || !str_contains($availabilitySource, 'in_array($requestedModality, [\'online\', \'presencial\'], true) && $modality !== $requestedModality')) {
    throw new RuntimeException('CalendarAvailabilityService deve aceitar modalidade indefinida e filtrar apenas quando houver modalidade efetiva.');
}

$template = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/n8n_templates/template-agenda-google-eventos-vago.json');
if (str_contains($template, 'A modalidade precisa ser definida como online ou presencial antes de consultar eventos VAGO.')) {
    throw new RuntimeException('Template VAGO não pode mais impor modalidade universalmente.');
}

fwrite(STDOUT, "OK - forma de atendimento pode ser opcional, única ou escolhida pelo contato.\n");
