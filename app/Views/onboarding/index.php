<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$guide = $guide ?? [];
$summary = $guide['summary'] ?? ['percent' => 0, 'done' => 0, 'total' => 8, 'pending' => 0, 'attention' => 0, 'blocked' => 0];
$steps = $guide['steps'] ?? [];
$next = $guide['next'] ?? null;
$instances = $guide['instances'] ?? ($instances ?? []);
$agents = $guide['agents'] ?? ($agents ?? []);
$defaultAgent = $guide['default_agent'] ?? ($agents[0] ?? []);
$attendanceSettings = $guide['attendance_settings'] ?? [];
$slaSettings = $guide['sla_settings'] ?? ['enabled' => 0, 'target_minutes' => 30, 'warning_percent' => 80, 'count_outside_business_hours' => 0];
// SLA é opt-in no onboarding. Empresas que já salvaram uma política preservam o estado atual;
// uma empresa nova não começa a medir tempo de resposta sem decisão explícita.
$slaEnabled = !empty($slaSettings['updated_at']) ? !empty($slaSettings['enabled']) : false;
$preSchedule = $guide['pre_schedule'] ?? [];
$agentLimits = $guide['agent_limits'] ?? [];
$businessNiches = $guide['business_niches'] ?? [];
$agentBlueprints = $guide['agent_blueprints'] ?? [];
$agentBlueprintProfile = $guide['agent_blueprint_profile'] ?? [];
$selectedCompanyBlueprintId = (int) (($company['agent_blueprint_id'] ?? 0) ?: ($agentBlueprintProfile['blueprint_id'] ?? 0));
$calendarAccess = $guide['calendar_access'] ?? [];
$calendarAvailability = $guide['calendar_availability'] ?? [];
$events = $guide['events'] ?? [];
$company = $guide['tenant'] ?? ($company ?? []);
$percent = max(0, min(100, (int) ($summary['percent'] ?? 0)));
$statusText = $summary['is_complete'] ?? false ? 'Configuração concluída' : ($next ? 'Próxima etapa: ' . ($next['short'] ?? $next['title']) : 'Em andamento');

$assistantPromptBuilder = json_decode((string) ($defaultAgent['prompt_builder_json'] ?? ''), true);
$assistantPromptBuilder = is_array($assistantPromptBuilder) ? $assistantPromptBuilder : [];
$assistantToneConfig = is_array($assistantPromptBuilder['conversation_tone'] ?? null) ? $assistantPromptBuilder['conversation_tone'] : [];
$assistantTonePreset = (string) ($assistantToneConfig['preset'] ?? 'professional');
if (!in_array($assistantTonePreset, ['warm', 'professional', 'objective', 'friendly'], true)) {
    $assistantTonePreset = 'professional';
}
$assistantInstanceId = (int) ($defaultAgent['instance_id'] ?? 0);
if ($assistantInstanceId < 1) {
    foreach ($instances as $candidateInstance) {
        if (($candidateInstance['status'] ?? '') === 'connected') {
            $assistantInstanceId = (int) ($candidateInstance['id'] ?? 0);
            if (!empty($candidateInstance['is_default'])) break;
        }
    }
}
$assistantName = trim((string) ($defaultAgent['name'] ?? ''));
if ($assistantName === '') {
    $companyDisplayName = trim((string) ($company['name'] ?? ''));
    $assistantName = $companyDisplayName !== '' ? 'Assistente ' . $companyDisplayName : 'Assistente virtual';
}
$hasConnectedInstance = false;
foreach ($instances as $candidateInstance) {
    if (($candidateInstance['status'] ?? '') === 'connected') {
        $hasConnectedInstance = true;
        break;
    }
}

// A Ativação Assistida trabalha com conceitos de negócio. As estruturas técnicas
// continuam existindo por trás, mas a tela principal não expõe workflow, capabilities
// ou chaves internas para o usuário final.
$triageFields = is_array($agentBlueprintProfile['triage_fields'] ?? null) ? $agentBlueprintProfile['triage_fields'] : [];
$activeTriageKeys = [];
foreach ($triageFields as $triageField) {
    if (!empty($triageField['active'])) {
        $activeTriageKeys[] = (string) ($triageField['field_key'] ?? '');
    }
}
$activeTriageKeys = array_values(array_filter(array_unique($activeTriageKeys)));
$agendaEnabledForBusiness = $calendarMode !== 'none';
$businessCapacity = $bookingCapacityMode === 'capacity' ? max(2, $defaultSlotCapacity) : 1;
$businessHumanApproval = (int) ($preSchedule['require_human_approval'] ?? 1) === 1;
$minimumAgeConfigured = max(0, (int) ($agentLimits['minimum_age'] ?? 0));
$maximumAgeConfigured = max(0, (int) ($agentLimits['maximum_age'] ?? 0));
$hasAgeRestriction = $minimumAgeConfigured > 0 || $maximumAgeConfigured > 0;
$businessLimitCount = 0;
foreach (['discount_rule_enabled', 'refund_rule_enabled', 'exception_rule_enabled', 'custom_case_enabled', 'emergency_rule_enabled'] as $limitFlag) {
    if (!empty($agentLimits[$limitFlag])) $businessLimitCount++;
}
if ($hasAgeRestriction) $businessLimitCount++;

$hours = ['start' => '08:00', 'end' => '18:00', 'days' => ['mon', 'tue', 'wed', 'thu', 'fri']];
$hoursSource = $attendanceSettings['business_hours_json'] ?? ($defaultAgent['business_hours_json'] ?? null);
if (!empty($hoursSource)) {
    $decoded = json_decode((string) $hoursSource, true);
    if (is_array($decoded)) {
        $hours = array_merge($hours, $decoded);
    }
}
$dayLabels = ['mon' => 'Seg', 'tue' => 'Ter', 'wed' => 'Qua', 'thu' => 'Qui', 'fri' => 'Sex', 'sat' => 'Sáb', 'sun' => 'Dom'];
$calendarMode = (string) ($calendarAccess['calendar_mode'] ?? 'none');
$smartCalendarStatus = (string) ($calendarAccess['smart_calendar_status'] ?? 'locked');
$smartCalendarReady = $smartCalendarStatus === 'ready';
$calendarDayLabels = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 0 => 'Domingo'];
$calendarDayCodeByNumber = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 0 => 'sun'];
$internalHoursRaw = json_decode((string) ($calendarAvailability['working_hours_json'] ?? '{}'), true);
$internalHoursByDay = is_array($internalHoursRaw) && isset($internalHoursRaw['by_day']) && is_array($internalHoursRaw['by_day']) ? $internalHoursRaw['by_day'] : [];
foreach ($calendarDayLabels as $dayNumber => $_dayLabel) {
    if (!isset($internalHoursByDay[(string) $dayNumber])) {
        $dayCode = $calendarDayCodeByNumber[$dayNumber] ?? '';
        $internalHoursByDay[(string) $dayNumber] = [
            'enabled' => in_array($dayCode, (array) ($hours['days'] ?? []), true) ? 1 : 0,
            'start' => (string) ($hours['start'] ?? '08:00'),
            'end' => (string) ($hours['end'] ?? '18:00'),
        ];
    }
}
$bookingCapacityMode = (($calendarAvailability['booking_capacity_mode'] ?? 'single') === 'capacity') ? 'capacity' : 'single';
$defaultSlotCapacity = $bookingCapacityMode === 'capacity' ? max(2, (int) ($calendarAvailability['default_slot_capacity'] ?? 4)) : 1;
$statusIcon = static fn (string $status): string => match ($status) {
    'complete' => '✓',
    'skipped' => '–',
    'attention' => '!',
    'blocked' => '×',
    default => (string) '•',
};
$statusClass = static fn (string $status): string => match ($status) {
    'complete' => 'is-complete',
    'skipped' => 'is-skipped',
    'attention' => 'is-attention',
    'blocked' => 'is-blocked',
    default => 'is-pending',
};

// A tela de ativação mostra apenas uma tarefa por vez. As oito validações
// internas continuam existindo para preservar auditoria e regras, mas são
// agrupadas em quatro blocos de negócio mais fáceis de entender.
$stepsByKey = [];
foreach ($steps as $candidateStep) {
    $stepsByKey[(string) ($candidateStep['key'] ?? '')] = $candidateStep;
}
$requestedStepKey = trim((string) ($_GET['step'] ?? ''));
$activeStepKey = (string) ($next['key'] ?? ($steps[0]['key'] ?? 'company_profile'));
if ($requestedStepKey !== '' && isset($stepsByKey[$requestedStepKey])) {
    $requestedStatus = (string) ($stepsByKey[$requestedStepKey]['status'] ?? 'pending');
    if ($requestedStatus !== 'blocked' || !empty($summary['is_complete'])) {
        $activeStepKey = $requestedStepKey;
    }
}
if (!isset($stepsByKey[$activeStepKey]) && $steps) {
    $activeStepKey = (string) ($steps[0]['key'] ?? 'company_profile');
}
$activeStep = $stepsByKey[$activeStepKey] ?? null;
$businessOperationKeys = ['attendance_rules', 'agenda_setup', 'agent_limits'];
if ($activeStep && in_array($activeStepKey, $businessOperationKeys, true)) {
    $activeStep['title'] = 'Como seu atendimento deve funcionar';
    $activeStep['description'] = 'Responda às perguntas abaixo em linguagem simples. O RS Connect organiza perguntas, agenda, permissões e limites automaticamente.';
    $activeStep['message'] = 'Revise as regras principais e use “Salvar e verificar”. Você não precisa montar fluxo técnico.';
}

$activationPhases = [
    ['key' => 'company', 'label' => 'Sua empresa', 'help' => 'Cadastro e privacidade', 'steps' => ['company_profile', 'lgpd_acceptance']],
    ['key' => 'operation', 'label' => 'Como atender', 'help' => 'Atendimento, agenda e limites', 'steps' => ['attendance_rules', 'agenda_setup', 'agent_limits']],
    ['key' => 'connection', 'label' => 'Conectar', 'help' => 'WhatsApp e assistente', 'steps' => ['whatsapp_connection', 'ai_agent']],
    ['key' => 'validation', 'label' => 'Testar e ativar', 'help' => 'Homologação final', 'steps' => ['final_test']],
];
foreach ($activationPhases as &$phase) {
    $phaseDone = 0;
    $phaseAttention = false;
    $phaseActive = in_array($activeStepKey, $phase['steps'], true);
    $phaseAnchor = '';
    foreach ($phase['steps'] as $phaseStepKey) {
        $phaseStep = $stepsByKey[$phaseStepKey] ?? null;
        if (!$phaseStep) continue;
        $phaseStatus = (string) ($phaseStep['status'] ?? 'pending');
        if (in_array($phaseStatus, ['complete', 'skipped'], true)) $phaseDone++;
        if ($phaseStatus === 'attention') $phaseAttention = true;
        if ($phaseAnchor === '' && $phaseStatus !== 'blocked' && !in_array($phaseStatus, ['complete', 'skipped'], true)) {
            $phaseAnchor = $phaseStepKey;
        }
    }
    if ($phaseAnchor === '') $phaseAnchor = (string) ($phase['steps'][0] ?? $activeStepKey);
    $phase['anchor'] = $phaseAnchor;
    $phase['active'] = $phaseActive;
    $phase['done'] = $phaseDone;
    $phase['total'] = count($phase['steps']);
    $phase['status'] = $phaseDone >= count($phase['steps']) ? 'complete' : ($phaseAttention ? 'attention' : ($phaseActive ? 'current' : 'pending'));
}
unset($phase);
$activePhaseNumber = 1;
$activePhaseLabel = 'Sua empresa';
foreach ($activationPhases as $phaseIndex => $phase) {
    if (!empty($phase['active'])) {
        $activePhaseNumber = $phaseIndex + 1;
        $activePhaseLabel = (string) ($phase['label'] ?? 'Ativação');
        break;
    }
}
?>

<section class="hero-card onboarding-guide-hero onboarding-assist-hero">
    <div>
        <span class="eyebrow">Ativação Assistida</span>
        <h2><?= ($summary['is_complete'] ?? false) ? 'Seu atendimento está pronto para operar.' : 'Vamos deixar seu atendimento pronto, sem complicação.' ?></h2>
        <p>Você configura somente o que é regra do seu negócio. O RS Connect prepara a estrutura técnica e mostra uma tarefa por vez.</p>
    </div>
    <div class="onboarding-score-card onboarding-assist-score">
        <strong><?= $percent ?>%</strong>
        <span><?= View::e($statusText) ?></span>
        <div class="onboarding-score-bar"><i style="width: <?= $percent ?>%"></i></div>
    </div>
</section>

<section class="card" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:16px 0"><div><strong>Prepare seu assistente em poucos minutos</strong><p style="margin:4px 0 0">Defina nome, perguntas, preços, horários e respostas em uma única tela.</p></div><a class="btn btn-primary" href="<?= View::e(Router::url('/onboarding/express')) ?>">Abrir configuração rápida</a></section>

<nav class="onboarding-phase-strip" aria-label="Etapas da ativação assistida">
    <?php foreach ($activationPhases as $phaseIndex => $phase): ?>
        <a class="onboarding-phase-card is-<?= View::e((string) $phase['status']) ?>" href="<?= View::e(Router::url('/onboarding?step=' . $phase['anchor'])) ?>#<?= View::e((string) $phase['anchor']) ?>">
            <span class="onboarding-phase-number"><?= $phaseIndex + 1 ?></span>
            <span class="onboarding-phase-copy"><strong><?= View::e((string) $phase['label']) ?></strong><small><?= View::e((string) $phase['help']) ?></small></span>
            <span class="onboarding-phase-progress"><?= $phase['status'] === 'complete' ? '✓' : (!empty($phase['active']) ? 'Agora' : '') ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<section class="onboarding-current-task card">
    <div>
        <span class="eyebrow">Agora</span>
        <strong><?= View::e((string) ($activeStep['title'] ?? 'Revisar ativação')) ?></strong>
        <p><?= View::e((string) ($activeStep['message'] ?? $activeStep['description'] ?? '')) ?></p>
    </div>
    <?php if ($next && $activeStepKey !== (string) ($next['key'] ?? '')): ?>
        <a class="btn btn-secondary" href="<?= View::e(Router::url('/onboarding?step=' . ($next['key'] ?? ''))) ?>#<?= View::e((string) ($next['key'] ?? '')) ?>">Voltar para a próxima tarefa</a>
    <?php endif; ?>
</section>

<div class="onboarding-guide-layout onboarding-assist-layout">
    <aside class="card onboarding-steps-card onboarding-ready-summary onboarding-business-summary">
        <div class="section-heading compact-heading">
            <div><span class="eyebrow">Seu atendimento</span><h2>Visão rápida</h2></div>
        </div>
        <div class="business-summary-list">
            <div class="<?= (int) ($company['business_niche_id'] ?? 0) > 0 ? 'is-ready' : '' ?>"><span><?= (int) ($company['business_niche_id'] ?? 0) > 0 ? '✓' : '•' ?></span><p><strong>Empresa</strong><small><?= View::e((string) ($company['segment'] ?? 'Segmento ainda não definido')) ?></small></p></div>
            <div class="<?= count($activeTriageKeys) > 0 ? 'is-ready' : '' ?>"><span><?= count($activeTriageKeys) > 0 ? '✓' : '•' ?></span><p><strong>Informações do atendimento</strong><small><?= count($activeTriageKeys) ?> selecionada(s)</small></p></div>
            <div class="<?= in_array((string) ($stepsByKey['agenda_setup']['status'] ?? ''), ['complete', 'skipped'], true) ? 'is-ready' : '' ?>"><span><?= in_array((string) ($stepsByKey['agenda_setup']['status'] ?? ''), ['complete', 'skipped'], true) ? '✓' : '•' ?></span><p><strong>Agenda</strong><small><?= $agendaEnabledForBusiness ? ($businessCapacity > 1 ? $businessCapacity . ' pessoas por horário' : 'Atendimento individual') : 'Sem agendamento pelo WhatsApp' ?></small></p></div>
            <div class="<?= $hasConnectedInstance ? 'is-ready' : '' ?>"><span><?= $hasConnectedInstance ? '✓' : '•' ?></span><p><strong>WhatsApp</strong><small><?= $hasConnectedInstance ? 'Conectado' : 'Ainda precisa conectar' ?></small></p></div>
        </div>
        <div class="onboarding-simple-note"><strong>Sem termos técnicos</strong><span>Você informa como a empresa trabalha. O RS Connect monta e valida a configuração por trás.</span></div>
    </aside>

    <main class="onboarding-main-flow">
        <?php foreach ($steps as $step): ?>
            <?php
            if ((string) ($step['key'] ?? '') !== $activeStepKey) continue;
            $stepDone = in_array((string) ($step['status'] ?? ''), ['complete', 'skipped'], true);
            $stepCurrent = true;
            $stepAccessible = (string) ($step['status'] ?? '') !== 'blocked' || !empty($summary['is_complete']);
            $isBusinessOperation = in_array((string) ($step['key'] ?? ''), $businessOperationKeys, true);
            $displayStepTitle = $isBusinessOperation ? 'Como seu atendimento deve funcionar' : (string) ($step['title'] ?? 'Configuração');
            $displayStepDescription = $isBusinessOperation
                ? 'Responda em linguagem simples. O RS Connect organiza perguntas, agenda, permissões e limites automaticamente.'
                : (string) ($step['description'] ?? '');
            $displayStepMessage = $isBusinessOperation
                ? 'Revise as regras principais e use “Salvar e verificar”. Você não precisa montar fluxo técnico.'
                : (string) ($step['message'] ?? '');
            ?>
            <section class="card onboarding-step-panel <?= View::e($statusClass((string) $step['status'])) ?><?= $stepCurrent ? ' is-current' : '' ?><?= !$stepAccessible ? ' is-locked' : '' ?>" id="<?= View::e($step['key']) ?>">
                <div class="onboarding-step-head">
                    <div class="step-number"><?= (int) $activePhaseNumber ?></div>
                    <div>
                        <span class="eyebrow">Bloco <?= (int) $activePhaseNumber ?> de 4 · <?= View::e($activePhaseLabel) ?></span>
                        <h2><?= View::e($displayStepTitle) ?></h2>
                        <p><?= View::e($displayStepDescription) ?></p>
                    </div>
                    <span class="badge <?= View::e($step['status_badge'] ?? 'badge-warning') ?>"><?= View::e($step['status_label'] ?? 'Pendente') ?></span>
                </div>
                <?php if (!$isBusinessOperation): ?>
                    <div class="onboarding-step-message">
                        <strong>Status atual:</strong> <?= View::e($displayStepMessage) ?>
                    </div>
                <?php endif; ?>

                <?php if (!$stepAccessible): ?>
                    <div class="onboarding-locked-message"><strong>Etapa ainda bloqueada</strong><span>Conclua a etapa anterior para liberar esta configuração.</span></div>
                <?php elseif ($step['key'] === 'company_profile'): ?>
                    <form class="wizard-card onboarding-inline-form onboarding-company-setup" method="post" action="<?= View::e(Router::url('/onboarding/company')) ?>" data-company-onboarding>
                        <?= Csrf::input() ?>
                        <div class="onboarding-company-prefill-note"><strong>Comece pelo segmento.</strong><span>Sem segmento não é possível avançar. Com essa escolha, o RS Connect prepara perguntas, regras e fluxo recomendados para sua empresa.</span></div>

                        <section class="onboarding-essential-card">
                            <div class="section-heading compact"><div><span class="eyebrow">Essencial</span><h3>Conte quem é sua empresa</h3><p>São as únicas informações necessárias para preparar a configuração inicial.</p></div><span class="badge badge-warning">Obrigatório</span></div>
                            <div class="form-grid two onboarding-company-editable-fields">
                                <label class="field"><span>Segmento da empresa</span><select name="business_niche_id" required data-onboarding-niche>
                                    <option value="">Selecione o segmento</option>
                                    <?php foreach ($businessNiches as $niche): ?>
                                        <option value="<?= (int) ($niche['id'] ?? 0) ?>" data-description="<?= View::e((string) ($niche['description'] ?? '')) ?>" <?= (int) ($company['business_niche_id'] ?? 0) === (int) ($niche['id'] ?? 0) ? 'selected' : '' ?>><?= View::e((string) ($niche['name'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select><small class="field-hint">Escolha a atividade principal. O restante será sugerido pelo RS Connect.</small></label>
                                <label class="field"><span>Nome usado no atendimento</span><input name="name" value="<?= View::e($company['name'] ?? '') ?>" required><small class="field-hint">É como sua empresa aparecerá nas telas e comunicações.</small></label>
                                <label class="field"><span>E-mail comercial</span><input type="email" name="email" value="<?= View::e($company['email'] ?? '') ?>" placeholder="contato@empresa.com.br"></label>
                                <label class="field"><span>Telefone</span><input name="phone" value="<?= View::e($company['phone'] ?? '') ?>" placeholder="(00) 00000-0000"><small class="field-hint">Informe e-mail ou telefone. Não é necessário preencher os dois.</small></label>
                            </div>

                            <div class="onboarding-segment-preview" data-onboarding-segment-preview>
                                <span>Configuração recomendada</span>
                                <strong data-onboarding-blueprint-name>Escolha um segmento</strong>
                                <p data-onboarding-blueprint-description>Ao selecionar o segmento, o RS Connect escolhe automaticamente o modelo inicial mais adequado.</p>
                                <div class="onboarding-segment-prepares"><em>Perguntas iniciais</em><em>Regras de segurança</em><em>Permissões</em><em>Fluxo de atendimento</em></div>
                            </div>
                        </section>

                        <details class="onboarding-simple-details onboarding-company-advanced">
                            <summary><span><strong>Ajustes opcionais</strong><small>Dados oficiais, site e modelo de implantação</small></span><em>Ver opções</em></summary>
                            <div class="onboarding-simple-details-body">
                                <div class="master-data-panel is-compact">
                                    <div class="master-data-panel-head">
                                        <div><span class="eyebrow">Cadastro RS</span><strong>Dados oficiais da empresa</strong><small>Somente leitura.</small></div>
                                        <span class="master-data-status">Protegido</span>
                                    </div>
                                    <div class="master-data-summary">
                                        <div class="master-data-item"><span>Razão social</span><strong><?= View::e($company['legal_name'] ?? 'Não informado') ?></strong></div>
                                        <div class="master-data-item"><span>CNPJ/CPF</span><strong><?= View::e($company['document'] ?? 'Não informado') ?></strong></div>
                                    </div>
                                </div>
                                <div class="form-grid two onboarding-company-editable-fields">
                                    <label class="field"><span>Site</span><input type="url" name="website" value="<?= View::e($company['website'] ?? '') ?>" placeholder="https://empresa.com.br"></label>
                                    <label class="field onboarding-blueprint-field"><span>Modelo inicial recomendado</span><select name="agent_blueprint_id" required data-onboarding-blueprint>
                                        <option value="">Selecione primeiro o segmento</option>
                                        <?php foreach ($agentBlueprints as $blueprint): ?>
                                            <option value="<?= (int) ($blueprint['id'] ?? 0) ?>" data-niche-id="<?= (int) ($blueprint['niche_id'] ?? 0) ?>" data-description="<?= View::e((string) ($blueprint['description'] ?? '')) ?>" <?= $selectedCompanyBlueprintId === (int) ($blueprint['id'] ?? 0) ? 'selected' : '' ?>><?= View::e((string) ($blueprint['name'] ?? '')) ?></option>
                                        <?php endforeach; ?>
                                    </select><small class="field-hint">Normalmente não é necessário alterar: o primeiro modelo ativo do segmento é escolhido automaticamente.</small></label>
                                </div>
                            </div>
                        </details>

                        <div class="message-info onboarding-company-model-note"><strong>Sem ativação automática</strong><span>Salvar aqui apenas prepara a configuração. O atendimento automático só será liberado depois da conexão e do teste final.</span></div>
                        <div class="form-actions"><button class="btn btn-primary" type="submit">Continuar</button></div>
                    </form>
                <?php elseif ($step['key'] === 'whatsapp_connection'): ?>
                    <div class="onboarding-action-grid">
                        <div>
                            <h3>Conecte o número que atenderá os clientes</h3><p class="muted-text">Você só precisa deixar uma conexão como <strong>conectada</strong>. Depois volte para esta tela; o progresso é reconhecido automaticamente.</p>
                            <?php foreach ($instances as $instance): ?>
                                <div class="mini-status-row"><strong><?= View::e($instance['name'] ?? $instance['instance_name'] ?? 'WhatsApp') ?></strong><span class="badge <?= ($instance['status'] ?? '') === 'connected' ? 'badge-success' : 'badge-warning' ?>"><?= View::e($instance['status'] ?? 'pendente') ?></span></div>
                            <?php endforeach; ?>
                            <?php if (!$instances): ?><div class="empty-state compact-empty">Nenhuma conexão de WhatsApp cadastrada.</div><?php endif; ?>
                        </div>
                        <div class="onboarding-actions-box">
                            <a class="btn btn-primary btn-block" href="<?= View::e(Router::url('/instances')) ?>">Conectar WhatsApp</a>
                        </div>
                    </div>
                <?php elseif ($step['key'] === 'ai_agent'): ?>
                    <form class="wizard-card onboarding-inline-form onboarding-assistant-quick" method="post" action="<?= View::e(Router::url('/onboarding/agent')) ?>">
                        <?= Csrf::input() ?>
                        <div class="onboarding-pre-agent-note"><strong>O RS Connect já preparou a parte técnica.</strong><span>Você só escolhe o nome, o tom de conversa e qual WhatsApp o assistente usará. Perguntas, fluxo, agenda e limites continuam vindo das configurações das etapas anteriores.</span></div>

                        <section class="onboarding-essential-card">
                            <div class="section-heading compact"><div><span class="eyebrow">Essencial</span><h3>Como o assistente vai se apresentar?</h3><p>Não é necessário abrir a tela avançada de Assistentes para concluir a ativação.</p></div><span class="badge badge-success">Configuração rápida</span></div>
                            <div class="form-grid two">
                                <label class="field"><span>Nome do assistente</span><input name="name" value="<?= View::e($assistantName) ?>" required><small class="field-hint">Ex.: Assistente Mariana, Atendimento RS ou Recepção.</small></label>
                                <label class="field"><span>Tom da conversa</span><select name="agent_tone_preset" required>
                                    <option value="warm" <?= $assistantTonePreset === 'warm' ? 'selected' : '' ?>>Acolhedor e empático</option>
                                    <option value="professional" <?= $assistantTonePreset === 'professional' ? 'selected' : '' ?>>Cordial e profissional</option>
                                    <option value="objective" <?= $assistantTonePreset === 'objective' ? 'selected' : '' ?>>Objetivo e direto</option>
                                    <option value="friendly" <?= $assistantTonePreset === 'friendly' ? 'selected' : '' ?>>Leve e próximo</option>
                                </select><small class="field-hint">O tom muda a forma de escrever, não as regras da empresa.</small></label>
                                <label class="field onboarding-assistant-channel"><span>WhatsApp usado pelo assistente</span><select name="instance_id" required>
                                    <option value="">Selecione uma conexão conectada</option>
                                    <?php foreach ($instances as $instance): ?>
                                        <?php $instanceConnected = ($instance['status'] ?? '') === 'connected'; ?>
                                        <option value="<?= (int) ($instance['id'] ?? 0) ?>" <?= $assistantInstanceId === (int) ($instance['id'] ?? 0) ? 'selected' : '' ?> <?= !$instanceConnected ? 'disabled' : '' ?>><?= View::e((string) ($instance['name'] ?? $instance['instance_name'] ?? 'WhatsApp')) ?><?= $instanceConnected ? '' : ' — não conectado' ?></option>
                                    <?php endforeach; ?>
                                </select><small class="field-hint">Somente conexões realmente conectadas podem receber o assistente.</small></label>
                                <div class="onboarding-assistant-ready-note"><span class="eyebrow">Já preparado</span><strong><?= View::e((string) ($agentBlueprintProfile['blueprint_name'] ?? $company['segment'] ?? 'Modelo da empresa')) ?></strong><small>Triagem, políticas, permissões e ordem do atendimento são lidas da configuração estruturada da empresa — não de um prompt gigante.</small></div>
                            </div>
                        </section>

                        <?php if (!$hasConnectedInstance): ?>
                            <div class="message-warning"><strong>Conecte o WhatsApp antes de continuar.</strong><span>Volte ao passo anterior e finalize a leitura do QR Code. Assim que a conexão ficar conectada, ela aparecerá disponível aqui.</span></div>
                        <?php endif; ?>

                        <div class="message-info onboarding-assistant-advanced-note"><strong>Configurações avançadas ficam para depois.</strong><span>Modelo de IA, instruções técnicas, memória e outros ajustes permanecem disponíveis na área de Assistentes, mas não são necessários para colocar o atendimento inicial em funcionamento.</span></div>
                        <div class="form-actions"><button class="btn btn-primary" type="submit" <?= !$hasConnectedInstance ? 'disabled' : '' ?>>Salvar assistente e continuar</button></div>
                    </form>
                <?php elseif (in_array($step['key'], $businessOperationKeys, true)): ?>
                    <form class="wizard-card onboarding-inline-form onboarding-business-operation" method="post" action="<?= View::e(Router::url('/onboarding/operation')) ?>" data-business-operation>
                        <?= Csrf::input() ?>

                        <div class="business-operation-overview" aria-label="Resumo da configuração atual">
                            <div><span>Informações</span><strong><?= count($activeTriageKeys) ?> selecionada(s)</strong><small>O que o assistente precisa saber.</small></div>
                            <div><span>Agenda</span><strong><?= $agendaEnabledForBusiness ? 'Pelo WhatsApp' : 'Sem agendamento' ?></strong><small><?= $agendaEnabledForBusiness ? ($businessCapacity > 1 ? $businessCapacity . ' pessoas por horário' : '1 pessoa por horário') : 'Pode ser ativada quando quiser' ?></small></div>
                            <div><span>Confirmação</span><strong><?= !$agendaEnabledForBusiness ? 'Não se aplica' : ($businessHumanApproval ? 'Pela equipe' : 'Automática') ?></strong><small><?= $agendaEnabledForBusiness && $businessHumanApproval ? 'O agente pré-reserva e aguarda.' : 'Regra definida pela empresa.' ?></small></div>
                            <div><span>Limites</span><strong><?= $businessLimitCount ?> regra(s)</strong><small>Casos em que o agente não decide sozinho.</small></div>
                        </div>

                        <section class="business-question-card">
                            <div class="business-question-heading">
                                <span class="business-question-number">1</span>
                                <div><h3>O que o assistente precisa perguntar?</h3><p>Marque somente as informações realmente necessárias. O RS Connect faz uma pergunta por vez e respeita esta seleção.</p></div>
                            </div>
                            <?php if ($triageFields): ?>
                                <div class="business-check-grid">
                                    <?php foreach ($triageFields as $field): ?>
                                        <?php
                                        $fieldKey = (string) ($field['field_key'] ?? '');
                                        $fieldLabel = trim((string) ($field['label'] ?? $fieldKey));
                                        $fieldPrompt = trim((string) ($field['prompt_text'] ?? ''));
                                        $fieldChecked = in_array($fieldKey, $activeTriageKeys, true);
                                        ?>
                                        <label class="business-check-card">
                                            <input type="checkbox" name="triage_fields[]" value="<?= View::e($fieldKey) ?>" <?= $fieldChecked ? 'checked' : '' ?>>
                                            <span><strong><?= View::e($fieldLabel !== '' ? $fieldLabel : 'Informação') ?></strong><?php if ($fieldPrompt !== ''): ?><small><?= View::e($fieldPrompt) ?></small><?php endif; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="message-warning"><strong>O segmento ainda não preparou perguntas iniciais.</strong><span>Volte ao cadastro da empresa e confirme o segmento/modelo antes de continuar.</span></div>
                            <?php endif; ?>
                            <div class="business-consequence"><strong>Resultado:</strong><span>As informações marcadas serão coletadas antes de qualquer agendamento. Campos desmarcados deixam de bloquear o atendimento.</span></div>
                        </section>

                        <section class="business-question-card">
                            <div class="business-question-heading">
                                <span class="business-question-number">2</span>
                                <div><h3>Quando sua equipe atende?</h3><p>Escolha os dias e o horário normal de funcionamento. Isso também orienta mensagens fora do expediente.</p></div>
                            </div>
                            <div class="form-grid two">
                                <label class="field"><span>Começa às</span><input type="time" name="start_time" value="<?= View::e($hours['start'] ?? '08:00') ?>" required></label>
                                <label class="field"><span>Termina às</span><input type="time" name="end_time" value="<?= View::e($hours['end'] ?? '18:00') ?>" required></label>
                            </div>
                            <div class="business-day-grid"><?php foreach ($dayLabels as $key => $label): ?><label><input type="checkbox" name="days[]" value="<?= View::e($key) ?>" <?= in_array($key, (array) ($hours['days'] ?? []), true) ? 'checked' : '' ?>><span><?= View::e($label) ?></span></label><?php endforeach; ?></div>
                            <details class="business-inline-optional">
                                <summary>Mensagem fora do horário e SLA <small>opcional</small></summary>
                                <div class="business-inline-optional-body">
                                    <label class="field"><span>Mensagem fora do horário</span><textarea name="after_hours_message" rows="3"><?= View::e($attendanceSettings['after_hours_message'] ?? ($defaultAgent['after_hours_message'] ?? 'No momento estamos fora do horário de atendimento. Assim que possível, nossa equipe retorna o contato.')) ?></textarea></label>
                                    <label class="field"><span>Mensagem ao encaminhar para a equipe</span><textarea name="human_handoff_message" rows="3"><?= View::e($attendanceSettings['human_handoff_message'] ?? ($defaultAgent['human_handoff_message'] ?? 'Vou encaminhar sua solicitação para uma pessoa da equipe continuar o atendimento.')) ?></textarea></label>
                                    <div class="business-toggle-row">
                                        <label><input type="hidden" name="sla_enabled" value="0"><input type="checkbox" name="sla_enabled" value="1" data-sla-enabled <?= $slaEnabled ? 'checked' : '' ?>><span><strong>Medir tempo da primeira resposta humana</strong><small>Ative somente se quiser acompanhar SLA.</small></span></label>
                                    </div>
                                    <div class="form-grid three" data-sla-fields <?= $slaEnabled ? '' : 'hidden' ?>>
                                        <label class="field"><span>Meta da 1ª resposta</span><div class="input-with-suffix"><input type="number" name="sla_target_minutes" min="5" max="1440" step="5" value="<?= (int) ($slaSettings['target_minutes'] ?? 30) ?>"><span>min</span></div></label>
                                        <label class="field"><span>Alerta em</span><div class="input-with-suffix"><input type="number" name="sla_warning_percent" min="50" max="99" value="<?= (int) ($slaSettings['warning_percent'] ?? 80) ?>"><span>%</span></div></label>
                                        <label class="checkbox-field"><span>Fora do expediente</span><span class="checkbox-control"><input type="checkbox" name="sla_count_outside_hours" value="1" <?= !empty($slaSettings['count_outside_business_hours']) ? 'checked' : '' ?>><span>Contabilizar</span></span></label>
                                    </div>
                                    <input type="hidden" name="business_timezone" value="<?= View::e($attendanceSettings['business_timezone'] ?? ($defaultAgent['business_timezone'] ?? 'America/Sao_Paulo')) ?>">
                                    <input type="hidden" name="cooldown_seconds" value="<?= (int) ($attendanceSettings['cooldown_seconds'] ?? ($defaultAgent['cooldown_seconds'] ?? 60)) ?>">
                                </div>
                            </details>
                        </section>

                        <section class="business-question-card">
                            <div class="business-question-heading">
                                <span class="business-question-number">3</span>
                                <div><h3>O cliente pode agendar pelo WhatsApp?</h3><p>Se sim, defina apenas as regras de negócio. A estrutura da agenda é preparada automaticamente.</p></div>
                            </div>
                            <div class="business-binary-choice" role="radiogroup" aria-label="Agendamento pelo WhatsApp">
                                <label><input type="radio" name="calendar_enabled" value="1" <?= $agendaEnabledForBusiness ? 'checked' : '' ?>><span><strong>Sim</strong><small>Consultar e reservar horários.</small></span></label>
                                <label><input type="radio" name="calendar_enabled" value="0" <?= !$agendaEnabledForBusiness ? 'checked' : '' ?>><span><strong>Não</strong><small>O assistente não oferece horários.</small></span></label>
                            </div>
                            <div class="business-calendar-fields" data-business-calendar-fields <?= $agendaEnabledForBusiness ? '' : 'hidden' ?>>
                                <div class="form-grid three">
                                    <label class="field"><span>Duração do atendimento</span><div class="input-with-suffix"><input type="number" name="default_duration_minutes" min="15" max="240" value="<?= (int) ($calendarAvailability['default_duration_minutes'] ?? $preSchedule['default_duration_minutes'] ?? 60) ?>"><span>min</span></div></label>
                                    <label class="field"><span>Pessoas no mesmo horário</span><input type="number" name="slot_capacity" min="1" max="100" value="<?= (int) $businessCapacity ?>"><small>Use 1 para atendimento individual.</small></label>
                                    <label class="field"><span>Antecedência mínima</span><div class="input-with-suffix"><input type="number" name="min_notice_hours" min="0" max="720" value="<?= (int) ($calendarAvailability['min_notice_hours'] ?? 2) ?>"><span>hora(s)</span></div></label>
                                </div>
                                <div class="business-decision-block">
                                    <span>Quem confirma o horário?</span>
                                    <div class="business-binary-choice compact" role="radiogroup">
                                        <label><input type="radio" name="approval_mode" value="human" <?= $businessHumanApproval ? 'checked' : '' ?>><span><strong>Minha equipe</strong><small>O agente pré-reserva e aguarda aprovação.</small></span></label>
                                        <label><input type="radio" name="approval_mode" value="automatic" <?= !$businessHumanApproval ? 'checked' : '' ?>><span><strong>Automaticamente</strong><small>O cliente recebe a confirmação sem aprovação humana.</small></span></label>
                                    </div>
                                </div>
                                <div class="business-consequence"><strong>Resultado:</strong><span data-calendar-consequence><?= $businessHumanApproval ? 'O agente consulta a disponibilidade, segura a vaga e aguarda sua equipe confirmar.' : 'O agente consulta a disponibilidade e confirma o horário quando todas as regras estiverem atendidas.' ?></span></div>
                            </div>
                        </section>

                        <section class="business-question-card">
                            <div class="business-question-heading">
                                <span class="business-question-number">4</span>
                                <div><h3>Quando o assistente não pode decidir sozinho?</h3><p>Escolha situações comuns. A decisão configurada prevalece sobre qualquer resposta gerada pela IA.</p></div>
                            </div>
                            <div class="business-rule-grid">
                                <label class="business-rule-card"><span><strong>Pedido de desconto</strong><small>O agente nunca inventa desconto.</small></span><select name="discount_policy"><option value="off" <?= empty($agentLimits['discount_rule_enabled']) ? 'selected' : '' ?>>Sem regra especial</option><option value="handoff" <?= !empty($agentLimits['discount_rule_enabled']) && ($agentLimits['discount_action'] ?? 'handoff') === 'handoff' ? 'selected' : '' ?>>Encaminhar para responsável</option><option value="deny" <?= !empty($agentLimits['discount_rule_enabled']) && ($agentLimits['discount_action'] ?? '') === 'warn' ? 'selected' : '' ?>>Informar que não pode conceder</option></select></label>
                                <label class="business-rule-card"><span><strong>Encaixe ou exceção</strong><small>Evita prometer algo fora das regras.</small></span><select name="exception_policy"><option value="off" <?= empty($agentLimits['exception_rule_enabled']) ? 'selected' : '' ?>>Sem regra especial</option><option value="handoff" <?= !empty($agentLimits['exception_rule_enabled']) && ($agentLimits['exception_action'] ?? 'handoff') === 'handoff' ? 'selected' : '' ?>>Encaminhar para responsável</option><option value="deny" <?= !empty($agentLimits['exception_rule_enabled']) && ($agentLimits['exception_action'] ?? '') === 'warn' ? 'selected' : '' ?>>Informar que não abre exceção</option></select></label>
                                <label class="business-rule-card"><span><strong>Reembolso / estorno</strong><small>Decisões financeiras ficam sob controle humano.</small></span><select name="refund_policy"><option value="off" <?= empty($agentLimits['refund_rule_enabled']) ? 'selected' : '' ?>>Sem regra especial</option><option value="handoff" <?= !empty($agentLimits['refund_rule_enabled']) && ($agentLimits['refund_action'] ?? 'handoff') === 'handoff' ? 'selected' : '' ?>>Encaminhar para responsável</option><option value="deny" <?= !empty($agentLimits['refund_rule_enabled']) && ($agentLimits['refund_action'] ?? '') === 'warn' ? 'selected' : '' ?>>Informar que não pode decidir</option></select></label>
                            </div>

                            <div class="business-age-rule">
                                <div><strong>A empresa atende qualquer idade?</strong><small>Se houver faixa etária, o agente usa essa regra antes de oferecer agenda.</small></div>
                                <div class="business-binary-choice compact"><label><input type="radio" name="age_restricted" value="0" <?= !$hasAgeRestriction ? 'checked' : '' ?>><span><strong>Sim</strong><small>Sem limite de idade.</small></span></label><label><input type="radio" name="age_restricted" value="1" <?= $hasAgeRestriction ? 'checked' : '' ?>><span><strong>Não</strong><small>Definir faixa atendida.</small></span></label></div>
                            </div>
                            <div class="form-grid three" data-business-age-fields <?= $hasAgeRestriction ? '' : 'hidden' ?>>
                                <label class="field"><span>Idade mínima</span><input type="number" name="minimum_age" min="0" max="130" value="<?= $minimumAgeConfigured ?>"><small>0 = sem mínimo.</small></label>
                                <label class="field"><span>Idade máxima</span><input type="number" name="maximum_age" min="0" max="130" value="<?= $maximumAgeConfigured ?>"><small>0 = sem máximo.</small></label>
                                <label class="field"><span>Se estiver fora da faixa</span><select name="age_action"><option value="block_schedule" <?= ($agentLimits['age_action'] ?? 'block_schedule') === 'block_schedule' ? 'selected' : '' ?>>Não oferecer agenda</option><option value="handoff" <?= ($agentLimits['age_action'] ?? '') === 'handoff' ? 'selected' : '' ?>>Encaminhar para equipe</option><option value="block" <?= ($agentLimits['age_action'] ?? '') === 'block' ? 'selected' : '' ?>>Interromper atendimento automático</option></select></label>
                            </div>

                            <details class="business-inline-optional">
                                <summary>Outros casos especiais <small>opcional</small></summary>
                                <div class="business-inline-optional-body">
                                    <label class="checkbox-field"><span>Parar automação em situações urgentes/sensíveis</span><span class="checkbox-control"><input type="checkbox" name="emergency_rule_enabled" value="1" <?= !empty($agentLimits['emergency_rule_enabled']) ? 'checked' : '' ?>><span>Ativar</span></span></label>
                                    <label class="field"><span>Casos ou serviços que exigem análise humana</span><textarea name="custom_case_terms" rows="4" placeholder="Um por linha. Ex.: convênio X, atendimento domiciliar"><?= View::e($agentLimits['custom_case_terms'] ?? '') ?></textarea><small>Se preencher, o RS Connect cria uma regra estruturada para cada linha.</small></label>
                                    <input type="hidden" name="custom_case_enabled" value="<?= trim((string) ($agentLimits['custom_case_terms'] ?? '')) !== '' || !empty($agentLimits['custom_case_enabled']) ? '1' : '0' ?>">
                                    <input type="hidden" name="custom_case_action" value="handoff">
                                    <input type="hidden" name="emergency_action" value="handoff">
                                    <label class="field"><span>Resposta em caso urgente</span><textarea name="emergency_message" rows="3"><?= View::e($agentLimits['emergency_message'] ?? 'Essa situação precisa de atenção humana. Vou encaminhar sua mensagem para a equipe responsável.') ?></textarea></label>
                                </div>
                            </details>
                        </section>

                        <details class="business-advanced-linkbox">
                            <summary>Precisa de uma configuração fora do padrão?</summary>
                            <div><p>As opções técnicas continuam disponíveis para implantação e suporte, mas não são necessárias para a operação inicial.</p><div class="onboarding-final-links"><a href="<?= View::e(Router::url('/agents')) ?>">Configuração avançada do assistente</a><a href="<?= View::e(Router::url('/calendar')) ?>">Agenda avançada</a></div></div>
                        </details>

                        <div class="business-save-bar">
                            <div><strong>O RS Connect valida antes de publicar.</strong><span>Se alguma regra estiver incompleta ou contraditória, nada é aplicado pela metade.</span></div>
                            <button class="btn btn-primary" type="submit">Salvar e verificar</button>
                        </div>
                    </form>
                <?php elseif ($step['key'] === 'final_test'): ?>
                    <div class="onboarding-action-grid">
                        <div>
                            <h3>Teste operacional final</h3>
                            <p class="muted-text">Envie uma mensagem real, confirme a resposta da IA, teste a passagem para humano e valide a agenda quando estiver habilitada.</p>
                            <div class="onboarding-final-links"><a href="<?= View::e(Router::url('/conversations')) ?>">Conversas</a><a href="<?= View::e(Router::url('/calendar')) ?>">Agenda</a><a href="<?= View::e(Router::url('/subscription')) ?>">Assinatura</a></div>
                        </div>
                        <form class="onboarding-actions-box" method="post" action="<?= View::e(Router::url('/onboarding/final-test')) ?>">
                            <?= Csrf::input() ?>
                            <label class="field"><span>Observação do teste</span><textarea name="notes" rows="4" placeholder="Ex.: WhatsApp enviou e recebeu, IA respondeu, agenda validada."></textarea></label>
                            <button class="btn btn-primary btn-block" type="submit">Finalizar onboarding</button>
                        </form>
                    </div>
                <?php endif; ?>


            </section>
        <?php endforeach; ?>

        <script>
        (function () {
            const root = document.querySelector('[data-company-onboarding]');
            if (!root) return;
            const niche = root.querySelector('[data-onboarding-niche]');
            const blueprint = root.querySelector('[data-onboarding-blueprint]');
            const previewName = root.querySelector('[data-onboarding-blueprint-name]');
            const previewDescription = root.querySelector('[data-onboarding-blueprint-description]');
            if (!niche || !blueprint) return;

            const syncPreview = () => {
                const selected = blueprint.selectedOptions[0];
                if (previewName) previewName.textContent = selected && selected.value ? selected.textContent.trim() : 'Nenhum modelo disponível';
                if (previewDescription) {
                    const text = selected && selected.value ? (selected.dataset.description || '') : '';
                    previewDescription.textContent = text || 'O segmento escolhido ainda precisa de um modelo de atendimento ativo.';
                }
            };

            const syncBlueprints = (autoChoose) => {
                const nicheId = niche.value;
                let firstVisible = '';
                let currentVisible = false;
                [...blueprint.options].forEach((option, index) => {
                    if (index === 0) return;
                    const visible = !!nicheId && option.dataset.nicheId === nicheId;
                    option.hidden = !visible;
                    option.disabled = !visible;
                    if (visible && !firstVisible) firstVisible = option.value;
                    if (visible && option.selected) currentVisible = true;
                });
                if (!nicheId) {
                    blueprint.value = '';
                } else if ((autoChoose || !currentVisible) && firstVisible) {
                    blueprint.value = firstVisible;
                } else if (!firstVisible) {
                    blueprint.value = '';
                }
                syncPreview();
            };

            niche.addEventListener('change', () => syncBlueprints(true));
            blueprint.addEventListener('change', syncPreview);
            syncBlueprints(false);
        })();

        (function () {
            const toggle = document.querySelector('[data-sla-enabled]');
            const fields = document.querySelector('[data-sla-fields]');
            if (!toggle || !fields) return;
            const sync = () => {
                fields.hidden = !toggle.checked;
                const label = toggle.closest('.onboarding-enable-toggle')?.querySelector('small');
                if (label) label.textContent = toggle.checked ? 'Ativado' : 'Desativado';
            };
            toggle.addEventListener('change', sync);
            sync();
        })();

        (function () {
            const root = document.querySelector('[data-business-operation]');
            if (!root) return;
            const calendarFields = root.querySelector('[data-business-calendar-fields]');
            const ageFields = root.querySelector('[data-business-age-fields]');
            const consequence = root.querySelector('[data-calendar-consequence]');

            const syncCalendar = () => {
                const enabled = root.querySelector('input[name="calendar_enabled"]:checked')?.value === '1';
                if (calendarFields) calendarFields.hidden = !enabled;
                root.querySelectorAll('input[name="calendar_enabled"]').forEach((input) => {
                    input.closest('label')?.classList.toggle('is-selected', input.checked);
                });
                syncConsequence();
            };
            const syncAge = () => {
                const restricted = root.querySelector('input[name="age_restricted"]:checked')?.value === '1';
                if (ageFields) ageFields.hidden = !restricted;
                root.querySelectorAll('input[name="age_restricted"]').forEach((input) => {
                    input.closest('label')?.classList.toggle('is-selected', input.checked);
                });
            };
            const syncConsequence = () => {
                if (!consequence) return;
                const enabled = root.querySelector('input[name="calendar_enabled"]:checked')?.value === '1';
                const human = root.querySelector('input[name="approval_mode"]:checked')?.value !== 'automatic';
                if (!enabled) {
                    consequence.textContent = 'O assistente não consulta nem oferece horários enquanto a agenda estiver desativada.';
                } else if (human) {
                    consequence.textContent = 'O agente consulta a disponibilidade, segura a vaga e aguarda sua equipe confirmar.';
                } else {
                    consequence.textContent = 'O agente consulta a disponibilidade e confirma o horário quando todas as regras estiverem atendidas.';
                }
            };
            const syncChecks = () => {
                root.querySelectorAll('.business-check-card input[type="checkbox"]').forEach((input) => {
                    input.closest('.business-check-card')?.classList.toggle('is-selected', input.checked);
                });
            };
            const syncApproval = () => {
                root.querySelectorAll('input[name="approval_mode"]').forEach((input) => {
                    input.closest('label')?.classList.toggle('is-selected', input.checked);
                });
                syncConsequence();
            };

            root.querySelectorAll('input[name="calendar_enabled"]').forEach((input) => input.addEventListener('change', syncCalendar));
            root.querySelectorAll('input[name="age_restricted"]').forEach((input) => input.addEventListener('change', syncAge));
            root.querySelectorAll('input[name="approval_mode"]').forEach((input) => input.addEventListener('change', syncApproval));
            root.querySelectorAll('.business-check-card input[type="checkbox"]').forEach((input) => input.addEventListener('change', syncChecks));
            syncCalendar();
            syncAge();
            syncApproval();
            syncChecks();
        })();
        </script>

        <details class="card onboarding-history-card onboarding-history-details">
            <summary><span><strong>Histórico da ativação</strong><small>Auditoria das alterações realizadas</small></span><em>Ver histórico</em></summary>
            <div class="security-timeline onboarding-history-body">
                <?php foreach ($events as $event): ?>
                    <div class="timeline-item"><strong><?= View::e($event['message'] ?? $event['event'] ?? '') ?></strong><span><?= View::e($event['user_name'] ?? 'Sistema') ?> · <?= View::e($event['created_at'] ?? '') ?></span></div>
                <?php endforeach; ?>
                <?php if (!$events): ?><div class="empty-state">Nenhuma atividade registrada ainda.</div><?php endif; ?>
            </div>
        </details>
    </main>
</div>
