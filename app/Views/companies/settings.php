<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$statusLabels = ['active' => 'Ativa', 'inactive' => 'Inativa', 'suspended' => 'Suspensa'];
$statusLabel = $statusLabels[$company['status'] ?? 'active'] ?? 'Ativa';
$canManageCompany = Auth::can('company.manage');
$profileFields = [
    $company['name'] ?? '', $company['segment'] ?? '', $company['email'] ?? '', $company['phone'] ?? '',
    $company['website'] ?? '', $company['company_about'] ?? '', $company['company_services'] ?? '',
    $company['company_differentials'] ?? '', $company['company_business_hours'] ?? '',
];
$filledProfile = count(array_filter($profileFields, static fn ($value): bool => trim((string) $value) !== ''));
$profilePercent = (int) round(($filledProfile / max(1, count($profileFields))) * 100);
$messageGovernanceSettings = is_array($messageGovernanceSettings ?? null) ? $messageGovernanceSettings : [];
$queueModuleDefinition = is_array(($availableModules ?? [])['queue'] ?? null) ? $availableModules['queue'] : [];
$queueEnabled = (bool) (($moduleSettings['queue']['is_enabled'] ?? null) ?? ($queueModuleDefinition['default_enabled'] ?? false));
?>
<?php if (Auth::isSuperAdmin()): ?>

<div class="hero-card compact-hero">
    <div>
        <span class="eyebrow light">Perfil empresarial</span>
        <h2><?= View::e($company['name']) ?></h2>
        <p>Esses dados identificam a empresa dentro do RS Connect e serão usados nos módulos de atendimento e automação.</p>
    </div>
    <?php if (Auth::isSuperAdmin()): ?><div class="hero-actions"><a class="btn btn-light" href="#company-module-settings">Menus do cliente</a><a class="btn btn-light" href="<?= View::e(Router::url('/companies')) ?>">Voltar às empresas</a></div><?php endif; ?>
</div>

<form class="card form-card-wide" method="post" action="<?= View::e(Router::url('/company-settings')) ?>">
    <?= Csrf::input() ?>
    <input type="hidden" name="tenant_id" value="<?= (int) $company['id'] ?>">
    <div class="section-heading">
        <div><span class="eyebrow">Dados cadastrais</span><h2>Informações da empresa</h2></div>
        <span class="badge badge-<?= View::e($company['status']) ?>"><?= View::e(ucfirst($company['status'])) ?></span>
    </div>

    <div class="form-grid two">
        <label class="field"><span>Nome de exibição</span><input name="name" value="<?= View::e($company['name']) ?>" required></label>
        <label class="field"><span>Razão social</span><input name="legal_name" value="<?= View::e($company['legal_name'] ?? '') ?>"></label>
        <label class="field"><span>CNPJ/CPF</span><input name="document" value="<?= View::e($company['document'] ?? '') ?>"></label>
        <label class="field"><span>Segmento</span><input name="segment" value="<?= View::e($company['segment'] ?? '') ?>" placeholder="Clínica, comércio, imobiliária..."></label>
        <label class="field"><span>E-mail comercial</span><input type="email" name="email" value="<?= View::e($company['email'] ?? '') ?>"></label>
        <label class="field"><span>Telefone</span><input name="phone" value="<?= View::e($company['phone'] ?? '') ?>"></label>
    </div>
    <label class="field"><span>Site</span><input type="url" name="website" value="<?= View::e($company['website'] ?? '') ?>" placeholder="https://empresa.com.br"></label>

    <div class="readonly-grid">
        <div><span>Slug</span><strong><?= View::e($company['slug']) ?></strong></div>
        <div><span>Plano</span><strong><?= View::e(ucfirst($company['plan'])) ?></strong></div>
        <div><span>Onboarding</span><strong><?= $company['onboarding_completed_at'] ? 'Concluído' : 'Etapa ' . (int) $company['onboarding_step'] . '/7' ?></strong></div>
    </div>


    <?php
    $agentBlueprintProfile = is_array($agentBlueprintProfile ?? null) ? $agentBlueprintProfile : [];
    $agentCapabilities = is_array($agentBlueprintProfile['capabilities'] ?? null) ? $agentBlueprintProfile['capabilities'] : [];
    $agentTriageFields = is_array($agentBlueprintProfile['triage_fields'] ?? null) ? $agentBlueprintProfile['triage_fields'] : [];
    $agentPolicies = is_array($agentBlueprintProfile['policies'] ?? null) ? $agentBlueprintProfile['policies'] : [];
    $agentWorkflow = is_array($agentBlueprintProfile['workflow'] ?? null) ? $agentBlueprintProfile['workflow'] : [];

    $capabilityLabels = [
        'triage.enabled' => 'Coletar informações antes de avançar',
        'eligibility.enabled' => 'Validar as regras antes de atender',
        'calendar.read' => 'Verificar horários na agenda',
        'calendar.pre_schedule' => 'Fazer pré-reserva de horário',
        'calendar.confirm' => 'Confirmar agendamento automaticamente',
        'calendar.human_approval' => 'Pedir aprovação da equipe antes de confirmar',
        'handoff.audio' => 'Passar para uma pessoa ao receber áudio',
        'policy.fail_closed' => 'Bloquear ações quando houver dúvida ou erro',
    ];
    $capabilityHelp = [
        'triage.enabled' => 'O assistente pergunta somente o que ainda estiver faltando e guarda as respostas na conversa.',
        'eligibility.enabled' => 'Antes de seguir para ações importantes, confere idade, tipo de atendimento e outras regras configuradas.',
        'calendar.read' => 'Permite consultar horários reais. Não dá permissão para confirmar sozinho.',
        'calendar.pre_schedule' => 'Permite segurar um horário válido antes da confirmação final.',
        'calendar.confirm' => 'Quando ligado, o sistema pode concluir o agendamento sem depender de uma pessoa da equipe.',
        'calendar.human_approval' => 'Mesmo com horário disponível, a confirmação final fica aguardando alguém da equipe.',
        'handoff.audio' => 'Ao receber áudio, o assistente para e deixa a conversa para atendimento humano.',
        'policy.fail_closed' => 'Se uma regra não puder ser validada, o sistema prefere não executar a ação em vez de correr risco.',
    ];
    $fieldTypeLabels = ['text' => 'Texto', 'textarea' => 'Texto livre', 'number' => 'Número', 'boolean' => 'Sim ou não', 'select' => 'Lista de opções', 'date' => 'Data', 'time' => 'Horário'];
    $policyLabels = [
        'minimum_age' => 'Idade mínima para atendimento',
        'couple_service_allowed' => 'Permitir atendimento de casal',
        'scheduling_requires_eligibility' => 'Validar as regras antes de consultar a agenda',
        'confirmation_requires_human' => 'Confirmação depende da equipe',
        'service_required' => 'Exigir o serviço antes de consultar a agenda',
        'required_before_schedule' => 'Informações obrigatórias antes da agenda',
    ];
    $policyHelp = [
        'minimum_age' => 'Se a idade informada for menor que este valor, o assistente envia a mensagem configurada e continua a conversa, mas não consulta nem reserva horário.',
        'couple_service_allowed' => 'Define se esse tipo de atendimento pode seguir normalmente neste modelo.',
        'scheduling_requires_eligibility' => 'Impede que o assistente pule a validação das regras e vá direto para a agenda.',
        'confirmation_requires_human' => 'O horário pode ser pré-reservado, mas só fica confirmado após aprovação da equipe.',
        'service_required' => 'Usado em negócios como salão e barbearia para saber a duração e o tipo de atendimento antes de procurar horário.',
    ];
    $policyActionLabels = [
        'block' => 'Bloquear a ação',
        'block_schedule' => 'Não permitir agenda (a conversa continua)',
        'human_approval' => 'Pedir aprovação da equipe',
        'collect' => 'Pedir a informação antes de seguir',
        'allow_confirm' => 'Permitir confirmação automática',
        'handoff' => 'Passar para atendimento humano',
        'warn' => 'Apenas sinalizar atenção',
    ];
    $decisionLabels = ['allow' => 'Permitido', 'block' => 'Bloqueado', 'collect' => 'Faltou informação', 'handoff' => 'Passado para a equipe', 'warn' => 'Atenção'];
    $decisionClasses = ['allow' => 'badge-active', 'block' => 'badge-rejected', 'collect' => 'badge-warning', 'handoff' => 'badge-pending', 'warn' => 'badge-warning'];
    $reasonLabels = [
        'minimum_age' => 'Idade abaixo do permitido',
        'couple_service_not_allowed' => 'Tipo de atendimento não permitido',
        'triage_incomplete' => 'Faltam informações obrigatórias',
        'human_approval_required' => 'Precisa de aprovação da equipe',
        'capability_denied' => 'Ação não liberada nas configurações',
        'allowed' => 'Regras atendidas',
        'profile_inactive' => 'Regras específicas ainda não estão ativas',
    ];
    $humanizeInternalKey = static function (string $key): string {
        $value = preg_replace('/[_\.]+/', ' ', trim($key)) ?? $key;
        return $value !== '' ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : 'Regra personalizada';
    };
    ?>
    <section class="settings-block agent-architecture-settings agent-rules-panel" id="agent-architecture-settings">
        <input type="hidden" name="agent_architecture_settings_submitted" value="1">
        <div class="section-heading compact agent-rules-heading">
            <div>
                <span class="eyebrow">Regras do assistente</span>
                <h2>Como o assistente deve atender</h2>
                <p>Escolha um modelo pronto para o segmento e ajuste as informações, limites e permissões desta empresa. O Prompt Studio continua cuidando apenas do jeito de falar.</p>
            </div>
            <span class="badge <?= ($agentBlueprintProfile['status'] ?? 'inactive') === 'active' ? 'badge-active' : 'badge-pending' ?>"><?= ($agentBlueprintProfile['status'] ?? 'inactive') === 'active' ? 'Regras ativas' : 'Modelo ainda não aplicado' ?></span>
        </div>

        <div class="agent-rules-intro">
            <div><span>1</span><strong>Escolha o segmento</strong><small>Psicologia, salão, clínica, serviços etc.</small></div>
            <div><span>2</span><strong>Escolha o modelo</strong><small>O RS Connect carrega uma base pronta para esse tipo de negócio.</small></div>
            <div><span>3</span><strong>Ajuste as regras</strong><small>Cada empresa pode ter limites e formas de atendimento diferentes.</small></div>
        </div>

        <div class="form-grid two agent-rules-main-fields">
            <label class="field"><span>Segmento da empresa</span><select data-agent-architecture-niche>
                <option value="">Escolher depois</option>
                <?php foreach (($businessNiches ?? []) as $niche): ?>
                    <option value="<?= (int) ($niche['id'] ?? 0) ?>" <?= (int) ($agentBlueprintProfile['niche_id'] ?? $company['business_niche_id'] ?? 0) === (int) ($niche['id'] ?? 0) ? 'selected' : '' ?>><?= View::e((string) ($niche['name'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select><small>O segmento filtra os modelos de atendimento disponíveis.</small></label>
            <label class="field"><span>Modelo de atendimento</span><select name="agent_blueprint_id" data-agent-architecture-blueprint>
                <option value="">Nenhum modelo aplicado</option>
                <?php foreach (($agentBlueprints ?? []) as $blueprint): ?>
                    <option value="<?= (int) ($blueprint['id'] ?? 0) ?>" data-niche-id="<?= (int) ($blueprint['niche_id'] ?? 0) ?>" <?= (int) ($agentBlueprintProfile['blueprint_id'] ?? 0) === (int) ($blueprint['id'] ?? 0) ? 'selected' : '' ?>><?= View::e((string) (($blueprint['niche_name'] ?? '') . ' — ' . ($blueprint['name'] ?? ''))) ?></option>
                <?php endforeach; ?>
            </select><small>Ao trocar o modelo, o RS Connect reaplica o padrão do segmento. Depois você pode personalizar os itens abaixo.</small></label>
            <label class="field"><span>Como fazer as perguntas</span><select name="agent_interaction_mode">
                <option value="hybrid" <?= ($agentBlueprintProfile['interaction_mode'] ?? 'hybrid') === 'hybrid' ? 'selected' : '' ?>>Natural com regras — recomendado</option>
                <option value="form" <?= ($agentBlueprintProfile['interaction_mode'] ?? '') === 'form' ? 'selected' : '' ?>>Perguntas fixas configuradas aqui</option>
                <option value="prompt" <?= ($agentBlueprintProfile['interaction_mode'] ?? '') === 'prompt' ? 'selected' : '' ?>>Prompt Studio com as mesmas travas</option>
            </select><small>Em qualquer opção, as regras de segurança continuam valendo e não podem ser ignoradas pela IA.</small></label>
            <div class="readonly-grid compact-readonly-grid agent-rules-status-grid">
                <div><span>Versão do modelo</span><strong><?= View::e((string) ($agentBlueprintProfile['version_label'] ?? '—')) ?></strong></div>
                <div><span>Tem ajustes próprios?</span><strong><?= !empty($agentBlueprintProfile['customized']) ? 'Sim' : 'Ainda não' ?></strong></div>
            </div>
        </div>

        <?php if ($agentCapabilities !== []): ?>
            <div class="agent-rules-section-title"><span>Permissões</span><h3>O que o assistente pode fazer</h3><p>Desligue qualquer ação que você não queira deixar nas mãos do atendimento automático.</p></div>
            <div class="settings-toggle-grid agent-capability-grid">
                <?php foreach ($agentCapabilities as $capabilityKey => $enabled): ?>
                    <label class="switch-card agent-rule-toggle <?= $enabled ? 'is-on' : 'is-off' ?>">
                        <input type="hidden" name="agent_capabilities[<?= View::e((string) $capabilityKey) ?>]" value="0">
                        <input type="checkbox" name="agent_capabilities[<?= View::e((string) $capabilityKey) ?>]" value="1" <?= $enabled ? 'checked' : '' ?>>
                        <span><strong><?= View::e($capabilityLabels[$capabilityKey] ?? $humanizeInternalKey((string) $capabilityKey)) ?></strong><small><?= View::e($capabilityHelp[$capabilityKey] ?? 'Permissão definida pelo modelo de atendimento.') ?></small></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($agentTriageFields !== []): ?>
            <div class="agent-rules-section-title"><span>Coleta de informações</span><h3>O que precisa ser perguntado</h3><p>O assistente aproveita o que a pessoa já informou e pergunta somente o que estiver faltando.</p></div>
            <div class="module-settings-grid agent-collection-grid">
                <?php foreach ($agentTriageFields as $field): ?>
                    <?php $fieldKey = (string) ($field['field_key'] ?? ''); $fieldType = (string) ($field['field_type'] ?? 'text'); ?>
                    <article class="module-setting-card agent-collection-card">
                        <div class="agent-rule-card-content">
                            <div class="agent-rule-card-head">
                                <label class="field"><span>Informação</span><input name="triage_fields[<?= View::e($fieldKey) ?>][label]" value="<?= View::e((string) ($field['label'] ?? $fieldKey)) ?>"></label>
                                <span class="agent-field-type-pill"><?= View::e($fieldTypeLabels[$fieldType] ?? 'Informação') ?></span>
                            </div>
                            <label class="field"><span>Pergunta usada se o Prompt Studio não definir outra</span><textarea name="triage_fields[<?= View::e($fieldKey) ?>][prompt_text]" rows="2"><?= View::e((string) ($field['prompt_text'] ?? '')) ?></textarea></label>
                            <div class="agent-rule-options">
                                <input type="hidden" name="triage_fields[<?= View::e($fieldKey) ?>][active]" value="0"><label><input type="checkbox" name="triage_fields[<?= View::e($fieldKey) ?>][active]" value="1" <?= !empty($field['active']) ? 'checked' : '' ?>> Usar esta informação</label>
                                <input type="hidden" name="triage_fields[<?= View::e($fieldKey) ?>][required_before_schedule]" value="0"><label><input type="checkbox" name="triage_fields[<?= View::e($fieldKey) ?>][required_before_schedule]" value="1" <?= !empty($field['required_before_schedule']) ? 'checked' : '' ?>> Precisa estar preenchida antes de consultar a agenda</label>
                                <input type="hidden" name="triage_fields[<?= View::e($fieldKey) ?>][required_for_completion]" value="0"><label><input type="checkbox" name="triage_fields[<?= View::e($fieldKey) ?>][required_for_completion]" value="1" <?= !empty($field['required_for_completion']) ? 'checked' : '' ?>> Precisa ser coletada antes de encerrar o atendimento</label>
                            </div>
                            <details class="agent-model-technical-details agent-rule-technical">
                                <summary>Detalhes técnicos</summary>
                                <div class="readonly-grid compact-readonly-grid"><div><span>Identificação interna</span><strong><?= View::e($fieldKey) ?></strong></div><div><span>Formato</span><strong><?= View::e($fieldType) ?></strong></div></div>
                            </details>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($agentPolicies !== []): ?>
            <div class="agent-rules-section-title"><span>Limites e segurança</span><h3>Regras que o assistente deve respeitar</h3><p>Essas regras são verificadas pelo sistema antes de liberar agenda, confirmação ou outras ações importantes.</p></div>
            <div class="module-settings-grid agent-policy-grid">
                <?php foreach ($agentPolicies as $policy): ?>
                    <?php
                    $policyKey = (string) ($policy['policy_key'] ?? '');
                    $policyType = (string) ($policy['policy_type'] ?? 'string');
                    $policyValue = $policy['value'] ?? '';
                    $actionKey = (string) ($policy['action_key'] ?? 'block');
                    ?>
                    <article class="module-setting-card agent-policy-card <?= !empty($policy['enabled']) ? 'is-enabled' : 'is-disabled' ?>">
                        <div class="agent-rule-card-content">
                            <div class="agent-policy-card-head">
                                <div><strong><?= View::e($policyLabels[$policyKey] ?? $humanizeInternalKey($policyKey)) ?></strong><small><?= View::e($policyHelp[$policyKey] ?? 'Regra personalizada deste modelo de atendimento.') ?></small></div>
                                <span class="badge <?= !empty($policy['enabled']) ? 'badge-active' : 'badge-pending' ?>"><?= !empty($policy['enabled']) ? 'Ativa' : 'Desligada' ?></span>
                            </div>
                            <div class="form-grid two">
                                <label class="field"><span><?= $policyType === 'number' ? 'Valor definido' : 'Esta regra deve valer?' ?></span>
                                    <?php if ($policyType === 'boolean'): ?>
                                        <select name="agent_policies[<?= View::e($policyKey) ?>][value]"><option value="1" <?= !empty($policyValue) ? 'selected' : '' ?>>Sim</option><option value="0" <?= empty($policyValue) ? 'selected' : '' ?>>Não</option></select>
                                    <?php elseif ($policyType === 'number'): ?>
                                        <input type="number" step="1" name="agent_policies[<?= View::e($policyKey) ?>][value]" value="<?= View::e((string) $policyValue) ?>">
                                    <?php else: ?>
                                        <input name="agent_policies[<?= View::e($policyKey) ?>][value]" value="<?= View::e(is_array($policyValue) ? json_encode($policyValue, JSON_UNESCAPED_UNICODE) : (string) $policyValue) ?>">
                                    <?php endif; ?>
                                </label>
                                <label class="field"><span>O que fazer quando a regra for acionada</span><select name="agent_policies[<?= View::e($policyKey) ?>][action_key]">
                                    <?php if (!array_key_exists($actionKey, $policyActionLabels)): ?><option value="<?= View::e($actionKey) ?>" selected>Regra personalizada</option><?php endif; ?>
                                    <?php foreach ($policyActionLabels as $key => $label): ?><option value="<?= View::e($key) ?>" <?= $actionKey === $key ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?>
                                </select></label>
                            </div>
                            <label class="field"><span>Mensagem que o cliente recebe</span><textarea name="agent_policies[<?= View::e($policyKey) ?>][customer_message]" rows="3"><?= View::e((string) ($policy['customer_message'] ?? '')) ?></textarea></label>
                            <div class="agent-rule-options single-line">
                                <input type="hidden" name="agent_policies[<?= View::e($policyKey) ?>][enabled]" value="0">
                                <label><input type="checkbox" name="agent_policies[<?= View::e($policyKey) ?>][enabled]" value="1" <?= !empty($policy['enabled']) ? 'checked' : '' ?>> Usar esta regra</label>
                            </div>
                            <details class="agent-model-technical-details agent-rule-technical"><summary>Detalhes técnicos</summary><code><?= View::e($policyKey) ?></code></details>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($agentWorkflow !== []): ?>
            <div class="agent-rules-section-title"><span>Ordem do atendimento</span><h3>Passo a passo que o assistente segue</h3><p>Essa ordem vem do modelo de atendimento aplicado à empresa. Você pode reorganizar as etapas; as travas de segurança continuam sendo validadas pelo RS Connect independentemente da posição.</p></div>
            <div class="agent-workflow-editor" data-workflow-list>
                <?php foreach ($agentWorkflow as $index => $step): ?>
                    <?php
                    $workflowKey = (string) ($step['step_key'] ?? '');
                    $workflowType = (string) ($step['step_type'] ?? 'collect');
                    $workflowTypeLabel = [
                        'collect' => 'Coleta',
                        'policy' => 'Validação',
                        'action' => 'Ação',
                        'handoff' => 'Equipe',
                        'complete' => 'Conclusão',
                    ][$workflowType] ?? 'Etapa';
                    ?>
                    <article class="agent-workflow-editor-step" data-workflow-step>
                        <div class="agent-workflow-order">
                            <span class="agent-workflow-number" data-workflow-number><?= $index + 1 ?></span>
                            <div class="agent-workflow-move">
                                <button type="button" class="workflow-move-btn" data-workflow-move="up" aria-label="Mover etapa para cima">↑</button>
                                <button type="button" class="workflow-move-btn" data-workflow-move="down" aria-label="Mover etapa para baixo">↓</button>
                            </div>
                        </div>
                        <div class="agent-workflow-editor-content">
                            <div class="agent-workflow-editor-head">
                                <span class="agent-workflow-type"><?= View::e($workflowTypeLabel) ?></span>
                                <?php if ($workflowType !== 'collect'): ?><span class="agent-workflow-protected">Proteção do sistema</span><?php endif; ?>
                            </div>
                            <label class="field compact-field">
                                <span>Nome da etapa</span>
                                <input name="workflow_steps[<?= View::e($workflowKey) ?>][label]" value="<?= View::e((string) ($step['label'] ?? $workflowKey)) ?>" maxlength="180">
                            </label>
                            <input type="hidden" name="workflow_steps[<?= View::e($workflowKey) ?>][position]" value="<?= (int) ($step['position'] ?? (($index + 1) * 10)) ?>" data-workflow-position>
                            <small><?= $workflowType === 'collect' ? 'A ordem influencia qual informação pendente será pedida primeiro.' : 'Esta etapa pode ser movida, mas a regra técnica continua obrigatória quando aplicável.' ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="message-info agent-safety-info"><strong>Proteção automática</strong><span>A IA pode entender o pedido e sugerir o próximo passo, mas o RS Connect confere as regras antes de executar. Se faltar uma informação obrigatória ou houver uma restrição, a agenda não é liberada.</span></div>
    </section>

    <?php $agentPolicyDecisions = is_array($agentPolicyDecisions ?? null) ? $agentPolicyDecisions : []; ?>
    <section class="settings-block agent-decision-history" id="agent-policy-audit">
        <div class="section-heading compact">
            <div>
                <span class="eyebrow">Histórico de segurança</span>
                <h2>O que o assistente decidiu recentemente</h2>
                <p>Acompanhe por que uma conversa foi liberada, bloqueada, ficou aguardando informação ou precisou da equipe.</p>
            </div>
            <span class="badge"><?= count($agentPolicyDecisions) ?> registro(s)</span>
        </div>
        <?php if ($agentPolicyDecisions === []): ?>
            <div class="message-info"><strong>Ainda não há registros</strong><span>Quando novas conversas passarem pelas regras do assistente, as decisões aparecerão aqui.</span></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table agent-decision-table">
                    <thead><tr><th>Data</th><th>Contato</th><th>Regra verificada</th><th>Resultado</th><th>Motivo</th><th>Conversa</th></tr></thead>
                    <tbody>
                    <?php foreach ($agentPolicyDecisions as $policyDecision): ?>
                        <?php $decision = (string) ($policyDecision['decision'] ?? 'warn'); $reason = (string) ($policyDecision['reason_code'] ?? ''); $pKey = (string) ($policyDecision['policy_key'] ?? ''); ?>
                        <tr>
                            <td><?= View::e((string) ($policyDecision['created_at'] ?? '')) ?></td>
                            <td><strong><?= View::e((string) (($policyDecision['contact_name'] ?? '') ?: 'Contato')) ?></strong><br><small><?= View::e((string) ($policyDecision['contact_phone'] ?? '')) ?></small></td>
                            <td><strong><?= View::e($policyLabels[$pKey] ?? $humanizeInternalKey($pKey)) ?></strong></td>
                            <?php
                            $decisionEvidence = is_array($policyDecision['evidence'] ?? null) ? $policyDecision['evidence'] : [];
                            $decisionEvidenceData = is_array($decisionEvidence['decision_evidence'] ?? null) ? $decisionEvidence['decision_evidence'] : [];
                            $restrictionScope = (string) ($decisionEvidenceData['restriction_scope'] ?? '');
                            $displayDecision = $decisionLabels[$decision] ?? $humanizeInternalKey($decision);
                            if ($restrictionScope === 'calendar' && $decision === 'block') {
                                $displayDecision = 'Agenda não liberada';
                            } elseif ($restrictionScope === 'calendar' && $decision === 'warn') {
                                $displayDecision = 'Regra informada';
                            }
                            ?>
                            <td><span class="badge <?= View::e($decisionClasses[$decision] ?? '') ?>"><?= View::e($displayDecision) ?></span></td>
                            <td><?= View::e($reasonLabels[$reason] ?? ($reason !== '' ? $humanizeInternalKey($reason) : '—')) ?></td>
                            <td><a class="btn btn-secondary btn-sm" href="<?= View::e(Router::url('/conversations?id=' . (int) ($policyDecision['conversation_id'] ?? 0))) ?>">Ver conversa</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <script>
    (function () {
        const niche = document.querySelector('[data-agent-architecture-niche]');
        const blueprint = document.querySelector('[data-agent-architecture-blueprint]');
        if (!niche || !blueprint) return;
        const sync = () => {
            const nicheId = niche.value;
            [...blueprint.options].forEach((option, index) => {
                if (index === 0) return;
                option.hidden = !!nicheId && option.dataset.nicheId !== nicheId;
            });
            const selected = blueprint.selectedOptions[0];
            if (selected && selected.value && selected.hidden) blueprint.value = '';
        };
        niche.addEventListener('change', sync);
        sync();
    })();
    </script>

    <section class="settings-block smart-calendar-admin-control">
        <div class="section-heading compact">
            <div>
                <span class="eyebrow">Agenda inteligente</span>
                <h2>Liberação técnica pela RS Connect</h2>
                <p>Controle se esta empresa poderá escolher a integração com Google Calendar/n8n durante o onboarding. Credenciais e workflows continuam restritos ao Super Admin.</p>
            </div>
            <?php $smartStatus = (string) (($calendarAccessSettings['smart_calendar_status'] ?? 'locked')); ?>
            <span class="badge <?= $smartStatus === 'ready' ? 'badge-active' : ($smartStatus === 'configuring' ? 'badge-warning' : 'badge-pending') ?>"><?= $smartStatus === 'ready' ? 'Liberada' : ($smartStatus === 'configuring' ? 'Em configuração' : 'Não liberada') ?></span>
        </div>
        <div class="form-grid two">
            <label class="field"><span>Situação da Agenda inteligente</span><select name="smart_calendar_status">
                <option value="locked" <?= $smartStatus === 'locked' ? 'selected' : '' ?>>Não liberada</option>
                <option value="configuring" <?= $smartStatus === 'configuring' ? 'selected' : '' ?>>Em configuração pela RS</option>
                <option value="ready" <?= $smartStatus === 'ready' ? 'selected' : '' ?>>Liberada e homologada</option>
            </select><small>Somente “Liberada e homologada” torna essa opção selecionável pelo cliente.</small></label>
            <div class="readonly-grid compact-readonly-grid">
                <div><span>Modo escolhido no onboarding</span><strong><?= View::e(match ((string) ($calendarAccessSettings['calendar_mode'] ?? 'none')) { 'internal' => 'Agenda interna', 'smart' => 'Agenda inteligente', default => 'Sem agenda' }) ?></strong></div>
                <div><span>Última liberação</span><strong><?= View::e((string) ($calendarAccessSettings['smart_calendar_released_at'] ?? 'Não liberada')) ?></strong></div>
            </div>
        </div>
        <div class="message-info"><strong>Responsabilidade da RS</strong><span>Antes de liberar, configure e valide n8n, Google Calendar, retornos automáticos, chaves de segurança, horários disponíveis e manutenção. O cliente não recebe acesso a esses dados técnicos.</span></div>
    </section>


    <section class="settings-block message-governance-settings">
        <input type="hidden" name="message_governance_settings_submitted" value="1">
        <div class="section-heading compact">
            <div>
                <span class="eyebrow">Conversas e privacidade</span>
                <h2>Identificação da equipe e retenção</h2>
                <p>Defina como atendentes humanos aparecem no WhatsApp e por quanto tempo o conteúdo das mensagens permanece armazenado.</p>
            </div>
            <span class="badge <?= !empty($messageGovernanceSettings['whatsapp_human_signature_enabled']) ? 'badge-active' : 'badge-pending' ?>"><?= !empty($messageGovernanceSettings['whatsapp_human_signature_enabled']) ? 'Assinatura ativa' : 'Assinatura desativada' ?></span>
        </div>
        <div class="settings-toggle-grid">
            <label class="switch-card"><input type="hidden" name="whatsapp_human_signature_enabled" value="0"><input type="checkbox" name="whatsapp_human_signature_enabled" value="1" <?= !empty($messageGovernanceSettings['whatsapp_human_signature_enabled']) ? 'checked' : '' ?>><span><strong>Identificar atendente no WhatsApp</strong><small>Mensagens humanas e automáticas exibem o nome do emissor no WhatsApp.</small></span></label>
        </div>
        <div class="form-grid two">
            <label class="field"><span>Formato da assinatura</span><select name="whatsapp_human_signature_format">
                <option value="name" <?= ($messageGovernanceSettings['whatsapp_human_signature_format'] ?? 'name_role') === 'name' ? 'selected' : '' ?>>Nome</option>
                <option value="name_role" <?= ($messageGovernanceSettings['whatsapp_human_signature_format'] ?? 'name_role') === 'name_role' ? 'selected' : '' ?>>Nome + função</option>
                <option value="name_company" <?= ($messageGovernanceSettings['whatsapp_human_signature_format'] ?? 'name_role') === 'name_company' ? 'selected' : '' ?>>Nome + empresa</option>
            </select><small>O nome público e a função são configurados em Equipe e acessos.</small></label>
            <label class="field"><span>Política de retenção</span><select name="message_retention_mode">
                <option value="complete" <?= ($messageGovernanceSettings['message_retention_mode'] ?? 'reduced') === 'complete' ? 'selected' : '' ?>>Completa</option>
                <option value="reduced" <?= ($messageGovernanceSettings['message_retention_mode'] ?? 'reduced') === 'reduced' ? 'selected' : '' ?>>Reduzida</option>
                <option value="ephemeral" <?= ($messageGovernanceSettings['message_retention_mode'] ?? 'reduced') === 'ephemeral' ? 'selected' : '' ?>>Efêmera</option>
            </select><small>Metadados de auditoria permanecem; o conteúdo textual é removido conforme a regra.</small></label>
            <label class="field"><span>Conteúdo no modo reduzido</span><div class="input-with-suffix"><input type="number" min="1" max="3650" name="message_retention_days" value="<?= (int) ($messageGovernanceSettings['message_retention_days'] ?? 90) ?>"><span>dias</span></div></label>
            <label class="field"><span>Dados técnicos</span><div class="input-with-suffix"><input type="number" min="1" max="3650" name="message_raw_payload_days" value="<?= (int) ($messageGovernanceSettings['message_raw_payload_days'] ?? 30) ?>"><span>dias</span></div></label>
            <label class="field"><span>Conteúdo no modo efêmero</span><div class="input-with-suffix"><input type="number" min="1" max="720" name="message_ephemeral_hours" value="<?= (int) ($messageGovernanceSettings['message_ephemeral_hours'] ?? 24) ?>"><span>horas</span></div></label>
            <div class="readonly-grid compact-readonly-grid"><div><span>Última limpeza</span><strong><?= View::e((string) ($messageGovernanceSettings['message_retention_last_run_at'] ?? 'Ainda não executada')) ?></strong></div></div>
        </div>
        <div class="message-info"><strong>Modo efêmero</strong><span>Preserva mensagens enquanto a conversa está ativa. Depois da janela configurada, remove o conteúdo e os dados técnicos, mantendo data, remetente, status e métricas.</span></div>
    </section>

    <section class="settings-block professional-assignment-settings">
        <input type="hidden" name="professional_assignment_settings_submitted" value="1">
        <div class="section-heading compact">
            <div>
                <span class="eyebrow">Equipe e responsabilidade</span>
                <h2>Atendimento por profissional</h2>
                <p>Ative somente para empresas que trabalham com carteira individual, como barbearias, clínicas e equipes comerciais.</p>
            </div>
            <span class="badge <?= !empty($professionalAssignmentSettings['enabled']) ? 'badge-active' : 'badge-pending' ?>"><?= !empty($professionalAssignmentSettings['enabled']) ? 'Recurso ativo' : 'Desativado' ?></span>
        </div>
        <div class="settings-toggle-grid">
            <label class="switch-card"><input type="checkbox" name="professional_assignment_enabled" value="1" <?= !empty($professionalAssignmentSettings['enabled']) ? 'checked' : '' ?>><span><strong>Usar atendimento por profissional</strong><small>Permite assumir, transferir e liberar conversas entre os usuários da empresa.</small></span></label>
            <label class="switch-card"><input type="checkbox" name="professional_lock_enabled" value="1" <?= !array_key_exists('lock_enabled', $professionalAssignmentSettings) || !empty($professionalAssignmentSettings['lock_enabled']) ? 'checked' : '' ?>><span><strong>Bloquear interferência durante a conversa aberta</strong><small>Somente o responsável atual pode responder e alterar o atendimento. Administradores podem transferir.</small></span></label>
            <label class="switch-card"><input type="checkbox" name="professional_auto_assign_enabled" value="1" <?= !empty($professionalAssignmentSettings['auto_assign_enabled']) ? 'checked' : '' ?>><span><strong>Atribuir automaticamente ao profissional preferido</strong><small>Opcional e desativado por padrão. Quando desligado, a conversa fica disponível até alguém assumir manualmente.</small></span></label>
        </div>
        <div class="message-info"><strong>Atribuição automática é independente</strong><span>Você pode usar o vínculo Cliente → Profissional apenas como referência, sem entregar automaticamente a conversa. O bloqueio começa somente quando alguém assume ou é atribuído manualmente.</span></div>
    </section>

    <section class="settings-block">
        <div class="section-heading compact">
            <div>
                <span class="eyebrow">Pré-agendamento</span>
                <h2>Agenda com aprovação humana</h2>
                <p>Quando ativo, a IA pode registrar a preferência de dia/horário do lead e enviar para aprovação antes de confirmar.</p>
            </div>
            <span class="badge <?= !empty($preScheduleSettings['enabled']) ? 'badge-active' : 'badge-pending' ?>"><?= !empty($preScheduleSettings['enabled']) ? 'Ativo' : 'Desativado' ?></span>
        </div>
        <div class="settings-toggle-grid">
            <label class="switch-card">
                <input type="checkbox" name="pre_schedule_enabled" value="1" <?= !empty($preScheduleSettings['enabled']) ? 'checked' : '' ?>>
                <span><strong>Usar pré-agendamento</strong><small>Cria solicitações na agenda a partir da intenção detectada na conversa.</small></span>
            </label>
            <label class="switch-card">
                <input type="checkbox" name="pre_schedule_ai_can_suggest_slots" value="1" <?= !empty($preScheduleSettings['ai_can_suggest_slots']) ? 'checked' : '' ?>>
                <span><strong>Sugerir horários alternativos</strong><small>Quando o horário pedido estiver ocupado, apresenta outras opções reais da agenda.</small></span>
            </label>
            <label class="switch-card">
                <input type="checkbox" name="pre_schedule_send_approval_message" value="1" <?= !empty($preScheduleSettings['send_approval_message']) ? 'checked' : '' ?>>
                <span><strong>Enviar mensagem ao aprovar</strong><small>Ao clicar em Aprovar/Confirmar na agenda, o RS Connect envia a confirmação pelo WhatsApp.</small></span>
            </label>
        </div>
        <div class="form-grid two">
            <label class="field"><span>Depois de encontrar um horário realmente livre</span><select name="pre_schedule_confirmation_mode">
                <?php $confirmationMode = !empty($preScheduleSettings['require_human_approval']) ? 'human' : (!empty($preScheduleSettings['ai_can_confirm']) ? 'automatic' : 'pre_schedule'); ?>
                <option value="human" <?= $confirmationMode === 'human' ? 'selected' : '' ?>>Pré-agendar e aguardar aprovação da equipe</option>
                <option value="automatic" <?= $confirmationMode === 'automatic' ? 'selected' : '' ?>>Perguntar ao cliente e confirmar automaticamente</option>
                <option value="pre_schedule" <?= $confirmationMode === 'pre_schedule' ? 'selected' : '' ?>>Somente pré-agendar; confirmação manual posterior</option>
            </select><small>A preferência do lead nunca vira compromisso sozinha. O modo acima só é aplicado depois que o RS Connect validar e reservar um slot real.</small></label>
            <label class="field"><span>Duração padrão</span><input type="number" min="15" max="240" name="pre_schedule_default_duration_minutes" value="<?= (int) ($preScheduleSettings['default_duration_minutes'] ?? 50) ?>"></label>
            <label class="field"><span>Forma de conduzir as perguntas da agenda</span><select name="pre_schedule_message_mode" data-pre-schedule-message-mode>
                <option value="prompt" <?= ($preScheduleSettings['message_mode'] ?? 'form') === 'prompt' ? 'selected' : '' ?>>Prompt Studio — conversa natural</option>
                <option value="form" <?= ($preScheduleSettings['message_mode'] ?? 'form') === 'form' ? 'selected' : '' ?>>Formulário — textos definidos abaixo</option>
            </select><small>No modo Prompt Studio, a IA pergunta apenas os dados que faltam. Mensagens técnicas da agenda continuam usando os textos abaixo como fonte segura e fallback.</small></label>
        </div>
        <div class="message-info" data-pre-schedule-prompt-info><strong>Prompt Studio</strong><span>Configure o tom e as regras de agenda em Assistentes → Prompt Studio. O RS Connect continua sendo a fonte de verdade para disponibilidade, pré-reserva e confirmação.</span></div>
        <div data-pre-schedule-form-messages>
        <div class="form-grid two">
            <label class="field"><span>Mensagem inicial quando faltam dia/horário e modalidade</span><textarea name="pre_schedule_initial_collect_message" rows="3"><?= View::e($preScheduleSettings['initial_collect_message'] ?? 'Claro! Qual o melhor dia e horário para você? E prefere atendimento online ou presencial?') ?></textarea></label>
            <label class="field"><span>Mensagem enquanto consulta a disponibilidade</span><textarea name="pre_schedule_default_message" rows="3"><?= View::e($preScheduleSettings['default_message'] ?? '') ?></textarea><small>Use {{dia_preferido}} e {{horario_preferido}}. Ex.: “Perfeito. Vou verificar a disponibilidade para sexta às 10:00.”</small></label>
            <label class="field"><span>Mensagem para coletar dia/horário</span><textarea name="pre_schedule_collect_message" rows="3"><?= View::e($preScheduleSettings['collect_message'] ?? '') ?></textarea></label>
            <label class="field"><span>Mensagem para escolher modalidade</span><textarea name="pre_schedule_modality_message" rows="3"><?= View::e($preScheduleSettings['modality_message'] ?? 'Antes de consultar os horários, você prefere atendimento online ou presencial?') ?></textarea><small>Obrigatória antes da consulta de disponibilidade. A modalidade é usada para filtrar corretamente os horários da agenda selecionada.</small></label>
            <label class="field"><span>Mensagem após aprovação</span><textarea name="pre_schedule_approved_message" rows="3"><?= View::e($preScheduleSettings['approved_message'] ?? '') ?></textarea></label>
            <label class="field"><span>Mensagem ao recusar horário</span><textarea name="pre_schedule_rejected_message" rows="3"><?= View::e($preScheduleSettings['rejected_message'] ?? '') ?></textarea></label>
            <label class="field"><span>Mensagem ao remarcar</span><textarea name="pre_schedule_reschedule_message" rows="3"><?= View::e($preScheduleSettings['reschedule_message'] ?? '') ?></textarea></label>
            <label class="field"><span>Mensagem com horários alternativos</span><textarea name="pre_schedule_availability_options_message" rows="5"><?= View::e($preScheduleSettings['availability_options_message'] ?? '') ?></textarea><small>Use {{opcoes}} para inserir a lista real retornada pela agenda selecionada.</small></label>
            <label class="field"><span>Mensagem após o cliente escolher</span><textarea name="pre_schedule_slot_selected_message" rows="4"><?= View::e($preScheduleSettings['slot_selected_message'] ?? '') ?></textarea><small>Disponível: {{data}}, {{hora}}, {{inicio}}, {{nome}} e {{modalidade}}.</small></label>
            <label class="field"><span>Mensagem quando não houver horários</span><textarea name="pre_schedule_no_availability_message" rows="3"><?= View::e($preScheduleSettings['no_availability_message'] ?? '') ?></textarea></label>
            <label class="field"><span>Mensagem quando a escolha não for identificada</span><textarea name="pre_schedule_invalid_slot_message" rows="3"><?= View::e($preScheduleSettings['invalid_slot_message'] ?? '') ?></textarea></label>
        </div>
        <p class="form-help">Você pode usar variáveis nas mensagens: <code>{{nome}}</code>, <code>{{data}}</code>, <code>{{hora}}</code>, <code>{{local}}</code>, <code>{{modalidade}}</code>, <code>{{dia_preferido}}</code> e <code>{{horario_preferido}}</code>.</p>
        </div>
    </section>

    <section class="settings-block admin-client-menu-settings" id="company-module-settings">
        <input type="hidden" name="module_settings_submitted" value="1">
        <div class="section-heading compact">
            <div>
                <span class="eyebrow">Menus do cliente</span>
                <h2>Escolha o que a empresa verá e poderá acessar</h2>
                <p><strong>Menu</strong> define se o atalho aparece na navegação da empresa. <strong>Acesso</strong> define se a rota pode ser aberta. O usuário ainda precisa ter a permissão do seu perfil para visualizar o item.</p>
            </div>
        </div>
        <div class="module-settings-grid">
            <?php foreach (($availableModules ?? []) as $moduleKey => $module): ?>
                <?php
                $isVisible = (bool) (($moduleSettings[$moduleKey]['is_visible'] ?? null) ?? ($module['default_visible'] ?? true));
                $isEnabled = (bool) (($moduleSettings[$moduleKey]['is_enabled'] ?? null) ?? ($module['default_enabled'] ?? true));
                $locked = in_array($moduleKey, ['dashboard', 'company_settings'], true);
                ?>
                <article class="module-setting-card <?= $isEnabled ? 'is-enabled' : 'is-disabled' ?>">
                    <div>
                        <strong><?= View::e($module['label']) ?></strong>
                        <small><?= View::e($module['description']) ?></small>
                    </div>
                    <div class="module-setting-actions">
                        <label><input type="checkbox" name="module_visible[]" value="<?= View::e($moduleKey) ?>" <?= $isVisible ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>> Menu</label>
                        <label><input type="checkbox" name="module_enabled[]" value="<?= View::e($moduleKey) ?>" <?= $isEnabled ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>> Acesso</label>
                        <?php if ($locked): ?>
                            <input type="hidden" name="module_visible[]" value="<?= View::e($moduleKey) ?>">
                            <input type="hidden" name="module_enabled[]" value="<?= View::e($moduleKey) ?>">
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if (Auth::can('company.manage')): ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar alterações</button></div>
    <?php endif; ?>
</form>

<section class="card retention-manual-card">
    <div class="section-heading compact">
        <div><span class="eyebrow">Execução manual</span><h2>Aplicar retenção agora</h2><p>Use para homologar a política desta empresa. A automação diária deve ser feita pelo template de retenção no n8n.</p></div>
        <form method="post" action="<?= View::e(Router::url('/messages/retention/run')) ?>" data-confirm="Executar agora a política de retenção desta empresa? Conteúdos vencidos serão removidos.">
            <?= Csrf::input() ?>
            <input type="hidden" name="tenant_id" value="<?= (int) $company['id'] ?>">
            <button class="btn btn-outline" type="submit">Executar retenção</button>
        </form>
    </div>
</section>

<?php else: ?>
<?php $accountSection = 'company'; require __DIR__ . '/_account_tabs.php'; ?>
<form class="client-company-profile" method="post" action="<?= View::e(Router::url('/company-settings')) ?>">
    <?= Csrf::input() ?>
    <input type="hidden" name="tenant_id" value="<?= (int) $company['id'] ?>">

    <section class="client-company-hero">
        <div class="client-company-hero-copy">
            <span class="eyebrow">Perfil da empresa</span>
            <div class="client-company-title-row">
                <h1><?= View::e($company['name']) ?></h1>
                <span class="badge badge-<?= View::e($company['status']) ?>"><?= View::e($statusLabel) ?></span>
            </div>
            <p>Mantenha estas informações atualizadas. Elas identificam sua empresa e ajudam os assistentes a responder com mais contexto.</p>
            <div class="client-profile-progress" aria-label="Perfil <?= $profilePercent ?>% preenchido">
                <span><i style="width: <?= $profilePercent ?>%"></i></span>
                <small><?= $profilePercent ?>% do perfil preenchido</small>
            </div>
        </div>
        <?php if ($canManageCompany): ?><button class="btn btn-primary client-company-save-top" type="submit">Salvar alterações</button><?php endif; ?>
    </section>

    <div class="client-company-grid">
        <section class="card client-profile-card">
            <div class="client-profile-card-heading">
                <span class="client-profile-icon" aria-hidden="true">01</span>
                <div><span class="eyebrow">Identificação</span><h2>Informações principais</h2><p>Dados usados para identificar sua empresa no sistema.</p></div>
            </div>
            <label class="field company-display-name-field">
                <span>Nome de exibição</span>
                <input name="name" value="<?= View::e($company['name']) ?>" required>
                <small class="field-hint">Nome usado nas telas, relatórios e comunicações do RS Connect.</small>
            </label>

            <div class="master-data-panel" aria-label="Dados cadastrais protegidos">
                <div class="master-data-panel-head">
                    <div>
                        <span class="eyebrow">Cadastro RS</span>
                        <strong>Dados cadastrais</strong>
                        <small>Informações oficiais vinculadas à sua empresa.</small>
                    </div>
                    <span class="master-data-status">Somente leitura</span>
                </div>
                <div class="master-data-summary">
                    <div class="master-data-item">
                        <span>Razão social</span>
                        <strong><?= View::e($company['legal_name'] ?? 'Não informado') ?></strong>
                    </div>
                    <div class="master-data-item">
                        <span>CNPJ ou CPF</span>
                        <strong><?= View::e($company['document'] ?? 'Não informado') ?></strong>
                    </div>
                    <div class="master-data-item">
                        <span>Segmento</span>
                        <strong><?= View::e($company['segment'] ?? 'Não informado') ?></strong>
                    </div>
                </div>
                <p class="master-data-help">Para corrigir uma dessas informações, solicite a alteração à equipe RS.</p>
            </div>
        </section>

        <section class="card client-profile-card">
            <div class="client-profile-card-heading">
                <span class="client-profile-icon" aria-hidden="true">02</span>
                <div><span class="eyebrow">Contato</span><h2>Como encontrar sua empresa</h2><p>Informações comerciais usadas pela equipe e pelos assistentes.</p></div>
            </div>
            <div class="form-grid two">
                <label class="field"><span>E-mail comercial</span><input type="email" name="email" value="<?= View::e($company['email'] ?? '') ?>" placeholder="contato@empresa.com.br"></label>
                <label class="field"><span>Telefone</span><input name="phone" value="<?= View::e($company['phone'] ?? '') ?>" placeholder="(00) 0000-0000"></label>
                <label class="field"><span>WhatsApp comercial</span><input name="commercial_whatsapp" value="<?= View::e($company['commercial_whatsapp'] ?? '') ?>" placeholder="(00) 00000-0000"></label>
                <label class="field"><span>Instagram</span><input name="instagram" value="<?= View::e($company['instagram'] ?? '') ?>" placeholder="@suaempresa ou link do perfil"></label>
            </div>
            <label class="field"><span>Site</span><input type="url" name="website" value="<?= View::e($company['website'] ?? '') ?>" placeholder="https://empresa.com.br"></label>
        </section>

        <section class="card client-profile-card client-profile-card-wide">
            <div class="client-profile-card-heading">
                <span class="client-profile-icon" aria-hidden="true">03</span>
                <div><span class="eyebrow">Localização</span><h2>Endereço da empresa</h2><p>Preencha quando o atendimento ou serviço depender de localização.</p></div>
            </div>
            <div class="client-address-grid" data-company-address>
                <label class="field client-address-cep">
                    <span>CEP</span>
                    <div class="postal-code-control">
                        <input name="postal_code" value="<?= View::e($company['postal_code'] ?? '') ?>" placeholder="00000-000" inputmode="numeric" maxlength="9" autocomplete="postal-code" data-cep-input>
                        <span class="cep-lookup-indicator" aria-hidden="true"></span>
                    </div>
                    <small class="field-hint cep-lookup-status" data-cep-status>Digite o CEP para preencher o endereço automaticamente.</small>
                </label>
                <label class="field client-address-line"><span>Rua ou avenida</span><input name="address_line" value="<?= View::e($company['address_line'] ?? '') ?>" autocomplete="address-line1"></label>
                <label class="field client-address-number"><span>Número</span><input name="address_number" value="<?= View::e($company['address_number'] ?? '') ?>" autocomplete="address-line2"></label>
                <label class="field client-address-complement"><span>Complemento</span><input name="address_complement" value="<?= View::e($company['address_complement'] ?? '') ?>"></label>
                <label class="field client-address-district"><span>Bairro</span><input name="district" value="<?= View::e($company['district'] ?? '') ?>"></label>
                <label class="field client-address-city"><span>Cidade</span><input name="city" value="<?= View::e($company['city'] ?? '') ?>" autocomplete="address-level2"></label>
                <label class="field client-address-state"><span>Estado</span><input name="state" value="<?= View::e($company['state'] ?? '') ?>" placeholder="Ex.: MG" maxlength="2" autocomplete="address-level1"></label>
            </div>
        </section>

        <section class="card client-profile-card client-profile-card-wide client-ai-profile-card">
            <div class="client-profile-card-heading">
                <span class="client-profile-icon" aria-hidden="true">04</span>
                <div><span class="eyebrow">Atendimento</span><h2>Informações usadas pelos assistentes</h2><p>Quanto mais claras forem estas informações, mais contextualizadas serão as respostas.</p></div>
            </div>
            <div class="form-grid two client-ai-profile-grid">
                <label class="field"><span>Sobre a empresa</span><textarea name="company_about" rows="6" placeholder="Conte de forma simples quem é a empresa, há quanto tempo atua e quem atende."><?= View::e($company['company_about'] ?? '') ?></textarea></label>
                <label class="field"><span>Principais serviços ou produtos</span><textarea name="company_services" rows="6" placeholder="Liste os serviços, produtos, especialidades ou soluções oferecidas."><?= View::e($company['company_services'] ?? '') ?></textarea></label>
                <label class="field"><span>Diferenciais</span><textarea name="company_differentials" rows="5" placeholder="Ex.: atendimento personalizado, entrega rápida, equipe especializada."><?= View::e($company['company_differentials'] ?? '') ?></textarea></label>
                <label class="field"><span>Horário de atendimento</span><textarea name="company_business_hours" rows="5" placeholder="Ex.: segunda a sexta, das 8h às 18h; sábado, das 8h às 12h."><?= View::e($company['company_business_hours'] ?? '') ?></textarea></label>
            </div>
            <label class="field"><span>Observações importantes</span><textarea name="company_notes" rows="4" placeholder="Políticas, limitações, links, instruções ou informações que precisam ser consideradas no atendimento."><?= View::e($company['company_notes'] ?? '') ?></textarea></label>
            <div class="client-ai-profile-note"><strong>Como isso ajuda?</strong><span>Ao criar um novo assistente, estas informações serão usadas como base inicial e poderão ser revisadas antes da ativação.</span></div>
        </section>
    </div>


    <section class="card client-settings-card message-governance-settings">
        <input type="hidden" name="message_governance_settings_submitted" value="1">
        <div class="section-heading compact">
            <div>
                <span class="eyebrow">Conversas e privacidade</span>
                <h2>Identificação da equipe e retenção</h2>
                <p>Defina como atendentes humanos aparecem no WhatsApp e por quanto tempo o conteúdo das mensagens permanece armazenado.</p>
            </div>
            <span class="badge <?= !empty($messageGovernanceSettings['whatsapp_human_signature_enabled']) ? 'badge-active' : 'badge-pending' ?>"><?= !empty($messageGovernanceSettings['whatsapp_human_signature_enabled']) ? 'Assinatura ativa' : 'Assinatura desativada' ?></span>
        </div>
        <div class="settings-toggle-grid">
            <label class="switch-card"><input type="hidden" name="whatsapp_human_signature_enabled" value="0"><input type="checkbox" name="whatsapp_human_signature_enabled" value="1" <?= !empty($messageGovernanceSettings['whatsapp_human_signature_enabled']) ? 'checked' : '' ?>><span><strong>Identificar atendente no WhatsApp</strong><small>Mensagens humanas e automáticas exibem o nome do emissor no WhatsApp.</small></span></label>
        </div>
        <div class="form-grid two">
            <label class="field"><span>Formato da assinatura</span><select name="whatsapp_human_signature_format">
                <option value="name" <?= ($messageGovernanceSettings['whatsapp_human_signature_format'] ?? 'name_role') === 'name' ? 'selected' : '' ?>>Nome</option>
                <option value="name_role" <?= ($messageGovernanceSettings['whatsapp_human_signature_format'] ?? 'name_role') === 'name_role' ? 'selected' : '' ?>>Nome + função</option>
                <option value="name_company" <?= ($messageGovernanceSettings['whatsapp_human_signature_format'] ?? 'name_role') === 'name_company' ? 'selected' : '' ?>>Nome + empresa</option>
            </select><small>O nome público e a função são configurados em Equipe e acessos.</small></label>
            <label class="field"><span>Política de retenção</span><select name="message_retention_mode">
                <option value="complete" <?= ($messageGovernanceSettings['message_retention_mode'] ?? 'reduced') === 'complete' ? 'selected' : '' ?>>Completa</option>
                <option value="reduced" <?= ($messageGovernanceSettings['message_retention_mode'] ?? 'reduced') === 'reduced' ? 'selected' : '' ?>>Reduzida</option>
                <option value="ephemeral" <?= ($messageGovernanceSettings['message_retention_mode'] ?? 'reduced') === 'ephemeral' ? 'selected' : '' ?>>Efêmera</option>
            </select><small>Metadados de auditoria permanecem; o conteúdo textual é removido conforme a regra.</small></label>
            <label class="field"><span>Conteúdo no modo reduzido</span><div class="input-with-suffix"><input type="number" min="1" max="3650" name="message_retention_days" value="<?= (int) ($messageGovernanceSettings['message_retention_days'] ?? 90) ?>"><span>dias</span></div></label>
            <label class="field"><span>Dados técnicos</span><div class="input-with-suffix"><input type="number" min="1" max="3650" name="message_raw_payload_days" value="<?= (int) ($messageGovernanceSettings['message_raw_payload_days'] ?? 30) ?>"><span>dias</span></div></label>
            <label class="field"><span>Conteúdo no modo efêmero</span><div class="input-with-suffix"><input type="number" min="1" max="720" name="message_ephemeral_hours" value="<?= (int) ($messageGovernanceSettings['message_ephemeral_hours'] ?? 24) ?>"><span>horas</span></div></label>
            <div class="readonly-grid compact-readonly-grid"><div><span>Última limpeza</span><strong><?= View::e((string) ($messageGovernanceSettings['message_retention_last_run_at'] ?? 'Ainda não executada')) ?></strong></div></div>
        </div>
        <div class="message-info"><strong>Modo efêmero</strong><span>Preserva mensagens enquanto a conversa está ativa. Depois da janela configurada, remove o conteúdo e os dados técnicos, mantendo data, remetente, status e métricas.</span></div>
    </section>

    <section class="card client-settings-card queue-optional-settings" id="queue-operation-settings">
        <input type="hidden" name="queue_settings_submitted" value="1">
        <div class="section-heading compact">
            <div>
                <span class="eyebrow">Organização da equipe</span>
                <h2>Fila e setores</h2>
                <p>Use somente quando sua operação precisar distribuir conversas por áreas como Comercial, Recepção, Suporte ou Financeiro.</p>
            </div>
            <span class="badge <?= $queueEnabled ? 'badge-active' : 'badge-pending' ?>"><?= $queueEnabled ? 'Em uso' : 'Opcional' ?></span>
        </div>
        <div class="settings-toggle-grid">
            <label class="switch-card queue-operation-toggle">
                <input type="checkbox" name="queue_enabled" value="1" <?= $queueEnabled ? 'checked' : '' ?>>
                <span>
                    <strong>Usar Fila e setores nesta empresa</strong>
                    <small>Quando ativado, libera o menu da fila, transferência por setor e regras da equipe. Quando desligado, o atendimento continua direto por usuário e pela IA.</small>
                </span>
            </label>
        </div>
        <div class="queue-operation-explainer">
            <div><span>Desativado</span><strong>Fluxo simples</strong><small>Conversa → IA ou atendente, sem exigir setor.</small></div>
            <div><span>Ativado</span><strong>Fluxo por equipe</strong><small>Conversa → setor → profissional responsável.</small></div>
        </div>
        <?php if ($queueEnabled && Auth::can('queue.view')): ?>
            <div class="form-actions queue-settings-actions"><a class="btn btn-outline" href="<?= View::e(Router::url('/queue')) ?>">Abrir Fila e setores</a></div>
        <?php endif; ?>
    </section>

    <section class="card client-settings-card professional-assignment-settings">
        <input type="hidden" name="professional_assignment_settings_submitted" value="1">
        <div class="section-heading compact">
            <div>
                <span class="eyebrow">Equipe e responsabilidade</span>
                <h2>Atendimento por profissional</h2>
                <p>Use quando cada cliente costuma ser atendido por uma pessoa específica da equipe.</p>
            </div>
            <span class="badge <?= !empty($professionalAssignmentSettings['enabled']) ? 'badge-active' : 'badge-pending' ?>"><?= !empty($professionalAssignmentSettings['enabled']) ? 'Recurso ativo' : 'Desativado' ?></span>
        </div>
        <div class="settings-toggle-grid">
            <label class="switch-card"><input type="checkbox" name="professional_assignment_enabled" value="1" <?= !empty($professionalAssignmentSettings['enabled']) ? 'checked' : '' ?>><span><strong>Usar atendimento por profissional</strong><small>Permite assumir, transferir e liberar conversas entre os usuários da empresa.</small></span></label>
            <label class="switch-card"><input type="checkbox" name="professional_lock_enabled" value="1" <?= !array_key_exists('lock_enabled', $professionalAssignmentSettings) || !empty($professionalAssignmentSettings['lock_enabled']) ? 'checked' : '' ?>><span><strong>Bloquear interferência durante a conversa aberta</strong><small>Somente o responsável atual pode responder e alterar o atendimento.</small></span></label>
            <label class="switch-card"><input type="checkbox" name="professional_auto_assign_enabled" value="1" <?= !empty($professionalAssignmentSettings['auto_assign_enabled']) ? 'checked' : '' ?>><span><strong>Atribuir automaticamente ao profissional preferido</strong><small>Opcional. Deixe desligado para a equipe assumir as conversas manualmente.</small></span></label>
        </div>
        <div class="message-info"><strong>Sem atribuição automática</strong><span>O profissional preferido continuará aparecendo no cadastro do cliente, mas a conversa ficará livre até alguém assumir.</span></div>
    </section>

    <details class="card client-settings-accordion">
        <summary><span><span class="eyebrow">Agenda</span><strong>Pré-agendamento e mensagens</strong><small>Regras para registrar, aprovar e comunicar horários.</small></span><span class="drawer-chevron"></span></summary>
        <div class="client-settings-accordion-body">
            <div class="settings-toggle-grid">
                <label class="switch-card"><input type="checkbox" name="pre_schedule_enabled" value="1" <?= !empty($preScheduleSettings['enabled']) ? 'checked' : '' ?>><span><strong>Usar pré-agendamento</strong><small>Registra preferências de dia e horário durante a conversa.</small></span></label>
                <label class="switch-card"><input type="checkbox" name="pre_schedule_ai_can_suggest_slots" value="1" <?= !empty($preScheduleSettings['ai_can_suggest_slots']) ? 'checked' : '' ?>><span><strong>Sugerir horários alternativos</strong><small>Quando a preferência estiver indisponível, apresenta outras opções reais da agenda.</small></span></label>
                <label class="switch-card"><input type="checkbox" name="pre_schedule_send_approval_message" value="1" <?= !empty($preScheduleSettings['send_approval_message']) ? 'checked' : '' ?>><span><strong>Enviar confirmação pelo WhatsApp</strong><small>Envia a mensagem quando a equipe aprovar o horário.</small></span></label>
            </div>
            <div class="form-grid two">
                <label class="field"><span>Depois de encontrar um horário realmente livre</span><select name="pre_schedule_confirmation_mode">
                    <?php $confirmationMode = !empty($preScheduleSettings['require_human_approval']) ? 'human' : (!empty($preScheduleSettings['ai_can_confirm']) ? 'automatic' : 'pre_schedule'); ?>
                    <option value="human" <?= $confirmationMode === 'human' ? 'selected' : '' ?>>Pré-agendar e aguardar aprovação da equipe</option>
                    <option value="automatic" <?= $confirmationMode === 'automatic' ? 'selected' : '' ?>>Perguntar ao cliente e confirmar automaticamente</option>
                    <option value="pre_schedule" <?= $confirmationMode === 'pre_schedule' ? 'selected' : '' ?>>Somente pré-agendar; confirmação manual posterior</option>
                </select><small>A escolha só entra em ação após validar disponibilidade real.</small></label>
                <label class="field"><span>Duração padrão em minutos</span><input type="number" min="15" max="240" name="pre_schedule_default_duration_minutes" value="<?= (int) ($preScheduleSettings['default_duration_minutes'] ?? 50) ?>"></label>
                <label class="field"><span>Forma de conduzir as perguntas da agenda</span><select name="pre_schedule_message_mode" data-pre-schedule-message-mode>
                    <option value="prompt" <?= ($preScheduleSettings['message_mode'] ?? 'form') === 'prompt' ? 'selected' : '' ?>>Prompt Studio — conversa natural</option>
                    <option value="form" <?= ($preScheduleSettings['message_mode'] ?? 'form') === 'form' ? 'selected' : '' ?>>Formulário — textos configurados</option>
                </select><small>Prompt Studio usa as regras do assistente para perguntar o que ainda falta. As mensagens técnicas permanecem configuráveis e servem de fallback.</small></label>
            </div>
            <div class="message-info" data-pre-schedule-prompt-info><strong>Prompt Studio</strong><span>Edite as regras de agenda em Assistentes → Prompt Studio. A IA cuida da linguagem; disponibilidade e confirmação continuam validadas pelo RS Connect.</span></div>
            <div data-pre-schedule-form-messages>
            <div class="form-grid two">
                <label class="field"><span>Mensagem inicial quando faltam dia/horário e modalidade</span><textarea name="pre_schedule_initial_collect_message" rows="3"><?= View::e($preScheduleSettings['initial_collect_message'] ?? 'Claro! Qual o melhor dia e horário para você? E prefere atendimento online ou presencial?') ?></textarea></label>
                <label class="field"><span>Mensagem enquanto consulta a disponibilidade</span><textarea name="pre_schedule_default_message" rows="3"><?= View::e($preScheduleSettings['default_message'] ?? '') ?></textarea><small>Use {{dia_preferido}} e {{horario_preferido}}.</small></label>
                <label class="field"><span>Mensagem para pedir dia e horário</span><textarea name="pre_schedule_collect_message" rows="3"><?= View::e($preScheduleSettings['collect_message'] ?? '') ?></textarea></label>
                <label class="field"><span>Mensagem para escolher modalidade</span><textarea name="pre_schedule_modality_message" rows="3"><?= View::e($preScheduleSettings['modality_message'] ?? 'Antes de consultar os horários, você prefere atendimento online ou presencial?') ?></textarea><small>Antes de consultar a agenda, o cliente precisa definir Online ou Presencial.</small></label>
                <label class="field"><span>Mensagem após aprovação</span><textarea name="pre_schedule_approved_message" rows="3"><?= View::e($preScheduleSettings['approved_message'] ?? '') ?></textarea></label>
                <label class="field"><span>Mensagem quando o horário não for aceito</span><textarea name="pre_schedule_rejected_message" rows="3"><?= View::e($preScheduleSettings['rejected_message'] ?? '') ?></textarea></label>
                <label class="field"><span>Mensagem para remarcar</span><textarea name="pre_schedule_reschedule_message" rows="3"><?= View::e($preScheduleSettings['reschedule_message'] ?? '') ?></textarea></label>
                <label class="field"><span>Mensagem com horários alternativos</span><textarea name="pre_schedule_availability_options_message" rows="5"><?= View::e($preScheduleSettings['availability_options_message'] ?? '') ?></textarea><small>Use {{opcoes}} para inserir a lista real retornada pela agenda selecionada.</small></label>
                <label class="field"><span>Mensagem após o cliente escolher</span><textarea name="pre_schedule_slot_selected_message" rows="4"><?= View::e($preScheduleSettings['slot_selected_message'] ?? '') ?></textarea><small>Disponível: {{data}}, {{hora}}, {{inicio}}, {{nome}} e {{modalidade}}.</small></label>
                <label class="field"><span>Mensagem quando não houver horários</span><textarea name="pre_schedule_no_availability_message" rows="3"><?= View::e($preScheduleSettings['no_availability_message'] ?? '') ?></textarea></label>
                <label class="field"><span>Mensagem quando a escolha não for identificada</span><textarea name="pre_schedule_invalid_slot_message" rows="3"><?= View::e($preScheduleSettings['invalid_slot_message'] ?? '') ?></textarea></label>
            </div>
            </div>
        </div>
    </details>

    <details class="card client-settings-accordion client-menu-preferences" id="my-menu-settings">
        <summary><span><span class="eyebrow">Meu menu</span><strong>Organizar módulos visíveis</strong><small>Escolha quais atalhos sua equipe verá na navegação.</small></span><span class="drawer-chevron"></span></summary>
        <div class="client-settings-accordion-body">
            <input type="hidden" name="module_settings_submitted" value="1">
            <div class="client-menu-preferences-note">
                <strong>Isso altera apenas a organização do menu.</strong>
                <span>As permissões e os módulos contratados continuam sendo definidos pela RS Connect. Itens obrigatórios não podem ser ocultados.</span>
            </div>
            <div class="client-menu-preferences-grid">
                <?php foreach (($availableModules ?? []) as $moduleKey => $module): ?>
                    <?php
                    if (in_array($moduleKey, ['company_settings', 'users', 'permissions', 'subscription', 'privacy', 'notifications'], true)) { continue; }
                    $isVisible = (bool) (($moduleSettings[$moduleKey]['is_visible'] ?? null) ?? ($module['default_visible'] ?? true));
                    $isEnabled = (bool) (($moduleSettings[$moduleKey]['is_enabled'] ?? null) ?? ($module['default_enabled'] ?? true));
                    $locked = in_array($moduleKey, ['dashboard', 'company_settings'], true);
                    if (!$isEnabled && !$locked) { continue; }
                    ?>
                    <label class="client-menu-option <?= $isVisible ? 'is-visible' : '' ?> <?= $locked ? 'is-locked' : '' ?>">
                        <input type="checkbox" name="module_visible[]" value="<?= View::e($moduleKey) ?>" <?= $isVisible ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
                        <span><strong><?= View::e($module['label']) ?></strong><small><?= View::e($module['description']) ?></small></span>
                        <em><?= $locked ? 'Obrigatório' : 'Mostrar' ?></em>
                        <?php if ($locked): ?><input type="hidden" name="module_visible[]" value="<?= View::e($moduleKey) ?>"><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </details>

    <details class="card client-settings-accordion client-account-details">
        <summary><span><span class="eyebrow">Conta</span><strong>Informações do plano</strong><small>Dados internos da sua conta no RS Connect.</small></span><span class="drawer-chevron"></span></summary>
        <div class="client-settings-accordion-body readonly-grid">
            <div><span>Identificador</span><strong><?= View::e($company['slug']) ?></strong></div>
            <div><span>Plano</span><strong><?= View::e(ucfirst($company['plan'])) ?></strong></div>
            <div><span>Primeiros passos</span><strong><?= $company['onboarding_completed_at'] ? 'Concluídos' : 'Etapa ' . (int) $company['onboarding_step'] . '/7' ?></strong></div>
        </div>
    </details>

    <?php if ($canManageCompany): ?>
        <div class="client-company-savebar">
            <a class="btn btn-quiet" href="<?= View::e(Router::url('/company-settings')) ?>">Cancelar alterações</a>
            <div><span>Revise os dados antes de salvar.</span><button class="btn btn-primary" type="submit">Salvar alterações</button></div>
        </div>
    <?php endif; ?>
</form>
<?php endif; ?>
<?php if (!Auth::isSuperAdmin()): ?>
<script src="<?= View::e(Router::url('/assets/js/company-settings.js?v=36.5.5')) ?>" defer></script>
<?php endif; ?>
