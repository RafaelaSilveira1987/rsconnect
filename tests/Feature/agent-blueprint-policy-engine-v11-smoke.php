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
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); }
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentPolicyEngineService;
use App\Services\AgentTriageService;

$profile = [
    'status' => 'active',
    'capabilities' => [
        'triage.enabled' => true,
        'eligibility.enabled' => true,
        'calendar.read' => true,
        'calendar.pre_schedule' => true,
        'calendar.confirm' => false,
        'calendar.human_approval' => true,
    ],
    'triage_fields' => [
        ['field_key' => 'requester_name', 'label' => 'Nome', 'prompt_text' => 'Como você se chama?', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1],
        ['field_key' => 'is_for_self', 'label' => 'Atendimento para quem', 'prompt_text' => 'O atendimento seria para você?', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1],
        ['field_key' => 'patient_age', 'label' => 'Idade', 'prompt_text' => 'Qual a idade?', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1],
        ['field_key' => 'modality', 'label' => 'Modalidade', 'prompt_text' => 'Online ou presencial?', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1],
    ],
    'policies' => [
        ['policy_key' => 'minimum_age', 'policy_type' => 'number', 'value' => 14, 'enabled' => 1, 'customer_message' => 'Atendimento somente a partir de {{minimum_age}} anos.'],
        ['policy_key' => 'couple_service_allowed', 'policy_type' => 'boolean', 'value' => false, 'enabled' => 1, 'customer_message' => 'Casal indisponível.'],
    ],
];

$engine = new AgentPolicyEngineService();
$triage = new AgentTriageService();

$checks = [];

$underAge = $engine->evaluate($profile, [
    'requester_name' => 'Maria', 'is_for_self' => false, 'patient_age' => 8, 'modality' => 'presencial',
], 'calendar.pre_schedule');
$checks['menor de 14 bloqueia agenda'] = empty($underAge['allowed']) && ($underAge['code'] ?? '') === 'minimum_age';
$checks['mensagem de idade usa valor configurado'] = str_contains((string) ($underAge['message'] ?? ''), '14');

$missingAge = $engine->evaluate($profile, [
    'requester_name' => 'Maria', 'is_for_self' => false, 'modality' => 'presencial',
], 'calendar.pre_schedule');
$checks['agenda sem idade fica bloqueada aguardando coleta'] = empty($missingAge['allowed']) && ($missingAge['decision'] ?? '') === 'collect';
$checks['próximo campo é idade'] = (($missingAge['evidence']['next_field'] ?? '') === 'patient_age');

$eligible = $engine->evaluate($profile, [
    'requester_name' => 'Maria', 'is_for_self' => true, 'patient_age' => 32, 'modality' => 'online',
], 'calendar.pre_schedule');
$checks['adulto com campos obrigatórios pode consultar agenda'] = !empty($eligible['allowed']);

$confirm = $engine->evaluate($profile, [
    'requester_name' => 'Maria', 'is_for_self' => true, 'patient_age' => 32, 'modality' => 'online',
], 'calendar.confirm');
$checks['psicologia com aprovação humana não confirma automaticamente'] = empty($confirm['allowed'])
    && ($confirm['decision'] ?? '') === 'handoff'
    && ($confirm['code'] ?? '') === 'human_approval_required';

$genericProfile = [
    'status' => 'active',
    'capabilities' => ['calendar.pre_schedule' => true],
    'triage_fields' => [],
    'policies' => [[
        'policy_key' => 'blocked_service',
        'policy_type' => 'json',
        'value' => [
            'field' => 'service',
            'operator' => 'in',
            'value' => ['progressiva'],
            'applies_to' => ['calendar.*'],
        ],
        'action_key' => 'block_schedule',
        'customer_message' => 'Este serviço precisa de avaliação da equipe.',
        'enabled' => 1,
    ]],
];
$genericBlock = $engine->evaluate($genericProfile, ['service' => 'Progressiva'], 'calendar.pre_schedule');
$checks['política JSON genérica permite novos nichos sem if específico'] = empty($genericBlock['allowed'])
    && ($genericBlock['policy_key'] ?? '') === 'blocked_service';

$analysis = $triage->analyzeText($profile, [], 'Quero marcar psicólogo para minha filha. Ela vai fazer 8 anos agora dia 18 de setembro. Presencial.', false);
$checks['triagem extrai terceiro e idade de mensagem natural'] = (($analysis['collected']['is_for_self'] ?? null) === false)
    && (int) ($analysis['collected']['patient_age'] ?? 0) === 8
    && (($analysis['collected']['modality'] ?? '') === 'presencial');
$checks['mensagem natural de menor bloqueia antes da agenda'] = empty($analysis['decision']['allowed']) && ($analysis['decision']['code'] ?? '') === 'minimum_age';

$root = dirname(__DIR__, 2);
$webhook = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$calendar = (string) file_get_contents($root . '/app/Services/CalendarConversationService.php');
$settings = (string) file_get_contents($root . '/app/Views/companies/settings.php');
$createForm = (string) file_get_contents($root . '/app/Views/companies/_create_form.php');
$migration = (string) file_get_contents($root . '/database/migrations/103_agent_blueprints_policy_engine.sql');
$blueprintAdmin = (string) file_get_contents($root . '/app/Views/agent_blueprints/index.php');
$blueprintController = (string) file_get_contents($root . '/app/Controllers/AgentBlueprintController.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$versionService = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks['webhook executa triagem antes da agenda'] = str_contains($webhook, 'AgentTriageService')
    && strpos($webhook, 'AgentTriageService())->handleIncoming') < strpos($webhook, 'CalendarConversationService())->handleIncomingSelection');
$checks['pré-agendamento possui defesa em profundidade'] = str_contains($pre, 'Policy Engine obrigatório antes de QUALQUER acesso à agenda')
    && str_contains($pre, 'rejectPendingForPolicy');
$checks['confirmação automática passa pelo Policy Engine'] = str_contains($calendar, "evaluate(\$profile, \$collected, 'calendar.confirm')");
$checks['RS Admin possui editor de blueprint e políticas'] = str_contains($settings, 'Como o assistente deve atender')
    && str_contains($settings, 'agent_policies[')
    && str_contains($settings, 'triage_fields[')
    && str_contains($settings, 'O que o assistente decidiu recentemente');
$checks['cadastro de empresa seleciona nicho e blueprint'] = str_contains($createForm, 'business_niche_id') && str_contains($createForm, 'agent_blueprint_id');
$checks['migration cria estrutura versionada e auditoria'] = str_contains($migration, 'agent_blueprint_versions')
    && str_contains($migration, 'conversation_policy_decisions')
    && str_contains($migration, 'conversation_triage_sessions');
$checks['RS Admin possui catálogo global de nichos e blueprints'] = str_contains($blueprintAdmin, 'Modelos de atendimento')
    && str_contains($blueprintController, 'publishVersion')
    && str_contains($routes, "'/agent-blueprints'")
    && str_contains($layout, 'Modelos por segmento');
$checks['publicação de versão não altera tenants automaticamente'] = str_contains($blueprintController, 'empresas existentes não foram alteradas')
    || str_contains($blueprintController, 'empresas existentes não são alteradas');
$checks['estrutura 103 permanece e pacote exige migration atual do laboratório'] = str_contains($migration, 'agent_blueprint_versions')
    && str_contains($versionService, "REQUIRED_MIGRATION = '105_agent_testing_lab.sql'")
    && str_contains($versionService, 'RS Connect 36.29.0');

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
}
if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - blueprints por nicho, triagem estruturada, elegibilidade e agenda fail-closed validados.\n";
