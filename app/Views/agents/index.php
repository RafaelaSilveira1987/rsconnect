<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$dayLabels = ['mon' => 'Seg', 'tue' => 'Ter', 'wed' => 'Qua', 'thu' => 'Qui', 'fri' => 'Sex', 'sat' => 'Sáb', 'sun' => 'Dom'];
$businessHoursByDay = static function (?string $json): array {
    $decoded = json_decode((string) $json, true);
    $result = [];
    foreach (['mon','tue','wed','thu','fri','sat','sun'] as $day) {
        $range = is_array($decoded) && isset($decoded[$day][0]) && is_array($decoded[$day][0]) ? $decoded[$day][0] : null;
        $result[$day] = [
            'enabled' => $range !== null && isset($range[0], $range[1]),
            'start' => $range !== null && isset($range[0]) ? (string) $range[0] : '08:00',
            'end' => $range !== null && isset($range[1]) ? (string) $range[1] : ($day === 'sat' ? '12:00' : '18:00'),
        ];
    }
    if (!is_array($decoded)) {
        foreach (['mon','tue','wed','thu','fri'] as $day) $result[$day]['enabled'] = true;
    }
    return $result;
};
$canManage = Auth::can('agents.manage');
$isClientExperience = !Auth::isSuperAdmin();
$selectedTenantId = (int) ($selectedTenantId ?? 0);
$tenants = is_array($tenants ?? null) ? $tenants : [];
$groupRules = is_array($groupRules ?? null) ? $groupRules : [];
$contactGroups = is_array($contactGroups ?? null) ? $contactGroups : [];
$promptVersions = is_array($promptVersions ?? null) ? $promptVersions : [];
$profile = is_array($companyProfile ?? null) ? $companyProfile : [];
$companyKnowledge = [];
foreach ([
    'Sobre a empresa' => $profile['company_about'] ?? '',
    'Principais serviços' => $profile['company_services'] ?? '',
    'Diferenciais' => $profile['company_differentials'] ?? '',
    'Horário de atendimento' => $profile['company_business_hours'] ?? '',
    'Site' => $profile['website'] ?? '',
    'Instagram' => $profile['instagram'] ?? '',
    'Observações importantes' => $profile['company_notes'] ?? '',
] as $label => $value) {
    $value = trim((string) $value);
    if ($value !== '') {
        $companyKnowledge[] = $label . ":\n" . $value;
    }
}
$defaultCompanyKnowledge = implode("\n\n", $companyKnowledge);
$aiModeLabels = ['economy' => 'Econômico', 'balanced' => 'Equilibrado', 'quality' => 'Qualidade máxima'];
$aiModeHints = [
    'economy' => 'Até 6 mensagens recentes, base seletiva e respostas mais curtas.',
    'balanced' => 'Lembra até 10 mensagens recentes, com equilíbrio entre qualidade e economia.',
    'quality' => 'Lembra até 20 mensagens recentes para respostas mais completas.',
];
$routingModeForBinding = static function (array $binding): string {
    if ((int) ($binding['is_primary'] ?? 0) === 1) {
        return 'primary';
    }
    return trim((string) ($binding['routing_keywords'] ?? '')) !== '' ? 'specialist' : 'round_robin';
};
$routingModeLabels = [
    'primary' => 'Principal / recepção',
    'specialist' => 'Especialista',
    'round_robin' => 'Distribuição automática',
];
$routingModeShortLabels = [
    'primary' => 'Principal',
    'specialist' => 'Especialista',
    'round_robin' => 'Distribuição automática',
];
?>
<div class="agent-management-page <?= $isClientExperience ? 'agent-client-experience' : 'agent-admin-experience' ?>">
    <section class="card agent-list-card">
        <div class="section-heading agent-page-heading">
            <div><span class="eyebrow">Assistentes virtuais</span><h2>Assistentes cadastrados</h2></div>
            <div class="agent-page-actions">
                <span class="badge"><?= count($agents) ?> assistente(s)</span>
                <?php if ($canManage): ?>
                    <button class="btn btn-primary" type="button" data-toggle-panel="agent-create-drawer" <?= !$instances ? 'disabled' : '' ?>>
                        Novo assistente
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (Auth::isSuperAdmin()): ?>
            <form class="agent-admin-tenant-selector" method="get" action="<?= View::e(Router::url('/agents')) ?>">
                <div>
                    <span class="eyebrow">Empresa em edição</span>
                    <strong><?= View::e($profile['name'] ?? 'Selecione uma empresa') ?></strong>
                    <small>Escolha a empresa para visualizar e editar as conexões, assistentes e instruções corretas.</small>
                </div>
                <label class="field compact-field">
                    <span>Empresa</span>
                    <select name="tenant_id" data-auto-submit required>
                        <option value="">Selecione</option>
                        <?php foreach ($tenants as $tenant): ?>
                            <option value="<?= (int) $tenant['id'] ?>" <?= $selectedTenantId === (int) $tenant['id'] ? 'selected' : '' ?>>
                                <?= View::e($tenant['name']) ?> · <?= (int) ($tenant['agents_count'] ?? 0) ?> assistente(s) · <?= (int) ($tenant['instances_count'] ?? 0) ?> WhatsApp(s)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        <?php endif; ?>

        <?php if ($canManage && !$instances): ?>
            <div class="message-warning agent-connection-warning">
                A equipe RS Connect precisa preparar uma conexão WhatsApp antes da criação do primeiro assistente.
            </div>
        <?php endif; ?>

        <?php if ($canManage && $instances): ?>
            <div class="agent-routing-guide">
                <div><span class="eyebrow">Multiagente</span><strong>Defina o papel de cada assistente no próprio canal</strong></div>
                <div class="agent-routing-guide-items">
                    <span><b>Principal</b> recebe o atendimento geral.</span>
                    <span><b>Especialista</b> assume quando identifica as intenções configuradas.</span>
                    <span><b>Distribuição automática</b> divide novas conversas gerais entre os assistentes participantes.</span>
                </div>
            </div>
        <?php endif; ?>

        <div class="agent-grid">
            <?php foreach ($agents as $agent): ?>
                <?php
                $dayHours = $businessHoursByDay($agent['business_hours_json'] ?? null);
                $agentChannelBindings = [];
                foreach (($agent['channels'] ?? []) as $channelBinding) {
                    $bindingInstanceId = (int) ($channelBinding['instance_id'] ?? 0);
                    if ($bindingInstanceId > 0) {
                        $agentChannelBindings[$bindingInstanceId] = $channelBinding;
                    }
                }
                $legacyInstanceId = (int) ($agent['instance_id'] ?? 0);
                if ($legacyInstanceId > 0 && !isset($agentChannelBindings[$legacyInstanceId])) {
                    $agentChannelBindings[$legacyInstanceId] = [
                        'instance_id' => $legacyInstanceId,
                        'is_primary' => 1,
                        'priority' => 100,
                        'routing_keywords' => null,
                        'status' => 'active',
                    ];
                }
                $routingModesInUse = [];
                $routingKeywordsSummary = [];
                foreach ($agentChannelBindings as $binding) {
                    $mode = $routingModeForBinding($binding);
                    $routingModesInUse[$mode] = true;
                    if ($mode === 'specialist') {
                        foreach (preg_split('/[,;\n]+/u', (string) ($binding['routing_keywords'] ?? '')) ?: [] as $keyword) {
                            $keyword = trim((string) $keyword);
                            if ($keyword !== '' && !in_array($keyword, $routingKeywordsSummary, true)) {
                                $routingKeywordsSummary[] = $keyword;
                            }
                        }
                    }
                }
                $routingSummaryLabels = array_map(
                    static fn (string $mode): string => $routingModeShortLabels[$mode] ?? $mode,
                    array_keys($routingModesInUse)
                );
                $routingSummary = $routingSummaryLabels !== [] ? implode(' · ', $routingSummaryLabels) : 'Não configurado';
                ?>
                <article class="agent-card">
                    <div class="agent-card-head">
                        <span class="agent-icon agent-icon-bot" aria-hidden="true"></span>
                        <div><h3><?= View::e($agent['name']) ?></h3><p><?= View::e($agent['segment']) ?></p></div>
                    </div>
                    <div class="agent-data">
                        <div><span>Canais WhatsApp</span><strong><?= View::e(($agent['channel_names'] ?? '') !== '' ? $agent['channel_names'] : ($agent['instance_name'] ?? 'Não vinculado')) ?></strong><small><?= (int) ($agent['channel_count'] ?? 0) ?> canal(is) vinculado(s)</small></div>
                        <div><span>Roteamento multiagente</span><strong><?= View::e($routingSummary) ?></strong><?php if ($routingKeywordsSummary !== []): ?><small>Direcionamento: <?= View::e(implode(', ', array_slice($routingKeywordsSummary, 0, 5))) ?><?= count($routingKeywordsSummary) > 5 ? ' +' . (count($routingKeywordsSummary) - 5) : '' ?></small><?php else: ?><small>Configure o papel deste assistente em cada canal.</small><?php endif; ?></div>
                        <div><span>Modelo usado para responder</span><strong><?= View::e($agent['credential_model'] ?: $agent['model_name']) ?></strong></div>
                        <div><span>Acesso à IA</span><strong><?= View::e($agent['credential_label'] ?: 'Configuração da RS Connect') ?></strong></div>
                        <div><span>Memória configurada</span><strong><?= (int) ($agent['max_context_messages'] ?? 12) ?> mensagens</strong></div>
                        <div><span>Nível de economia</span><strong><?= View::e($aiModeLabels[$agent['ai_efficiency_mode'] ?? 'balanced'] ?? 'Equilibrado') ?></strong><small><?= View::e($aiModeHints[$agent['ai_efficiency_mode'] ?? 'balanced'] ?? $aiModeHints['balanced']) ?></small></div>
                    </div>
                    <div class="badge-row">
                        <span class="badge badge-<?= View::e($agent['status']) ?>"><?= $agent['status'] === 'active' ? 'Ativo' : 'Inativo' ?></span>
                        <span class="badge <?= (int) ($agent['auto_reply_enabled'] ?? 0) === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) ($agent['auto_reply_enabled'] ?? 0) === 1 ? 'Respostas automáticas' : 'Resposta manual' ?></span>
                        <?php foreach (array_keys($routingModesInUse) as $routingMode): ?>
                            <span class="badge agent-routing-badge agent-routing-badge-<?= View::e($routingMode) ?>"><?= View::e($routingModeShortLabels[$routingMode] ?? $routingMode) ?></span>
                        <?php endforeach; ?>
                        <?php if ((int) $agent['is_default'] === 1 && !isset($routingModesInUse['primary'])): ?><span class="badge">Apoio geral</span><?php endif; ?>
                        <?php if ((int) ($agent['business_hours_enabled'] ?? 0) === 1): ?><span class="badge">Segue horário</span><?php endif; ?>
                    </div>
                    <?php if ($canManage && $instances): ?>
                        <a class="agent-routing-jump" href="#agent-routing-<?= (int) $agent['id'] ?>">Configurar multiagente</a>
                    <?php endif; ?>

                    <?php if ($canManage): ?>
                        <details class="agent-prompt agent-prompt-editor">
                            <summary>Editar instruções e informações</summary>
                            <form method="post" action="<?= View::e(Router::url('/agents/prompt')) ?>">
                                <?= Csrf::input() ?>
                                <?php if (Auth::isSuperAdmin()): ?><input type="hidden" name="tenant_id" value="<?= $selectedTenantId ?>"><?php endif; ?>
                                <input type="hidden" name="agent_id" value="<?= (int) $agent['id'] ?>">
                                <label class="field">
                                    <span>Como o assistente deve atender</span>
                                    <textarea class="agent-prompt-textarea" name="system_prompt" rows="16" maxlength="60000" required><?= View::e($agent['system_prompt']) ?></textarea>
                                    <small class="field-hint">Descreva o tom de voz, o que ele pode fazer, o que deve evitar e como conduzir o atendimento.</small>
                                </label>
                                <label class="field">
                                    <span>Informações da empresa</span>
                                    <textarea class="agent-knowledge-textarea" name="knowledge_base" rows="10" maxlength="500000" placeholder="Serviços, horários, links, políticas, respostas frequentes e informações importantes."><?= View::e($agent['knowledge_base'] ?? '') ?></textarea>
                                </label>
                                <div class="agent-prompt-actions">
                                    <span class="muted-text">As mudanças passam a valer nas próximas respostas.</span>
                                    <button class="btn btn-primary" type="submit">Salvar instruções</button>
                                </div>
                            </form>
                        </details>
                        <?php $agentPromptVersions = $promptVersions[(int) $agent['id']] ?? []; ?>
                        <details class="agent-prompt prompt-version-history">
                            <summary>Histórico das instruções <span class="badge"><?= count($agentPromptVersions) ?> versão(ões)</span></summary>
                            <div class="prompt-version-list">
                                <?php foreach ($agentPromptVersions as $version): ?>
                                    <article class="prompt-version-item">
                                        <div>
                                            <strong>Versão <?= (int) ($version['version_number'] ?? 0) ?></strong>
                                            <span><?= View::e($version['title'] ?? 'Instruções salvas') ?></span>
                                            <small><?= View::e($version['created_by_name'] ?? 'Sistema') ?> · <?= View::e($version['created_at'] ?? '') ?> · <?= View::e($version['source'] ?? 'manual') ?></small>
                                        </div>
                                        <form method="post" action="<?= View::e(Router::url('/prompt-studio/restore')) ?>" data-confirm="Restaurar esta versão das instruções?">
                                            <?= Csrf::input() ?>
                                            <?php if (Auth::isSuperAdmin()): ?><input type="hidden" name="tenant_id" value="<?= $selectedTenantId ?>"><?php endif; ?>
                                            <input type="hidden" name="agent_id" value="<?= (int) $agent['id'] ?>">
                                            <input type="hidden" name="version_id" value="<?= (int) ($version['id'] ?? 0) ?>">
                                            <button class="btn btn-quiet btn-sm" type="submit">Restaurar</button>
                                        </form>
                                    </article>
                                <?php endforeach; ?>
                                <?php if (!$agentPromptVersions): ?><div class="empty-state compact-empty">O histórico aparecerá depois que a atualização do banco for concluída ou uma nova versão for salva.</div><?php endif; ?>
                            </div>
                        </details>
                    <?php else: ?>
                        <details class="agent-prompt"><summary>Ver instruções e informações</summary><pre><?= View::e($agent['system_prompt']) ?></pre><?php if (!empty($agent['knowledge_base'])): ?><pre><?= View::e($agent['knowledge_base']) ?></pre><?php endif; ?></details>
                    <?php endif; ?>

                    <?php if ($canManage): ?>
                        <form class="agent-actions agent-settings-form" method="post" action="<?= View::e(Router::url('/agents/status')) ?>">
                            <?= Csrf::input() ?>
                            <?php if (Auth::isSuperAdmin()): ?><input type="hidden" name="tenant_id" value="<?= $selectedTenantId ?>"><?php endif; ?>
                            <input type="hidden" name="agent_id" value="<?= (int) $agent['id'] ?>">
                            <input type="hidden" name="channels_present" value="1">

                            <section class="agent-channel-editor" id="agent-routing-<?= (int) $agent['id'] ?>">
                                <div class="agent-channel-editor-head">
                                    <div>
                                        <span class="eyebrow">Canais WhatsApp</span>
                                        <strong>Onde este assistente deve atuar?</strong>
                                        <small>Marque uma ou mais conexões e escolha como este assistente participa do atendimento em cada uma delas.</small>
                                    </div>
                                    <span class="badge"><?= count($agentChannelBindings) ?> vinculado(s)</span>
                                </div>
                                <?php if ($instances): ?>
                                    <div class="agent-channel-selection-grid">
                                        <?php foreach ($instances as $instance): ?>
                                            <?php
                                            $channelId = (int) $instance['id'];
                                            $channelBinding = $agentChannelBindings[$channelId] ?? null;
                                            $isLinked = is_array($channelBinding);
                                            $routingMode = $isLinked ? $routingModeForBinding($channelBinding) : 'round_robin';
                                            $routingKeywordsValue = $isLinked ? trim((string) ($channelBinding['routing_keywords'] ?? '')) : '';
                                            ?>
                                            <article class="agent-channel-option <?= $isLinked ? 'is-linked' : '' ?>" data-agent-channel-option>
                                                <label class="agent-channel-link-toggle">
                                                    <input type="checkbox" name="instance_ids[]" value="<?= $channelId ?>" <?= $isLinked ? 'checked' : '' ?> data-agent-channel-link>
                                                    <span class="agent-channel-check" aria-hidden="true"></span>
                                                    <span>
                                                        <strong><?= View::e($instance['name']) ?></strong>
                                                        <small><?= $isLinked ? 'Assistente vinculado a este WhatsApp' : 'Marque para vincular este WhatsApp' ?></small>
                                                    </span>
                                                </label>
                                                <div class="agent-channel-routing-config">
                                                    <label class="field compact-field">
                                                        <span>Papel neste canal</span>
                                                        <select name="routing_mode[<?= $channelId ?>]" data-routing-mode <?= !$isLinked ? 'disabled' : '' ?>>
                                                            <option value="primary" <?= $routingMode === 'primary' ? 'selected' : '' ?>>Principal / recepção</option>
                                                            <option value="specialist" <?= $routingMode === 'specialist' ? 'selected' : '' ?>>Especialista por assunto</option>
                                                            <option value="round_robin" <?= $routingMode === 'round_robin' ? 'selected' : '' ?>>Distribuição automática</option>
                                                        </select>
                                                    </label>
                                                    <label class="field compact-field agent-routing-keywords <?= $routingMode === 'specialist' ? 'is-visible' : '' ?>" data-routing-keywords-field>
                                                        <span>Intenções / palavras de direcionamento</span>
                                                        <textarea name="routing_keywords[<?= $channelId ?>]" rows="3" maxlength="1000" placeholder="Ex.: comercial, vendas, planos, preço, orçamento" data-routing-keywords <?= (!$isLinked || $routingMode !== 'specialist') ? 'disabled' : '' ?>><?= View::e($routingKeywordsValue) ?></textarea>
                                                        <small class="field-hint">Quando uma mensagem contiver uma dessas intenções, a conversa é transferida para este assistente e permanece com ele.</small>
                                                    </label>
                                                    <div class="agent-routing-mode-hint" data-routing-mode-hint></div>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="field-hint agent-channel-help">Principal recebe o atendimento geral. Especialista só entra quando a intenção configurada for identificada. Distribuição automática participa do round-robin das novas conversas gerais.</p>
                                <?php else: ?>
                                    <div class="message-warning">Cadastre uma conexão em Canais WhatsApp antes de vincular este assistente.</div>
                                <?php endif; ?>
                            </section>

                            <div class="form-grid two">
                                <label class="field compact-field"><span>Disponibilidade do assistente</span><select name="status"><option value="active" <?= $agent['status'] === 'active' ? 'selected' : '' ?>>Ativo</option><option value="inactive" <?= $agent['status'] === 'inactive' ? 'selected' : '' ?>>Inativo</option></select></label>
                                <label class="field compact-field"><span>Nível de economia da IA</span><select name="ai_efficiency_mode"><option value="economy" <?= ($agent['ai_efficiency_mode'] ?? 'balanced') === 'economy' ? 'selected' : '' ?>>Econômico</option><option value="balanced" <?= ($agent['ai_efficiency_mode'] ?? 'balanced') === 'balanced' ? 'selected' : '' ?>>Equilibrado</option><option value="quality" <?= ($agent['ai_efficiency_mode'] ?? 'balanced') === 'quality' ? 'selected' : '' ?>>Qualidade máxima</option></select><small class="field-hint">Controla automaticamente histórico, base de conhecimento e tamanho da resposta.</small></label>
                                <label class="field compact-field"><span>Mensagens lembradas</span><input type="number" name="max_context_messages" value="<?= (int) ($agent['max_context_messages'] ?? 12) ?>" min="4" max="30"><small class="field-hint">Funciona como teto. O modo Econômico usa no máximo 6 e o Equilibrado no máximo 10.</small></label>
                                <label class="field compact-field"><span>Tamanho máximo da resposta</span><input type="number" name="ai_max_output_tokens" value="<?= View::e((string) ($agent['ai_max_output_tokens'] ?? '')) ?>" min="64" max="2000" placeholder="Automático pelo modo"><small class="field-hint">Se ficar vazio, o sistema usa um limite adequado ao nível de economia escolhido.</small></label>
                                <label class="field compact-field"><span>Orçamento da base (caracteres)</span><input type="number" name="ai_knowledge_budget_chars" value="<?= View::e((string) ($agent['ai_knowledge_budget_chars'] ?? '')) ?>" min="1000" max="120000" placeholder="Automático pelo modo"><small class="field-hint">Limita quanto da base de conhecimento entra em cada chamada.</small></label>
                                <label class="field compact-field">
                                    <span>Tempo de espera da IA (seg.)</span>
                                    <input type="number" name="cooldown_seconds" value="<?= (int) ($agent['cooldown_seconds'] ?? 15) ?>" min="0" max="3600">
                                    <small class="field-hint">A IA aguarda este tempo após a última mensagem recebida. Se outra chegar durante a espera, o relógio reinicia e as mensagens são agrupadas no contexto.</small>
                                </label>
                            </div>
                            <details class="ai-local-automation-card">
                                <summary><span><strong>Respostas sem nova cobrança de IA</strong><small>Saudações prontas e reaproveitamento opcional antes de chamar o serviço de IA.</small></span><span class="drawer-chevron"></span></summary>
                                <div class="ai-local-automation-body">
                                    <div class="agent-toggle-grid">
                                        <label class="check-field compact-check"><input type="checkbox" name="ai_local_replies_enabled" value="1" <?= !array_key_exists('ai_local_replies_enabled', $agent) || (int) ($agent['ai_local_replies_enabled'] ?? 1) === 1 ? 'checked' : '' ?>><span>Usar respostas locais configuradas</span></label>
                                        <label class="check-field compact-check"><input type="checkbox" name="ai_exact_cache_enabled" value="1" <?= (int) ($agent['ai_exact_cache_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><span>Reutilizar perguntas idênticas elegíveis</span></label>
                                    </div>
                                    <div class="form-grid two">
                                        <label class="field compact-field"><span>Resposta para saudação</span><input name="ai_greeting_reply" value="<?= View::e($agent['ai_greeting_reply'] ?? '') ?>" maxlength="500" placeholder="Olá! Como posso ajudar você hoje?"></label>
                                        <label class="field compact-field"><span>Resposta para agradecimento</span><input name="ai_gratitude_reply" value="<?= View::e($agent['ai_gratitude_reply'] ?? '') ?>" maxlength="500" placeholder="Por nada! Estou à disposição."></label>
                                        <label class="field compact-field"><span>Resposta para despedida</span><input name="ai_farewell_reply" value="<?= View::e($agent['ai_farewell_reply'] ?? '') ?>" maxlength="500" placeholder="Até mais! Quando precisar, fale com a gente."></label>
                                        <label class="field compact-field"><span>Por quantas horas uma resposta pode ser reaproveitada?</span><input type="number" name="ai_exact_cache_ttl_hours" value="<?= (int) ($agent['ai_exact_cache_ttl_hours'] ?? 168) ?>" min="1" max="720"><small class="field-hint">As respostas salvas são apagadas automaticamente quando as instruções, informações ou modelo mudam.</small></label>
                                    </div>
                                    <label class="field compact-field"><span>Resposta para menu/ajuda</span><textarea name="ai_menu_reply" rows="3" maxlength="4000" placeholder="Liste aqui as opções principais disponíveis."><?= View::e($agent['ai_menu_reply'] ?? '') ?></textarea></label>
                                    <p class="field-hint">As respostas prontas atendem somente mensagens curtas e exatas. O reaproveitamento vem desligado por padrão e não é usado em dados pessoais, pedidos, agenda, números ou links.</p>
                                </div>
                            </details>
                            <details class="ai-local-automation-card ai-memory-config-card">
                                <summary><span><strong>Memória da conversa</strong><small>Cria um resumo de tempos em tempos para lembrar o contexto usando menos IA.</small></span><span class="drawer-chevron"></span></summary>
                                <div class="ai-local-automation-body">
                                    <label class="check-field compact-check"><input type="checkbox" name="ai_progressive_memory_enabled" value="1" <?= !array_key_exists('ai_progressive_memory_enabled', $agent) || (int) ($agent['ai_progressive_memory_enabled'] ?? 1) === 1 ? 'checked' : '' ?>><span>Ativar resumo progressivo e memória estruturada</span></label>
                                    <div class="form-grid two">
                                        <label class="field compact-field"><span>Atualizar a cada N mensagens</span><input type="number" name="ai_memory_refresh_messages" value="<?= (int) ($agent['ai_memory_refresh_messages'] ?? 8) ?>" min="4" max="30"><small class="field-hint">Recomendado: 8. Evita gerar um resumo a cada resposta.</small></label>
                                        <label class="field compact-field"><span>Tamanho máximo do resumo</span><input type="number" name="ai_memory_max_chars" value="<?= (int) ($agent['ai_memory_max_chars'] ?? 2200) ?>" min="800" max="6000"><small class="field-hint">O resumo substitui parte do histórico antigo no contexto.</small></label>
                                    </div>
                                    <p class="field-hint">A memória preserva pedidos, decisões, preferências, pendências e próximos passos. Mensagens recentes sempre prevalecem sobre o resumo.</p>
                                </div>
                            </details>
                            <label class="field compact-field"><span>Palavras que pedem atendimento humano</span><input name="handoff_keywords" value="<?= View::e($agent['handoff_keywords'] ?? '') ?>" placeholder="humano, atendente, pessoa"></label>
                            <label class="field compact-field"><span>Ao chamar uma pessoa</span><select name="handoff_action"><option value="paused" <?= ($agent['handoff_action'] ?? 'paused') === 'paused' ? 'selected' : '' ?>>Pausar respostas automáticas</option><option value="human" <?= ($agent['handoff_action'] ?? '') === 'human' ? 'selected' : '' ?>>Marcar atendimento humano</option></select></label>
                            <label class="field compact-field"><span>Mensagem ao encaminhar para a equipe</span><input name="human_handoff_message" value="<?= View::e($agent['human_handoff_message'] ?? '') ?>" placeholder="Vou encaminhar você para uma pessoa da equipe."></label>
                            <label class="field compact-field"><span>Fuso horário</span><input name="business_timezone" value="<?= View::e($agent['business_timezone'] ?? 'America/Sao_Paulo') ?>"></label>
                            <div class="business-hours-editor" data-business-hours-editor>
                                <div class="business-hours-editor-head"><strong>Horário por dia</strong><small>Ative somente os dias de atendimento e defina a faixa de cada um.</small></div>
                                <?php foreach ($dayLabels as $dayKey => $label): $dayHour = $dayHours[$dayKey] ?? ['enabled' => false, 'start' => '08:00', 'end' => ($dayKey === 'sat' ? '12:00' : '18:00')]; ?>
                                    <div class="business-hours-day <?= !empty($dayHour['enabled']) ? 'is-enabled' : '' ?>" data-business-hours-day>
                                        <label class="business-hours-day-toggle"><input type="checkbox" name="business_day_enabled[<?= View::e($dayKey) ?>]" value="1" <?= !empty($dayHour['enabled']) ? 'checked' : '' ?> data-business-day-toggle><span><?= View::e($label) ?></span></label>
                                        <label class="field compact-field"><span>Início</span><input type="time" name="business_day_start[<?= View::e($dayKey) ?>]" value="<?= View::e((string) $dayHour['start']) ?>" data-business-day-time></label>
                                        <label class="field compact-field"><span>Fim</span><input type="time" name="business_day_end[<?= View::e($dayKey) ?>]" value="<?= View::e((string) $dayHour['end']) ?>" data-business-day-time></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <label class="field compact-field"><span>Mensagem fora do horário</span><input name="after_hours_message" value="<?= View::e($agent['after_hours_message'] ?? '') ?>" placeholder="Estamos fora do horário. Retornaremos em breve."></label>
                            <label class="field compact-field"><span>Automação externa deste assistente</span><input name="n8n_webhook_url" value="<?= View::e($agent['n8n_webhook_url'] ?? '') ?>" placeholder="Preencha somente com orientação da equipe RS Connect"><small class="field-hint">Use somente quando este assistente tiver uma automação própria. A Agenda Google é acionada automaticamente quando existe um compromisso real.</small></label>
                            <?php if (!empty($agent['n8n_calendar_conflict'])): ?><div class="message-warning"><strong>Configuração antiga precisa ser corrigida:</strong> este assistente está ligado diretamente a uma automação antiga que cria eventos no Google Calendar. Para evitar agendamentos duplicados, remova o endereço deste campo e mantenha a Agenda somente em Automações (n8n) → Fluxos por empresa.</div><?php endif; ?>
                            <div class="agent-toggle-grid">
                                <label class="check-field compact-check"><input type="checkbox" name="auto_reply_enabled" value="1" <?= (int) ($agent['auto_reply_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><span>Responder automaticamente</span></label>
                                <label class="check-field compact-check"><input type="checkbox" name="business_hours_enabled" value="1" <?= (int) ($agent['business_hours_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><span>Responder somente no horário configurado</span></label>
                                <label class="check-field compact-check"><input type="checkbox" name="n8n_enabled" value="1" <?= (int) ($agent['n8n_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><span>Usar integração externa</span></label>
                                <label class="check-field compact-check"><input type="checkbox" name="reply_to_reactions" value="1" <?= (int) ($agent['reply_to_reactions'] ?? 0) === 1 ? 'checked' : '' ?>><span>Responder a reações em mensagens</span></label>
                                <label class="check-field compact-check"><input type="checkbox" name="ai_selective_knowledge" value="1" <?= !array_key_exists('ai_selective_knowledge', $agent) || (int) ($agent['ai_selective_knowledge'] ?? 1) === 1 ? 'checked' : '' ?>><span>Enviar somente trechos relevantes da base</span></label>
                                <label class="check-field compact-check"><input type="checkbox" name="is_default" value="1" <?= (int) $agent['is_default'] === 1 ? 'checked' : '' ?>><span>Assistente de apoio</span></label>
                            </div>
                            <p class="field-hint"><strong>O horário configurado sempre tem prioridade.</strong> Quando “Responder somente no horário configurado” estiver ativo, IA, agenda, seleção de horários e automações conversacionais ficam bloqueadas fora do expediente, mesmo que as instruções do assistente digam para atender 24 horas.</p>
                            <button class="btn btn-outline" type="submit">Salvar configurações</button>
                        </form>

                        <details class="agent-group-rules">
                            <summary>
                                <span><strong>Regras por grupo de contato</strong><small>Defina como pacientes, interessados, familiares e outros grupos devem ser atendidos.</small></span>
                                <span class="drawer-chevron"></span>
                            </summary>
                            <form method="post" action="<?= View::e(Router::url('/agents/group-rules')) ?>">
                                <?= Csrf::input() ?>
                                <?php if (Auth::isSuperAdmin()): ?><input type="hidden" name="tenant_id" value="<?= $selectedTenantId ?>"><?php endif; ?>
                                <input type="hidden" name="agent_id" value="<?= (int) $agent['id'] ?>">
                                <div class="agent-group-rule-grid">
                                    <?php foreach ($contactGroups as $groupKey => $groupLabel): ?>
                                        <?php
                                        $savedRule = $groupRules[(int) $agent['id']][$groupKey] ?? [];
                                        $defaults = match ($groupKey) {
                                            'customer' => ['allow' => 1, 'require' => 0, 'reschedule' => 1, 'instructions' => 'Cliente atual: use cadastro e histórico existentes e não reinicie a triagem como novo interessado. Pergunte somente o que for necessário para o pedido atual.'],
                                            'patient' => ['allow' => 1, 'require' => 1, 'reschedule' => 1, 'instructions' => 'Paciente atual: não peça novamente a queixa quando ele estiver apenas remarcando um atendimento.'],
                                            'family' => ['allow' => 0, 'require' => 1, 'reschedule' => 0, 'instructions' => 'Siga a regra da empresa para familiares antes de oferecer atendimento ou agenda.'],
                                            'couple' => ['allow' => 0, 'require' => 1, 'reschedule' => 0, 'instructions' => 'Não abra pré-agendamento automático quando a empresa atende somente individualmente.'],
                                            default => ['allow' => 1, 'require' => 1, 'reschedule' => 0, 'instructions' => ''],
                                        };
                                        $allow = array_key_exists('allow_pre_schedule', $savedRule) ? (int) $savedRule['allow_pre_schedule'] : $defaults['allow'];
                                        $require = array_key_exists('require_demand_before_pre_schedule', $savedRule) ? (int) $savedRule['require_demand_before_pre_schedule'] : $defaults['require'];
                                        $reschedule = array_key_exists('allow_reschedule_without_demand', $savedRule) ? (int) $savedRule['allow_reschedule_without_demand'] : $defaults['reschedule'];
                                        $instructions = trim((string) ($savedRule['instructions'] ?? $defaults['instructions']));
                                        ?>
                                        <section class="agent-group-rule-card">
                                            <div><span class="eyebrow">Grupo</span><h4><?= View::e($groupLabel) ?></h4></div>
                                            <label class="check-field compact-check"><input type="checkbox" name="group_rules[<?= View::e($groupKey) ?>][allow_pre_schedule]" value="1" <?= $allow === 1 ? 'checked' : '' ?>><span>Permitir pré-agendamento</span></label>
                                            <label class="check-field compact-check"><input type="checkbox" name="group_rules[<?= View::e($groupKey) ?>][require_demand_before_pre_schedule]" value="1" <?= $require === 1 ? 'checked' : '' ?>><span>Exigir demanda antes da agenda</span></label>
                                            <label class="check-field compact-check"><input type="checkbox" name="group_rules[<?= View::e($groupKey) ?>][allow_reschedule_without_demand]" value="1" <?= $reschedule === 1 ? 'checked' : '' ?>><span>Permitir remarcação sem repetir a demanda</span></label>
                                            <label class="field compact-field"><span>Orientação específica</span><textarea name="group_rules[<?= View::e($groupKey) ?>][instructions]" rows="4" placeholder="Explique como o assistente deve agir com este grupo."><?= View::e($instructions) ?></textarea></label>
                                        </section>
                                    <?php endforeach; ?>
                                </div>
                                <div class="agent-group-rule-actions"><button class="btn btn-primary" type="submit">Salvar regras dos grupos</button></div>
                            </form>
                        </details>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$agents): ?><div class="empty-state">Nenhum assistente cadastrado ainda.</div><?php endif; ?>
        </div>
    </section>
</div>

<?php if ($canManage): ?>
<?php if ($isClientExperience): ?>
<aside class="conversation-details agent-create-drawer agent-client-drawer" id="agent-create-drawer" aria-label="Criar novo assistente" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header agent-client-drawer-header">
        <div>
            <span class="eyebrow">Novo assistente</span>
            <h2>Crie seu assistente de atendimento</h2>
            <p>Defina quem ele atende, como conversa e quais informações pode usar nas respostas.</p>
        </div>
        <button class="icon-button drawer-close" type="button" data-close-panel="agent-create-drawer" aria-label="Fechar painel">×</button>
    </div>

    <form class="drawer-form agent-create-form agent-guided-form" method="post" action="<?= View::e(Router::url('/agents')) ?>" data-prompt-studio data-prompt-endpoint="<?= View::e(Router::url('/prompt-studio/generate')) ?>">
        <?= Csrf::input() ?>
        <?php if (Auth::isSuperAdmin()): ?><input type="hidden" name="tenant_id" value="<?= $selectedTenantId ?>"><?php endif; ?>
        <div class="agent-drawer-progress" aria-label="Etapas do cadastro">
            <span><b>1</b> Identificação</span>
            <span><b>2</b> Atendimento</span>
            <span><b>3</b> Empresa</span>
            <span><b>4</b> Prompt</span>
        </div>

        <div class="conversation-drawer-body agent-client-drawer-body">
            <section class="drawer-section agent-step-card">
                <div class="drawer-section-title">
                    <div><span class="eyebrow">Etapa 1</span><h3>Quem vai atender?</h3><p>Escolha o WhatsApp e dê uma identidade simples ao assistente.</p></div>
                </div>
                <div class="drawer-form-grid">
                    <label class="field drawer-span"><span>Canal inicial</span><select name="instance_id" required><option value="">Selecione o WhatsApp</option><?php foreach ($instances as $instance): ?><option value="<?= (int) $instance['id'] ?>"><?= View::e($instance['name']) ?></option><?php endforeach; ?></select><small class="field-hint">É o primeiro número em que ele atuará. Outros canais podem ser adicionados depois.</small></label>
                    <label class="field"><span>Papel neste canal</span><select name="routing_mode" data-routing-mode><option value="primary" <?= count($agents) === 0 ? 'selected' : '' ?>>Principal / recepção</option><option value="specialist">Especialista por assunto</option><option value="round_robin" <?= count($agents) > 0 ? 'selected' : '' ?>>Distribuição automática</option></select><small class="field-hint">Você poderá alterar esta opção a qualquer momento.</small></label>
                    <label class="field agent-routing-keywords" data-routing-keywords-field><span>Intenções / palavras de direcionamento</span><input name="routing_keywords" maxlength="1000" placeholder="comercial, vendas, planos, preço, orçamento" data-routing-keywords disabled><small class="field-hint">Obrigatório quando o papel for Especialista.</small></label>
                    <label class="field"><span>Nome do assistente</span><input name="name" placeholder="Ex.: Digi" required></label>
                    <label class="field"><span>Área de atendimento</span><input name="segment" placeholder="Ex.: vendas e agendamentos" required></label>
                    <label class="field drawer-span"><span>Objetivo do atendimento</span><textarea name="service_objective" rows="4" placeholder="Ex.: responder dúvidas, identificar a necessidade do cliente, apresentar os serviços e encaminhar oportunidades para a equipe." required></textarea><small class="field-hint">Explique em palavras simples o resultado esperado de cada conversa.</small></label>
                </div>
            </section>

            <section class="drawer-section agent-step-card">
                <div class="drawer-section-title">
                    <div><span class="eyebrow">Etapa 2</span><h3>Como ele deve responder?</h3><p>Defina o estilo da conversa e as regras mais importantes.</p></div>
                </div>
                <div class="drawer-form-grid">
                    <label class="field"><span>Tom de voz</span><select name="tone_of_voice"><option value="claro, cordial e profissional">Claro e profissional</option><option value="acolhedor, paciente e próximo">Acolhedor e próximo</option><option value="objetivo, direto e consultivo">Objetivo e consultivo</option><option value="descontraído, simpático e respeitoso">Descontraído e simpático</option></select></label>
                    <label class="field"><span>Mensagem de boas-vindas</span><input name="welcome_message" placeholder="Ex.: Olá! Como posso ajudar você hoje?"></label>
                    <label class="field drawer-span"><span>Regras principais</span><textarea name="assistant_rules" rows="7" placeholder="Ex.: faça uma pergunta por vez; não informe preços sem confirmar; encaminhe para uma pessoa quando o cliente pedir; nunca invente informações." required></textarea></label>
                    <label class="field drawer-span"><span>Público principal</span><textarea name="audience" rows="3" placeholder="Ex.: clientes atuais, novos interessados, pacientes, responsáveis ou empresas parceiras."></textarea></label>
                    <label class="field drawer-span"><span>Produtos e serviços</span><textarea name="services" rows="5" placeholder="Liste os serviços e explique brevemente o que pode ser apresentado ao contato."></textarea></label>
                    <label class="field drawer-span"><span>Informações autorizadas</span><textarea name="allowed_information" rows="4" placeholder="Preços confirmados, prazos, links, políticas e informações que o agente pode informar com segurança."></textarea></label>
                    <label class="field drawer-span"><span>Perguntas essenciais</span><textarea name="required_questions" rows="4" placeholder="Quais dados são realmente necessários? Ex.: nome, serviço desejado e melhor período."></textarea></label>
                    <div class="form-grid two drawer-span">
                        <label class="field"><span>Como tratar novos interessados</span><textarea name="lead_rules" rows="4" placeholder="Ex.: entender a demanda e apresentar o próximo passo sem pressionar."></textarea></label>
                        <label class="field"><span>Como tratar clientes atuais</span><textarea name="customer_rules" rows="4" placeholder="Ex.: usar cadastro e histórico, sem reiniciar a triagem."></textarea></label>
                    </div>
                    <label class="field drawer-span"><span>Regras de agenda</span><textarea name="agenda_rules" rows="4" placeholder="Ex.: perguntar modalidade, nunca inventar disponibilidade e aguardar aprovação quando exigida."></textarea></label>
                    <label class="field drawer-span"><span>Quando encaminhar para uma pessoa</span><textarea name="handoff_rules" rows="4" placeholder="Ex.: quando o cliente pedir humano, houver reclamação sensível ou faltar autorização."></textarea></label>
                    <label class="field drawer-span"><span>O que o agente nunca deve fazer</span><textarea name="forbidden_information" rows="4" placeholder="Ex.: inventar preço, prometer prazo, confirmar agenda sem retorno ou expor dados internos."></textarea></label>
                    <label class="field drawer-span"><span>Exemplos de bom comportamento</span><textarea name="examples" rows="5" placeholder="Ex.: Cliente: quero remarcar. Assistente: claro, vou verificar seu agendamento atual e as opções disponíveis."></textarea></label>
                    <input type="hidden" name="response_style" value="mensagens curtas, naturais e com uma pergunta por vez">
                </div>
                <div class="agent-create-behavior agent-friendly-toggles">
                    <label class="switch-card"><input type="checkbox" name="auto_reply_enabled" value="1" checked><span><strong>Responder automaticamente</strong><small>O assistente responde quando a conversa estiver no modo IA ativa.</small></span></label>
                    <label class="switch-card"><input type="checkbox" name="is_default" value="1"><span><strong>Assistente de apoio</strong><small>Usado apenas quando um número ainda não tem um assistente principal definido. O responsável de cada WhatsApp é escolhido em Canais WhatsApp.</small></span></label>
                </div>
            </section>

            <section class="drawer-section agent-step-card">
                <div class="drawer-section-title">
                    <div><span class="eyebrow">Etapa 3</span><h3>O que ele precisa saber sobre a empresa?</h3><p>Revise as informações que poderão ser usadas nas respostas.</p></div>
                </div>
                <label class="field"><span>Informações da empresa</span><textarea name="knowledge_base" rows="11" placeholder="Serviços, horários, links, políticas, perguntas frequentes e outras informações importantes."><?= View::e($defaultCompanyKnowledge) ?></textarea><small class="field-hint">O conteúdo foi preenchido com os dados do Perfil da empresa. Você pode complementar antes de criar.</small></label>
            </section>

            <section class="drawer-section agent-step-card prompt-studio-output">
                <div class="drawer-section-title">
                    <div><span class="eyebrow">Etapa 4</span><h3>Criar e revisar as instruções</h3><p>O RS Connect organiza as orientações do assistente e verifica conflitos com horário, agenda e regras de atendimento.</p></div>
                </div>
                <div class="prompt-studio-toolbar">
                    <button class="btn btn-outline" type="button" data-prompt-generate>Criar instruções organizadas</button>
                    <span class="muted-text" data-prompt-status>Preencha as etapas anteriores e gere a primeira versão.</span>
                </div>
                <div class="prompt-studio-warnings" data-prompt-warnings hidden></div>
                <label class="field"><span>Instruções finais do assistente</span><textarea class="agent-prompt-textarea" name="system_prompt" rows="18" maxlength="60000" placeholder="As instruções organizadas aparecerão aqui. Você poderá revisar antes de criar o assistente." required></textarea><small class="field-hint">Revise e altere qualquer parte antes de salvar.</small></label>
                <input type="hidden" name="prompt_studio_generated" value="0" data-prompt-generated>
                <input type="hidden" name="prompt_studio_answers_json" value="" data-prompt-answers>
                <input type="hidden" name="prompt_studio_warnings_json" value="" data-prompt-warnings-json>
            </section>

            <details class="drawer-section drawer-collapsed-card agent-advanced-settings">
                <summary>
                    <span><span class="eyebrow">Opcional</span><strong>Configurações avançadas</strong><small>Horários, transferência para a equipe e ajustes técnicos.</small></span>
                    <span class="drawer-chevron"></span>
                </summary>
                <div class="agent-advanced-body">
                    <div class="form-grid two">
                        <label class="field"><span>Serviço de IA</span><select name="model_provider"><option value="openai">OpenAI</option><option value="google">Google Gemini</option><option value="custom">Outro serviço</option></select></label>
                        <label class="field"><span>Estilo das respostas</span><select name="temperature"><option value="0.1">Mais objetivo</option><option value="0.2" selected>Equilibrado</option><option value="0.5">Mais criativo</option><option value="0.8">Bem criativo</option></select></label>
                    </div>
                    <label class="field"><span>Modelo de IA</span><input name="model_name" value="gpt-4o-mini" required><small class="field-hint">A equipe RS Connect pode orientar este ajuste.</small></label>
                    <label class="field"><span>Palavras para chamar uma pessoa</span><input name="handoff_keywords" value="humano, atendente, pessoa, suporte"></label>
                    <label class="field"><span>Mensagem ao encaminhar para a equipe</span><input name="human_handoff_message" value="Vou encaminhar você para uma pessoa da nossa equipe. Aguarde um momento, por favor."></label>
                    <input type="hidden" name="handoff_action" value="paused">
                    <label class="field"><span>Fuso horário</span><input name="business_timezone" value="America/Sao_Paulo"></label>
                    <div class="business-hours-editor" data-business-hours-editor>
                        <div class="business-hours-editor-head"><strong>Horário por dia</strong><small>Você pode, por exemplo, usar Seg–Sex 08:00–17:00 e Sáb 08:00–12:00.</small></div>
                        <?php foreach ($dayLabels as $dayKey => $label): $enabledByDefault = in_array($dayKey, ['mon','tue','wed','thu','fri'], true); ?>
                            <div class="business-hours-day <?= $enabledByDefault ? 'is-enabled' : '' ?>" data-business-hours-day>
                                <label class="business-hours-day-toggle"><input type="checkbox" name="business_day_enabled[<?= View::e($dayKey) ?>]" value="1" <?= $enabledByDefault ? 'checked' : '' ?> data-business-day-toggle><span><?= View::e($label) ?></span></label>
                                <label class="field"><span>Início</span><input type="time" name="business_day_start[<?= View::e($dayKey) ?>]" value="08:00" data-business-day-time></label>
                                <label class="field"><span>Fim</span><input type="time" name="business_day_end[<?= View::e($dayKey) ?>]" value="<?= $dayKey === 'sat' ? '12:00' : '18:00' ?>" data-business-day-time></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <label class="field"><span>Mensagem fora do horário</span><input name="after_hours_message" value="Estamos fora do horário de atendimento agora. Assim que retornarmos, nossa equipe responde por aqui."></label>
                    <div class="ai-efficiency-create-card">
                        <div><span class="eyebrow">Economia de IA</span><strong>Estratégia de consumo</strong><small>O modo Equilibrado é recomendado para começar.</small></div>
                        <div class="form-grid two">
                            <label class="field"><span>Modo</span><select name="ai_efficiency_mode"><option value="economy">Econômico</option><option value="balanced" selected>Equilibrado</option><option value="quality">Qualidade máxima</option></select></label>
                            <label class="field"><span>Mensagens lembradas</span><input type="number" name="max_context_messages" value="12" min="4" max="30"></label>
                            <label class="field"><span>Tamanho máximo da resposta</span><input type="number" name="ai_max_output_tokens" min="64" max="2000" placeholder="Automático pelo modo"></label>
                            <label class="field"><span>Base por chamada (caracteres)</span><input type="number" name="ai_knowledge_budget_chars" min="1000" max="120000" placeholder="Automático pelo modo"></label>
                        </div>
                        <label class="check-field"><input type="checkbox" name="ai_selective_knowledge" value="1" checked><span>Enviar somente os trechos da base relacionados à conversa</span></label>
                    </div>
                    <details class="ai-local-automation-card">
                        <summary><span><strong>Respostas sem nova cobrança de IA</strong><small>Configure respostas de saudação. O reaproveitamento de perguntas iguais é opcional.</small></span><span class="drawer-chevron"></span></summary>
                        <div class="ai-local-automation-body">
                            <label class="check-field"><input type="checkbox" name="ai_local_replies_enabled" value="1" checked><span>Usar respostas locais configuradas</span></label>
                            <div class="form-grid two">
                                <label class="field"><span>Saudação</span><input name="ai_greeting_reply" maxlength="500" placeholder="Olá! Como posso ajudar você hoje?"></label>
                                <label class="field"><span>Agradecimento</span><input name="ai_gratitude_reply" maxlength="500" placeholder="Por nada! Estou à disposição."></label>
                                <label class="field"><span>Despedida</span><input name="ai_farewell_reply" maxlength="500" placeholder="Até mais! Quando precisar, fale com a gente."></label>
                                <label class="field"><span>Por quantas horas uma resposta pode ser reaproveitada?</span><input type="number" name="ai_exact_cache_ttl_hours" value="168" min="1" max="720"></label>
                            </div>
                            <label class="field"><span>Menu/ajuda</span><textarea name="ai_menu_reply" rows="3" maxlength="4000" placeholder="Liste as opções principais disponíveis."></textarea></label>
                            <label class="check-field"><input type="checkbox" name="ai_exact_cache_enabled" value="1"><span>Reutilizar respostas para perguntas idênticas e elegíveis</span></label>
                            <small class="field-hint">Respostas reaproveitadas não são usadas para agenda, dados pessoais, números, links ou mensagens que dependem da conversa.</small>
                        </div>
                    </details>
                    <label class="field"><span>Tempo de espera da IA (seg.)</span><input type="number" name="cooldown_seconds" value="15" min="0" max="3600"><small class="field-hint">A IA espera este tempo após a última mensagem recebida. Se o cliente enviar outra mensagem, a contagem reinicia.</small></label>
                    <label class="field"><span>Integração externa</span><input name="n8n_webhook_url" placeholder="Preencha somente com orientação da equipe RS Connect"></label>
                    <label class="check-field"><input type="checkbox" name="business_hours_enabled" value="1"><span>Responder somente no horário configurado</span></label>
                    <p class="field-hint">Quando ativado, este horário tem prioridade e pausa a IA, a agenda e outras automações fora do expediente.</p>
                    <label class="check-field"><input type="checkbox" name="n8n_enabled" value="1"><span>Usar integração externa neste assistente</span></label>
                    <label class="check-field"><input type="checkbox" name="reply_to_reactions" value="1"><span>Responder quando o contato reagir a uma mensagem</span><small class="field-hint">Desativado por padrão. Curtidas e emojis de reação não geram resposta automática.</small></label>
                </div>
            </details>
        </div>

        <div class="drawer-savebar agent-create-savebar agent-client-savebar">
            <button class="btn btn-quiet" type="button" data-close-panel="agent-create-drawer">Cancelar</button>
            <button class="btn btn-primary" type="submit" <?= !$instances ? 'disabled' : '' ?>>Criar assistente</button>
            <?php if (!$instances): ?><p class="field-hint">A equipe RS Connect precisa preparar uma conexão WhatsApp primeiro.</p><?php endif; ?>
        </div>
    </form>
</aside>
<?php else: ?>
<aside class="conversation-details agent-create-drawer" id="agent-create-drawer" aria-label="Cadastrar novo assistente">
    <div class="conversation-drawer-header">
        <div>
            <span class="eyebrow">Novo assistente</span>
            <h2>Criar assistente virtual</h2>
            <p>Preencha primeiro as informações essenciais. As opções técnicas ficam agrupadas no final.</p>
        </div>
        <button class="icon-button drawer-close" type="button" data-close-panel="agent-create-drawer" aria-label="Fechar painel">×</button>
    </div>

    <div class="conversation-drawer-body">
        <form class="drawer-form agent-create-form" method="post" action="<?= View::e(Router::url('/agents')) ?>" data-prompt-studio data-prompt-endpoint="<?= View::e(Router::url('/prompt-studio/generate')) ?>">
            <?= Csrf::input() ?>
            <?php if (Auth::isSuperAdmin()): ?><input type="hidden" name="tenant_id" value="<?= $selectedTenantId ?>"><?php endif; ?>

            <section class="drawer-section">
                <div class="drawer-section-title"><div><span class="eyebrow">1. Identificação</span><h3>Quem vai atender?</h3></div></div>
                <label class="field"><span>Canal inicial</span><select name="instance_id" required><option value="">Selecione o WhatsApp</option><?php foreach ($instances as $instance): ?><option value="<?= (int) $instance['id'] ?>"><?= View::e($instance['name']) ?></option><?php endforeach; ?></select><small class="field-hint">Escolha o primeiro WhatsApp deste assistente. Depois você pode vinculá-lo a outros canais na tela WhatsApp.</small></label>
                <label class="field"><span>Papel neste canal</span><select name="routing_mode" data-routing-mode><option value="primary" <?= count($agents) === 0 ? 'selected' : '' ?>>Principal / recepção</option><option value="specialist">Especialista por assunto</option><option value="round_robin" <?= count($agents) > 0 ? 'selected' : '' ?>>Distribuição automática</option></select></label>
                <label class="field agent-routing-keywords" data-routing-keywords-field><span>Intenções / palavras de direcionamento</span><input name="routing_keywords" maxlength="1000" placeholder="comercial, vendas, planos, preço, orçamento" data-routing-keywords disabled><small class="field-hint">Obrigatório quando o papel for Especialista.</small></label>
                <label class="field"><span>Nome do assistente</span><input name="name" placeholder="Ex.: Digi, Assistente Comercial" required></label>
                <label class="field"><span>Área de atendimento</span><input name="segment" placeholder="Ex.: vendas, suporte, agendamentos" required><small class="field-hint">Ajuda a identificar a função principal do assistente.</small></label>
            </section>

            <section class="drawer-section prompt-studio-output">
                <div class="drawer-section-title"><div><span class="eyebrow">2. Criador de instruções</span><h3>O que ele deve saber e fazer?</h3><p>Responda ao questionário e gere uma estrutura consistente antes de salvar.</p></div></div>
                <label class="field"><span>Objetivo do atendimento</span><textarea name="service_objective" rows="4" placeholder="Resultado esperado de cada conversa." required></textarea></label>
                <div class="form-grid two">
                    <label class="field"><span>Tom de voz</span><select name="tone_of_voice"><option value="claro, cordial e profissional">Claro e profissional</option><option value="acolhedor, paciente e próximo">Acolhedor e próximo</option><option value="objetivo, direto e consultivo">Objetivo e consultivo</option></select></label>
                    <label class="field"><span>Público principal</span><input name="audience" placeholder="Clientes, leads, pacientes..."></label>
                </div>
                <label class="field"><span>Produtos e serviços</span><textarea name="services" rows="4"></textarea></label>
                <label class="field"><span>Regras principais</span><textarea name="assistant_rules" rows="5" placeholder="Uma pergunta por vez, regras comerciais e limites."></textarea></label>
                <div class="form-grid two">
                    <label class="field"><span>Novos interessados</span><textarea name="lead_rules" rows="4"></textarea></label>
                    <label class="field"><span>Clientes atuais</span><textarea name="customer_rules" rows="4"></textarea></label>
                </div>
                <label class="field"><span>Agenda</span><textarea name="agenda_rules" rows="4"></textarea></label>
                <label class="field"><span>Transferência humana</span><textarea name="handoff_rules" rows="4"></textarea></label>
                <label class="field"><span>Restrições</span><textarea name="forbidden_information" rows="4"></textarea></label>
                <input type="hidden" name="response_style" value="mensagens curtas, naturais e com uma pergunta por vez">
                <div class="prompt-studio-toolbar"><button class="btn btn-outline" type="button" data-prompt-generate>Criar instruções organizadas</button><span class="muted-text" data-prompt-status>As instruções serão comparadas com as regras de atendimento.</span></div>
                <div class="prompt-studio-warnings" data-prompt-warnings hidden></div>
                <label class="field"><span>Instruções finais</span><textarea name="system_prompt" rows="16" maxlength="60000" required></textarea></label>
                <input type="hidden" name="prompt_studio_generated" value="0" data-prompt-generated>
                <input type="hidden" name="prompt_studio_answers_json" value="" data-prompt-answers>
                <input type="hidden" name="prompt_studio_warnings_json" value="" data-prompt-warnings-json>
                <label class="field"><span>Informações da empresa</span><textarea name="knowledge_base" rows="7" placeholder="Inclua serviços, horários, perguntas frequentes, regras, links e informações que podem ser usadas nas respostas."></textarea></label>
            </section>

            <section class="drawer-section agent-create-behavior">
                <div class="drawer-section-title"><div><span class="eyebrow">3. Funcionamento</span><h3>Comportamento inicial</h3></div></div>
                <label class="check-field"><input type="checkbox" name="auto_reply_enabled" value="1" checked><span>Responder automaticamente quando a conversa estiver com IA ativa</span></label>
                <label class="check-field"><input type="checkbox" name="is_default" value="1"><span>Usar como assistente de apoio da empresa</span></label>
            </section>

            <details class="drawer-section drawer-collapsed-card agent-advanced-settings">
                <summary>
                    <span><span class="eyebrow">Opcional</span><strong>Configurações avançadas</strong><small>Modelo de IA, horários, transferência e integração externa.</small></span>
                    <span class="drawer-chevron"></span>
                </summary>
                <div class="agent-advanced-body">
                    <div class="form-grid two">
                        <label class="field"><span>Serviço de IA</span><select name="model_provider"><option value="openai">OpenAI</option><option value="google">Google Gemini</option><option value="custom">Outro serviço</option></select></label>
                        <label class="field"><span>Estilo das respostas</span><select name="temperature"><option value="0.1">Mais objetivo</option><option value="0.2" selected>Equilibrado</option><option value="0.5">Mais criativo</option><option value="0.8">Bem criativo</option></select></label>
                    </div>
                    <label class="field"><span>Modelo de IA</span><input name="model_name" value="gpt-4o-mini" required><small class="field-hint">A equipe RS Connect pode ajustar este campo conforme a chave e o modelo disponíveis.</small></label>
                    <label class="field"><span>Palavras que pedem atendimento humano</span><input name="handoff_keywords" value="humano, atendente, pessoa, suporte" placeholder="humano, atendente, pessoa"></label>
                    <label class="field"><span>Mensagem ao encaminhar para a equipe</span><input name="human_handoff_message" value="Vou encaminhar você para uma pessoa da nossa equipe. Aguarde um momento, por favor."></label>
                    <input type="hidden" name="handoff_action" value="paused">
                    <label class="field"><span>Fuso horário</span><input name="business_timezone" value="America/Sao_Paulo"></label>
                    <div class="business-hours-editor" data-business-hours-editor>
                        <div class="business-hours-editor-head"><strong>Horário por dia</strong><small>Você pode, por exemplo, usar Seg–Sex 08:00–17:00 e Sáb 08:00–12:00.</small></div>
                        <?php foreach ($dayLabels as $dayKey => $label): $enabledByDefault = in_array($dayKey, ['mon','tue','wed','thu','fri'], true); ?>
                            <div class="business-hours-day <?= $enabledByDefault ? 'is-enabled' : '' ?>" data-business-hours-day>
                                <label class="business-hours-day-toggle"><input type="checkbox" name="business_day_enabled[<?= View::e($dayKey) ?>]" value="1" <?= $enabledByDefault ? 'checked' : '' ?> data-business-day-toggle><span><?= View::e($label) ?></span></label>
                                <label class="field"><span>Início</span><input type="time" name="business_day_start[<?= View::e($dayKey) ?>]" value="08:00" data-business-day-time></label>
                                <label class="field"><span>Fim</span><input type="time" name="business_day_end[<?= View::e($dayKey) ?>]" value="<?= $dayKey === 'sat' ? '12:00' : '18:00' ?>" data-business-day-time></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <label class="field"><span>Mensagem fora do horário</span><input name="after_hours_message" value="Estamos fora do horário de atendimento agora. Assim que retornarmos, nossa equipe responde por aqui."></label>
                    <div class="ai-efficiency-create-card">
                        <div><span class="eyebrow">Economia de IA</span><strong>Estratégia de consumo</strong><small>O modo Equilibrado é recomendado para começar.</small></div>
                        <div class="form-grid two">
                            <label class="field"><span>Modo</span><select name="ai_efficiency_mode"><option value="economy">Econômico</option><option value="balanced" selected>Equilibrado</option><option value="quality">Qualidade máxima</option></select></label>
                            <label class="field"><span>Mensagens lembradas</span><input type="number" name="max_context_messages" value="12" min="4" max="30"></label>
                            <label class="field"><span>Tamanho máximo da resposta</span><input type="number" name="ai_max_output_tokens" min="64" max="2000" placeholder="Automático pelo modo"></label>
                            <label class="field"><span>Base por chamada (caracteres)</span><input type="number" name="ai_knowledge_budget_chars" min="1000" max="120000" placeholder="Automático pelo modo"></label>
                        </div>
                        <label class="check-field"><input type="checkbox" name="ai_selective_knowledge" value="1" checked><span>Enviar somente os trechos da base relacionados à conversa</span></label>
                    </div>
                    <details class="ai-local-automation-card">
                        <summary><span><strong>Respostas sem nova cobrança de IA</strong><small>Configure respostas de saudação. O reaproveitamento de perguntas iguais é opcional.</small></span><span class="drawer-chevron"></span></summary>
                        <div class="ai-local-automation-body">
                            <label class="check-field"><input type="checkbox" name="ai_local_replies_enabled" value="1" checked><span>Usar respostas locais configuradas</span></label>
                            <div class="form-grid two">
                                <label class="field"><span>Saudação</span><input name="ai_greeting_reply" maxlength="500" placeholder="Olá! Como posso ajudar você hoje?"></label>
                                <label class="field"><span>Agradecimento</span><input name="ai_gratitude_reply" maxlength="500" placeholder="Por nada! Estou à disposição."></label>
                                <label class="field"><span>Despedida</span><input name="ai_farewell_reply" maxlength="500" placeholder="Até mais! Quando precisar, fale com a gente."></label>
                                <label class="field"><span>Por quantas horas uma resposta pode ser reaproveitada?</span><input type="number" name="ai_exact_cache_ttl_hours" value="168" min="1" max="720"></label>
                            </div>
                            <label class="field"><span>Menu/ajuda</span><textarea name="ai_menu_reply" rows="3" maxlength="4000" placeholder="Liste as opções principais disponíveis."></textarea></label>
                            <label class="check-field"><input type="checkbox" name="ai_exact_cache_enabled" value="1"><span>Reutilizar respostas para perguntas idênticas e elegíveis</span></label>
                            <small class="field-hint">Respostas reaproveitadas não são usadas para agenda, dados pessoais, números, links ou mensagens que dependem da conversa.</small>
                        </div>
                    </details>
                    <label class="field"><span>Tempo de espera da IA (seg.)</span><input type="number" name="cooldown_seconds" value="15" min="0" max="3600"><small class="field-hint">A IA espera este tempo após a última mensagem recebida. Se o cliente enviar outra mensagem, a contagem reinicia.</small></label>
                    <label class="field"><span>Integração externa</span><input name="n8n_webhook_url" placeholder="Preencha somente com orientação da equipe RS Connect"></label>
                    <label class="check-field"><input type="checkbox" name="business_hours_enabled" value="1"><span>Responder somente no horário configurado</span></label>
                    <p class="field-hint">Quando ativado, este horário tem prioridade e pausa a IA, a agenda e outras automações fora do expediente.</p>
                    <label class="check-field"><input type="checkbox" name="n8n_enabled" value="1"><span>Usar integração externa neste assistente</span></label>
                    <label class="check-field"><input type="checkbox" name="reply_to_reactions" value="1"><span>Responder quando o contato reagir a uma mensagem</span><small class="field-hint">Desativado por padrão. Curtidas e emojis de reação não geram resposta automática.</small></label>
                </div>
            </details>

            <div class="drawer-savebar agent-create-savebar">
                <button class="btn btn-primary btn-block" type="submit" <?= !$instances ? 'disabled' : '' ?>>Criar assistente</button>
                <?php if (!$instances): ?><p class="field-hint">A equipe RS Connect precisa preparar uma conexão WhatsApp primeiro.</p><?php endif; ?>
            </div>
        </form>
    </div>
</aside>
<?php endif; ?>
<?php endif; ?>
