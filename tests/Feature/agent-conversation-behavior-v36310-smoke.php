<?php

declare(strict_types=1);

if (!function_exists('mb_strlen')) { function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); } }
if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $start) : substr($value, $start, $length); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); } }

require_once __DIR__ . '/../../app/Services/AgentConversationBehaviorService.php';

use App\Services\AgentConversationBehaviorService;

$root = dirname(__DIR__, 2);
$passes = 0;
$failures = 0;
$check = static function (bool $ok, string $message) use (&$passes, &$failures): void {
    if ($ok) {
        $passes++;
        echo "[OK] {$message}\n";
        return;
    }
    $failures++;
    echo "[FAIL] {$message}\n";
};
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$service = new AgentConversationBehaviorService();
$settings = AgentConversationBehaviorService::normalizeConfiguration([
    'demand' => [
        'enabled' => '1',
        'required_before_schedule' => '1',
        'prompt' => 'Pode me contar brevemente qual é a sua demanda?',
    ],
    'response_delivery' => ['mode' => 'blocks', 'max_blocks' => 3],
    'modalities' => [
        'online' => ['enabled' => '1', 'channel' => 'Google Meet', 'message' => 'O atendimento online acontece pelo Meet.'],
        'presencial' => ['enabled' => '1', 'location' => 'Bronze', 'allowed_days' => ['mon'], 'message' => 'O presencial acontece no Bronze, somente às segundas-feiras.'],
    ],
    'payment' => ['enabled' => '1', 'value_text' => 'R$ 200 por sessão', 'methods' => ['Pix', 'Cartão', 'Transferência']],
    'no_availability' => ['message' => 'No momento estou sem vagas.', 'action' => 'notify', 'target_user_id' => 7],
    'special_routes' => [[
        'enabled' => '1',
        'label' => 'Convite para palestra',
        'keywords' => 'palestra, convite para palestra, evento',
        'customer_message' => 'Vou encaminhar o convite para a responsável.',
        'target_user_id' => 7,
    ]],
]);

$check(($settings['demand']['enabled'] ?? false) === true && ($settings['demand']['required_before_schedule'] ?? false) === true, 'Demanda pode ser obrigatória antes da agenda.');
$legacySettings = $service->settingsFromProfile(['config' => [], 'triage_fields' => [['field_key' => 'brief_demand', 'active' => 1, 'required_before_schedule' => 1, 'prompt_text' => 'Qual é a demanda?']]]);
$check(!empty($legacySettings['demand']['enabled']) && ($legacySettings['demand']['prompt'] ?? '') === 'Qual é a demanda?', 'Empresas antigas herdam a configuração já existente de demanda.');
$check(($settings['response_delivery']['mode'] ?? '') === 'blocks' && (int) ($settings['response_delivery']['max_blocks'] ?? 0) === 3, 'Entrega em até três mensagens é normalizada.');
$check(($settings['modalities']['online']['channel'] ?? '') === 'Google Meet', 'Modalidade online mantém o meio configurado.');
$check(($settings['modalities']['presencial']['location'] ?? '') === 'Bronze' && ($settings['modalities']['presencial']['allowed_days'] ?? []) === ['mon'], 'Presencial preserva local e somente segunda-feira.');
$check(($settings['payment']['methods'] ?? []) === ['Pix', 'Cartão', 'Transferência'], 'Formas de pagamento são preservadas.');
$check(($settings['no_availability']['action'] ?? '') === 'notify' && (int) ($settings['no_availability']['target_user_id'] ?? 0) === 7, 'Política sem vaga aceita aviso interno e responsável.');

$blocks = $service->splitReplyWithSettings($settings, 'O atendimento online acontece pelo Google Meet. O presencial acontece no Bronze somente às segundas-feiras. O valor é R$ 200 por sessão. Você pode pagar por Pix, cartão ou transferência.');
$check(count($blocks) >= 2 && count($blocks) <= 3, 'Resposta longa pode ser entregue em mensagens curtas separadas.');
$check(str_contains(implode(' ', $blocks), 'Google Meet') && str_contains(implode(' ', $blocks), 'Pix'), 'Separação não perde conteúdo da resposta.');

$route = $service->matchSpecialRouteWithSettings($settings, 'Oi, sou de uma faculdade e queria fazer um convite para palestra no nosso evento.');
$check(is_array($route) && ($route['label'] ?? '') === 'Convite para palestra', 'Convite para palestra é identificado como encaminhamento especial.');
$check(($route['customer_message'] ?? '') === 'Vou encaminhar o convite para a responsável.', 'Encaminhamento especial preserva mensagem ao cliente.');

$slots = [
    ['starts_at' => '2026-09-14T13:00:00Z', 'id' => 1], // segunda-feira
    ['starts_at' => '2026-09-15T13:00:00Z', 'id' => 2], // terça-feira
];
$filtered = $service->filterSlotsWithSettings($settings, ['appointment_modality' => 'presencial', 'timezone' => 'America/Sao_Paulo'], $slots);
$check(count($filtered) === 1 && (int) ($filtered[0]['id'] ?? 0) === 1, 'Agenda presencial elimina horários fora da segunda-feira configurada.');

$profile = ['config' => ['conversation_behavior' => $settings]];
$prompt = $service->promptBlock($profile);
$check(str_contains($prompt, 'entenda a necessidade antes de avançar') && str_contains($prompt, 'Google Meet'), 'Prompt recebe demanda e modalidade online estruturadas.');
$check(str_contains($prompt, 'Bronze') && str_contains($prompt, 'segunda-feira'), 'Prompt recebe local e dia permitido do presencial.');
$check(str_contains($prompt, 'R$ 200 por sessão') && str_contains($prompt, 'Pix'), 'Prompt recebe valor e formas de pagamento.');
$check(str_contains($prompt, 'Convite para palestra') && str_contains($prompt, 'backend faz o encaminhamento'), 'Prompt sabe que encaminhamento especial é controlado pelo backend.');

$view = $read('app/Views/agents/index.php');
$controller = $read('app/Controllers/AgentController.php');
$blueprint = $read('app/Services/AgentBlueprintService.php');
$triage = $read('app/Services/AgentTriageService.php');
$model = $read('app/Services/AiModelService.php');
$automation = $read('app/Services/AiAutomationService.php');
$calendar = $read('app/Services/CalendarConversationService.php');
$css = $read('public/assets/css/app.css');
$layout = $read('app/Views/layouts/app.php');
$guest = $read('app/Views/layouts/guest.php');
$restricted = $read('app/Views/layouts/restricted.php');
$version = $read('app/Services/AppVersionService.php');
$manifest = $read('manifest.json');

$check(str_contains($view, 'Conversa, modalidades e encaminhamentos') && str_contains($view, 'Entender a demanda'), 'Tela do Agente expõe comportamento conversacional estruturado.');
$check(str_contains($view, 'Respostas em mensagens curtas') && str_contains($view, 'Preferir mensagens separadas'), 'Tela permite configurar espaçamento em balões.');
$check(str_contains($view, 'Atendimento online') && str_contains($view, 'Atendimento presencial') && str_contains($view, 'Dias permitidos para presencial'), 'Tela estrutura modalidades e dias presenciais.');
$check(str_contains($view, 'Valor e formas de pagamento') && str_contains($view, 'Quando não houver vaga'), 'Tela estrutura pagamento e indisponibilidade.');
$check(str_contains($view, '$hasExplicitConversationBehavior') && str_contains($view, "preScheduleSettings['no_availability_message']"), 'Primeira abertura preserva mensagem sem vaga já configurada no pré-agendamento.');
$check(str_contains($view, 'Encaminhamentos especiais') && str_contains($view, 'Palavras / frases') && str_contains($view, 'Encaminhar para'), 'Tela permite regras de palestra/aula/supervisão com responsável.');
$check(str_contains($controller, "'conversation_behavior' => is_array(\$_POST['conversation_behavior'] ?? null) ? \$_POST['conversation_behavior'] : []"), 'Controller entrega a nova configuração ao Blueprint.');
$check(str_contains($blueprint, "currentConfig['conversation_behavior']") && str_contains($blueprint, 'syncConversationBehavior'), 'Blueprint persiste comportamento no config_json e sincroniza triagem/agenda.');
$check(str_contains($triage, '[continuidade: demanda anterior não precisa ser repetida]'), 'Cliente/paciente atual não é forçado a repetir demanda.');
$check(str_contains($model, 'AgentConversationBehaviorService') && str_contains($model, '$conversationBehaviorBlock'), 'Modelo recebe regras operacionais fora do prompt livre.');
$check(str_contains($automation, 'matchSpecialRoute') && str_contains($automation, 'handoffSpecialRoute'), 'Automação intercepta encaminhamentos especiais antes do fluxo comum.');
$check(str_contains($automation, 'sendAutomatedReplySequence') && str_contains($automation, 'splitReply'), 'Automação entrega respostas da IA em sequência configurável.');
$check(str_contains($calendar, 'filterSlotsForAppointment') && str_contains($calendar, 'handleNoAvailability'), 'Agenda aplica dias permitidos e política sem disponibilidade.');
$check(str_contains($css, 'agent-conversation-behavior-section') && str_contains($css, 'agent-special-route-card'), 'Frontend possui layout responsivo dedicado às novas regras.');
$check(str_contains($layout, 'app.css?v=36.31.0') && str_contains($layout, 'app.js?v=36.31.0'), 'Layout autenticado invalida cache para 36.31.0.');
$check(str_contains($guest, 'app.css?v=36.31.0') && str_contains($restricted, 'app.css?v=36.31.0'), 'Layouts auxiliares invalidam cache CSS para 36.31.0.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.31.0 — Atendimento conversacional estruturado'"), 'Versão 36.31.0 registrada.');
$check(str_contains($manifest, '"package_version": "36.31.0"') || str_contains($manifest, '"package_version": "36.31.1"') || str_contains($manifest, '"package_version": "36.31.2"') || str_contains($manifest, '"package_version": "36.31.3"') || str_contains($manifest, '"package_version": "36.32.0"'), 'A linha 36.31 permanece registrada no manifesto atual.');
$check(str_contains($manifest, '110_conversation_lifecycle_e2e_consistency.sql') || str_contains($read('database/migrations/manifest.php'), '110_conversation_lifecycle_e2e_consistency.sql'), 'Migration-base 110 permanece registrada no histórico de migrations.');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} falha(s); {$passes} verificação(ões) aprovada(s).\n");
    exit(1);
}

echo "\nResumo atendimento conversacional 36.31.0: {$passes} verificações aprovadas.\n";
