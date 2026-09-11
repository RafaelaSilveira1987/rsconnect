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

$service = new AiLocalReplyService();
$configured = 'Olá! Aqui é a Rafa, assistente do consultório da psicóloga Mariana.';
$agent = [
    'ai_local_replies_enabled' => 1,
    'ai_greeting_reply' => $configured,
    'ai_gratitude_reply' => '',
    'ai_farewell_reply' => '',
    'ai_menu_reply' => '',
];
$leadWithHistory = ['_is_opening_turn' => false, 'contact_status' => 'lead', 'contact_group' => 'interested', 'tags_json' => []];
$patientWithHistory = ['_is_opening_turn' => false, 'contact_status' => 'customer', 'contact_group' => 'patient', 'tags_json' => []];

$result = $service->match($agent + ['ai_greeting_mode' => 'all_contacts'], 'Olá', $leadWithHistory);
$check(!empty($result['matched']) && ($result['reply'] ?? '') === $configured, 'Todos os contatos usa exatamente a saudação configurada mesmo com histórico anterior.');

$result = $service->match($agent + ['ai_greeting_mode' => 'all_contacts'], 'Olá', $patientWithHistory);
$check(!empty($result['matched']) && ($result['reply'] ?? '') === $configured, 'Todos os contatos também aplica a saudação configurada a paciente reconhecido.');

$result = $service->match($agent + ['ai_greeting_mode' => 'new_contacts'], 'Olá', $leadWithHistory);
$check(!empty($result['matched']) && ($result['reply'] ?? '') === $configured, 'Somente novos contatos mantém a saudação configurada para lead, mesmo em retorno na mesma conversa.');

$result = $service->match($agent + ['ai_greeting_mode' => 'new_contacts'], 'Olá', $patientWithHistory);
$check(empty($result['matched']), 'Somente novos contatos deixa paciente reconhecido seguir para resposta natural.');

$result = $service->match($agent + ['ai_greeting_mode' => 'disabled'], 'Olá', $leadWithHistory);
$check(empty($result['matched']), 'Sem saudação automática continua desativando a regra local.');

$result = $service->match($agent + ['ai_greeting_mode' => 'all_contacts'], 'Olá, preciso remarcar minha consulta', $patientWithHistory);
$check(empty($result['matched']), 'Mensagem com pedido além da saudação não é sequestrada pela resposta pronta.');

$view = file_get_contents($root . '/app/Views/agents/index.php');
$manifest = file_get_contents($root . '/manifest.json');
$version = file_get_contents($root . '/app/Services/AppVersionService.php');
$serviceSource = file_get_contents($root . '/app/Services/AiLocalReplyService.php');

$check(str_contains((string) $view, 'Uma saudação enviada pelo contato usa esta política mesmo em uma conversa já existente'), 'Tela explica corretamente o comportamento corrigido.');
$check(str_contains((string) $serviceSource, 'não deve depender de ser a primeira resposta de todo o histórico'), 'Serviço documenta a separação entre saudação explícita e abertura espontânea.');
$check(str_contains((string) $manifest, '"package_version": "36.31.3"') && str_contains((string) $manifest, '111_agent_greeting_policy.sql'), 'Manifesto registra 36.31.3 sem exigir nova migration.');
$check(str_contains((string) $version, "PACKAGE_LABEL = 'RS Connect 36.31.3 — Hotfix da saudação configurada'") && str_contains((string) $version, "REQUIRED_MIGRATION = '111_agent_greeting_policy.sql'"), 'Versão atualizada e migration preservada.');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} falha(s); {$passes} verificação(ões) aprovada(s).\n");
    exit(1);
}

echo "\nResumo hotfix saudação 36.31.3: {$passes} verificações aprovadas.\n";
