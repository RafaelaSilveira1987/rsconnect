<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);
$check = static function (bool $condition, string $label, array &$failed): void {
    echo ($condition ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$condition) {
        $failed[] = $label;
    }
};

require_once $root . '/app/Services/ConversationFlowService.php';

$flow = new \App\Services\ConversationFlowService();
$failed = [];

$patientNormalized = \App\Services\ConversationFlowService::normalizeClassification('lead', 'patient');
$customerNormalized = \App\Services\ConversationFlowService::normalizeClassification('lead', 'customer');
$customerWithoutGroup = \App\Services\ConversationFlowService::normalizeClassification('customer', 'unclassified');
$leadNormalized = \App\Services\ConversationFlowService::normalizeClassification('lead', 'interested');

$patientProfile = $flow->relationshipProfile([
    'status' => $patientNormalized['status'],
    'contact_group' => $patientNormalized['group'],
    'tags_json' => null,
]);
$customerProfile = $flow->relationshipProfile([
    'status' => $customerNormalized['status'],
    'contact_group' => $customerNormalized['group'],
    'tags_json' => null,
]);
$leadProfile = $flow->relationshipProfile([
    'status' => $leadNormalized['status'],
    'contact_group' => $leadNormalized['group'],
    'tags_json' => null,
]);

$contacts = $read('app/Views/contacts/index.php');
$conversations = $read('app/Views/conversations/index.php');
$contactController = $read('app/Controllers/ContactController.php');
$conversationController = $read('app/Controllers/ConversationController.php');
$ai = $read('app/Services/AiModelService.php');
$crm = $read('app/Services/CrmAutoService.php');
$agents = $read('app/Views/agents/index.php');
$css = $read('public/assets/css/app.css');
$js = $read('public/assets/js/app.js');
$appLayout = $read('app/Views/layouts/app.php');
$version = $read('app/Services/AppVersionService.php');

$check($patientNormalized === ['status' => 'customer', 'group' => 'patient'], 'paciente nunca permanece classificado como novo lead', $failed);
$check($customerNormalized === ['status' => 'customer', 'group' => 'customer'], 'cliente atual nunca permanece classificado como novo lead', $failed);
$check($customerWithoutGroup === ['status' => 'customer', 'group' => 'customer'], 'relacionamento atual sem grupo recebe grupo cliente', $failed);
$check(($patientProfile['key'] ?? '') === 'patient' && !empty($patientProfile['is_existing_customer']) && empty($patientProfile['should_create_commercial_lead']), 'perfil de paciente ativa continuidade e evita lead automático', $failed);
$check(($customerProfile['key'] ?? '') === 'customer' && !empty($customerProfile['is_existing_customer']) && empty($customerProfile['should_create_commercial_lead']), 'perfil de cliente ativa continuidade e evita lead automático', $failed);
$check(($leadProfile['key'] ?? '') === 'lead' && !empty($leadProfile['is_new_lead']) && !empty($leadProfile['should_create_commercial_lead']), 'novo interessado mantém fluxo comercial de lead', $failed);

$check(str_contains($contactController, 'ConversationFlowService::normalizeClassification') && str_contains($conversationController, 'ConversationFlowService::normalizeClassification'), 'contatos e conversas usam a mesma normalização de classificação', $failed);
$check(str_contains($ai, 'Perfil operacional:') && str_contains($ai, 'Regra de relacionamento:') && str_contains($ai, 'A organização do contato é uma regra operacional'), 'prompt da IA recebe o perfil operacional da Organização', $failed);
$check(str_contains($crm, "empty(\$relationship['should_create_commercial_lead'])") && str_contains($crm, 'hasExplicitCommercialIntent'), 'CRM evita novo lead para relacionamento atual sem perder nova oportunidade explícita', $failed);
$check(str_contains($contacts, 'data-contact-ai-context') && str_contains($contacts, 'contact-table-row') && str_contains($contacts, 'Ver contato'), 'base de contatos exibe relacionamento e contexto da IA com leitura melhor', $failed);
$check(str_contains($conversations, 'data-contact-classification-form') && str_contains($conversations, 'Como a IA vai conduzir este contato'), 'drawer de Conversas usa a mesma lógica de Organização', $failed);
$check(str_contains($js, "querySelectorAll('[data-contact-classification-form]')") && str_contains($js, "['customer', 'patient'].includes(group.value)"), 'interface mantém classificação e grupo coerentes antes de salvar', $failed);

$check(str_contains($css, 'grid-template-columns: minmax(0, 1fr) 124px') && str_contains($css, '.channel-priority-field input[type="number"]'), 'prioridade do roteamento não estica ao lado das palavras-chave', $failed);
$check(str_contains($css, '.module-setting-actions') && str_contains($css, 'grid-template-columns: repeat(2, minmax(0, 1fr))'), 'Menu e Acesso ficam alinhados em colunas estáveis', $failed);
$check(str_contains($agents, 'ai-local-automation-intro') && str_contains($css, '.ai-local-automation-intro'), 'Conversa natural separa eyebrow, título e descrição', $failed);
$check(str_contains($css, '.agent-workflow-editor') && preg_match('/\.agent-workflow-editor\s*\{[^}]*grid-template-columns:\s*1fr/s', $css) === 1, 'ordem do atendimento é exibida verticalmente', $failed);
$check(str_contains($css, '.contacts-layout .clean-table tr.contact-table-row') && str_contains($css, 'content: attr(data-label)'), 'contatos viram cards legíveis em mobile', $failed);
$check(str_contains($css, 'RS Connect 36.29.8 — terceira rodada operacional'), 'camada visual 36.29.8 presente', $failed);
$check(str_contains($appLayout, 'app.css?v=36.29.8') && str_contains($appLayout, 'app.js?v=36.29.8'), 'cache principal atualizado para 36.29.8', $failed);
$check(str_contains($version, 'RS Connect 36.29.8 — Organização inteligente e telas operacionais'), 'pacote identificado como 36.29.8', $failed);

exit($failed === [] ? 0 : 1);
