<?php

declare(strict_types=1);

if (!function_exists('mb_strlen')) { function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); } }
if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $start) : substr($value, $start, $length); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); } }

require_once __DIR__ . '/../../app/Services/ConversationFlowService.php';
require_once __DIR__ . '/../../app/Services/AiLocalReplyService.php';

use App\Services\AiLocalReplyService;

$root = dirname(__DIR__, 2);
$passes = 0;
$failures = 0;
$check = static function (bool $ok, string $message) use (&$passes, &$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $message . PHP_EOL;
    $ok ? $passes++ : $failures++;
};
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$service = new AiLocalReplyService();
$baseAgent = [
    'ai_local_replies_enabled' => 1,
    'ai_greeting_reply' => 'Olá! Como posso ajudar você hoje?',
    'ai_gratitude_reply' => 'Por nada!',
    'ai_farewell_reply' => 'Até mais!',
    'ai_menu_reply' => '',
];

$lead = ['_is_opening_turn' => true, 'contact_status' => 'lead', 'contact_group' => 'interested', 'tags_json' => []];
$patient = ['_is_opening_turn' => true, 'contact_status' => 'customer', 'contact_group' => 'patient', 'tags_json' => []];
$continuedLead = ['_is_opening_turn' => false, 'contact_status' => 'lead', 'contact_group' => 'interested', 'tags_json' => []];

$result = $service->match($baseAgent + ['ai_greeting_mode' => 'all_contacts'], 'Oi', $patient);
$check(!empty($result['matched']) && ($result['type'] ?? '') === 'greeting', 'Modo todos os contatos mantém saudação para paciente reconhecido na abertura.');

$result = $service->match($baseAgent + ['ai_greeting_mode' => 'new_contacts'], 'Oi', $lead);
$check(!empty($result['matched']), 'Modo novos contatos mantém saudação pronta para lead na abertura.');

$result = $service->match($baseAgent + ['ai_greeting_mode' => 'new_contacts'], 'Oi', $patient);
$check(empty($result['matched']), 'Modo novos contatos não usa saudação pronta para cliente/paciente reconhecido.');

$result = $service->match($baseAgent + ['ai_greeting_mode' => 'disabled'], 'Oi', $lead);
$check(empty($result['matched']), 'Modo desativado não força saudação pronta.');

$result = $service->match($baseAgent + ['ai_greeting_mode' => 'all_contacts'], 'Oi', $continuedLead);
$check(empty($result['matched']), 'Saudação pronta não se repete quando a conversa já teve resposta.');

$result = $service->match($baseAgent + ['ai_greeting_mode' => 'disabled'], 'Obrigado', $continuedLead);
$check(!empty($result['matched']) && ($result['type'] ?? '') === 'gratitude', 'Desativar saudação não desativa outras respostas locais configuradas.');

$view = $read('app/Views/agents/index.php');
$controller = $read('app/Controllers/AgentController.php');
$model = $read('app/Services/AiModelService.php');
$automation = $read('app/Services/AiAutomationService.php');
$context = $read('app/Services/AiContextBuilder.php');
$migration = $read('database/migrations/111_agent_greeting_policy.sql');
$manifest = $read('manifest.json');
$version = $read('app/Services/AppVersionService.php');

$check(str_contains($view, 'Quem recebe a saudação') && str_contains($view, 'Somente novos contatos; cliente/paciente reconhecido continua naturalmente'), 'Tela do Agente expõe os três modos de saudação.');
$check(str_contains($view, 'ai_greeting_use_contact_name') && str_contains($view, 'não se repete nos próximos turnos'), 'Tela permite uso natural do nome e explica a regra de não repetição.');
$check(str_contains($controller, 'ai_greeting_mode') && str_contains($controller, 'ai_greeting_use_contact_name'), 'Criação e atualização do assistente persistem a política de saudação.');
$check(str_contains($model, 'Não use mensagem de boas-vindas de novo contato') && str_contains($model, 'Não repita saudação de abertura'), 'Prompt diferencia cliente/paciente reconhecido e continuidade da conversa.');
$check(str_contains($automation, 'hasPriorOutgoingMessage') && str_contains($automation, "empty(\$generationAgent['_is_opening_turn'])"), 'Automação bloqueia repetição e evita cache de respostas de abertura.');
$check(str_contains($context, "['_is_opening_turn']") && str_contains($context, 'outgoing_messages'), 'Contexto da IA sabe quando é a primeira resposta da conversa.');
$check(str_contains($migration, 'ai_greeting_mode') && str_contains($migration, "DEFAULT ''all_contacts''") && str_contains($migration, 'ai_greeting_use_contact_name'), 'Migration preserva instalações existentes e adiciona configuração por agente.');
$check(str_contains($manifest, '"package_version": "36.31.2"') && str_contains($manifest, '111_agent_greeting_policy.sql'), 'Manifesto aponta para a release e migration 36.31.2.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.31.2 — Saudação inteligente por contato'") && str_contains($version, "REQUIRED_MIGRATION = '111_agent_greeting_policy.sql'"), 'Versão da aplicação registra a nova release.');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} falha(s); {$passes} verificação(ões) aprovada(s).\n");
    exit(1);
}

echo "\nResumo saudação inteligente 36.31.2: {$passes} verificações aprovadas.\n";
