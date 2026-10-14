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

<nav class="onboarding-phase-strip" aria-label="Etapas da ativação assistida">
    <?php foreach ($activationPhases as $phaseIndex => $phase): ?>
        <a class="onboarding-phase-card is-<?= View::e((string) $phase['status']) ?>" href="<?= View::e(Router::url('/onboarding?step=' . $phase['anchor'])) ?>#<?= View::e((string) $phase['anchor']) ?>">
            <span class="onboarding-phase-number"><?= $phaseIndex + 1 ?></span>
            <span class="onboarding-phase-copy"><strong><?= View::e((string) $phase['label']) ?></strong><small><?= View::e((string) $phase['help']) ?></small></span>
            <span class="onboarding-phase-progress"><?= (int) $phase['done'] ?>/<?= (int) $phase['total'] ?></span>
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
    <aside class="card onboarding-steps-card onboarding-ready-summary">
        <div class="section-heading compact-heading">
            <div><span class="eyebrow">Resumo</span><h2>O que já está pronto</h2></div>
        </div>
        <div class="onboarding-ready-list">
            <?php foreach ($steps as $summaryStep): ?>
                <?php $summaryDone = in_array((string) ($summaryStep['status'] ?? ''), ['complete', 'skipped'], true); ?>
                <?php if (!$summaryDone) continue; ?>
                <a href="<?= View::e(Router::url('/onboarding?step=' . ($summaryStep['key'] ?? ''))) ?>#<?= View::e((string) ($summaryStep['key'] ?? '')) ?>">
                    <span>✓</span><strong><?= View::e((string) ($summaryStep['short'] ?? $summaryStep['title'] ?? 'Etapa')) ?></strong><small>Editar</small>
                </a>
            <?php endforeach; ?>
            <?php if ((int) ($summary['done'] ?? 0) === 0): ?><p class="muted-text">Ainda não há etapas concluídas. Comece pela tarefa ao lado.</p><?php endif; ?>
        </div>
        <div class="onboarding-simple-note"><strong>Configuração expressa</strong><span>Opções técnicas ficam recolhidas em “Ajustes avançados” e já vêm com valores recomendados.</span></div>
    </aside>

    <main class="onboarding-main-flow">
        <?php foreach ($steps as $step): ?>
            <?php
            if ((string) ($step['key'] ?? '') !== $activeStepKey) continue;
            $stepDone = in_array((string) ($step['status'] ?? ''), ['complete', 'skipped'], true);
            $stepCurrent = true;
            $stepAccessible = (string) ($step['status'] ?? '') !== 'blocked' || !empty($summary['is_complete']);
            ?>
            <section class="card onboarding-step-panel <?= View::e($statusClass((string) $step['status'])) ?><?= $stepCurrent ? ' is-current' : '' ?><?= !$stepAccessible ? ' is-locked' : '' ?>" id="<?= View::e($step['key']) ?>">
                <div class="onboarding-step-head">
                    <div class="step-number"><?= (int) $activePhaseNumber ?></div>
                    <div>
                        <span class="eyebrow">Bloco <?= (int) $activePhaseNumber ?> de 4 · <?= View::e($activePhaseLabel) ?></span>
                        <h2><?= View::e($step['title']) ?></h2>
                        <p><?= View::e($step['description'] ?? '') ?></p>
                    </div>
                    <span class="badge <?= View::e($step['status_badge'] ?? 'badge-warning') ?>"><?= View::e($step['status_label'] ?? 'Pendente') ?></span>
                </div>
                <div class="onboarding-step-message">
                    <strong>Status atual:</strong> <?= View::e($step['message'] ?? '') ?>
                </div>

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
                <?php elseif ($step['key'] === 'attendance_rules'): ?>
                    <form class="wizard-card onboarding-inline-form" method="post" action="<?= View::e(Router::url('/onboarding/attendance')) ?>">
                        <?= Csrf::input() ?>
                        <div class="onboarding-pre-agent-note"><strong>Defina quando sua equipe atende.</strong><span>Mensagens e parâmetros técnicos já vêm preenchidos com valores recomendados e podem ser ajustados somente se necessário.</span></div>
                        <section class="onboarding-essential-card">
                            <div class="section-heading compact"><div><span class="eyebrow">Essencial</span><h3>Dias e horário de atendimento</h3><p>Essas informações controlam quando o agente considera a empresa aberta ou fora do expediente.</p></div></div>
                            <div class="form-grid two">
                                <label class="field"><span>Início do atendimento</span><input type="time" name="start_time" value="<?= View::e($hours['start'] ?? '08:00') ?>"></label>
                                <label class="field"><span>Fim do atendimento</span><input type="time" name="end_time" value="<?= View::e($hours['end'] ?? '18:00') ?>"></label>
                            </div>
                            <div class="field"><span>Dias de atendimento</span><div class="inline-checks onboarding-day-picker"><?php foreach ($dayLabels as $key => $label): ?><label><input type="checkbox" name="days[]" value="<?= View::e($key) ?>" <?= in_array($key, (array) ($hours['days'] ?? []), true) ? 'checked' : '' ?>> <?= View::e($label) ?></label><?php endforeach; ?></div></div>
                        </section>

                        <details class="onboarding-simple-details">
                            <summary><span><strong>Ajustes avançados</strong><small>Mensagens, SLA, fuso horário e tempo de agrupamento</small></span><em>Opcional</em></summary>
                            <div class="onboarding-simple-details-body">
                                <label class="field"><span>Fuso horário</span><input name="business_timezone" value="<?= View::e($attendanceSettings['business_timezone'] ?? ($defaultAgent['business_timezone'] ?? 'America/Sao_Paulo')) ?>"></label>
                                <div class="onboarding-sla-policy" data-sla-policy>
                                    <div class="section-heading compact onboarding-sla-heading">
                                        <div><span class="eyebrow">SLA operacional</span><h3>Primeira resposta humana</h3><p>Ative somente se sua equipe quiser medir e receber alertas sobre tempo de resposta.</p></div>
                                        <label class="onboarding-enable-toggle">
                                            <input type="hidden" name="sla_enabled" value="0">
                                            <input type="checkbox" name="sla_enabled" value="1" data-sla-enabled <?= $slaEnabled ? 'checked' : '' ?>>
                                            <span><strong>Medir SLA</strong><small><?= $slaEnabled ? 'Ativado' : 'Desativado' ?></small></span>
                                        </label>
                                    </div>
                                    <div class="form-grid three onboarding-sla-fields" data-sla-fields <?= $slaEnabled ? '' : 'hidden' ?>>
                                        <label class="field"><span>Meta da 1ª resposta</span><div class="team-report-sla-input"><input type="number" name="sla_target_minutes" min="5" max="1440" step="5" value="<?= (int) ($slaSettings['target_minutes'] ?? 30) ?>"><small>min</small></div></label>
                                        <label class="field"><span>Alerta preventivo</span><div class="team-report-sla-input"><input type="number" name="sla_warning_percent" min="50" max="99" step="1" value="<?= (int) ($slaSettings['warning_percent'] ?? 80) ?>"><small>%</small></div></label>
                                        <label class="field checkbox-field sla-clock-toggle"><span>Relógio fora do expediente</span><span class="checkbox-control"><input type="checkbox" name="sla_count_outside_hours" value="1" <?= !empty($slaSettings['count_outside_business_hours']) ? 'checked' : '' ?>><span>Contabilizar também fora do horário</span></span></label>
                                    </div>
                                </div>
                                <label class="field"><span>Mensagem fora de horário</span><textarea name="after_hours_message" rows="3"><?= View::e($attendanceSettings['after_hours_message'] ?? ($defaultAgent['after_hours_message'] ?? 'No momento estamos fora do horário de atendimento. Assim que possível, nossa equipe retorna o contato.')) ?></textarea></label>
                                <label class="field"><span>Mensagem de encaminhamento humano</span><textarea name="human_handoff_message" rows="3"><?= View::e($attendanceSettings['human_handoff_message'] ?? ($defaultAgent['human_handoff_message'] ?? 'Vou encaminhar sua solicitação para uma pessoa da equipe continuar o atendimento.')) ?></textarea></label>
                                <label class="field"><span>Tempo de espera da IA (seg.)</span><input type="number" name="cooldown_seconds" min="0" max="3600" value="<?= (int) ($attendanceSettings['cooldown_seconds'] ?? ($defaultAgent['cooldown_seconds'] ?? 60)) ?>"><small class="field-hint">Agrupa mensagens enviadas em sequência antes de responder.</small></label>
                            </div>
                        </details>
                        <div class="form-actions"><button class="btn btn-primary" type="submit">Continuar</button></div>
                    </form>
                <?php elseif ($step['key'] === 'agenda_setup'): ?>
                    <form class="wizard-card onboarding-inline-form onboarding-calendar-form" method="post" action="<?= View::e(Router::url('/onboarding/agenda')) ?>" data-calendar-onboarding>
                        <?= Csrf::input() ?>
                        <div class="onboarding-pre-agent-note"><strong>Escolha a origem da disponibilidade</strong><span>Essa decisão define se os horários serão controlados pelo próprio RS Connect, por uma integração homologada pela RS ou se a empresa não utilizará agenda.</span></div>

                        <div class="calendar-mode-grid" role="radiogroup" aria-label="Tipo de agenda">
                            <label class="calendar-mode-card <?= $calendarMode === 'none' ? 'is-selected' : '' ?>">
                                <input type="radio" name="calendar_mode" value="none" <?= $calendarMode === 'none' ? 'checked' : '' ?>>
                                <span class="calendar-mode-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 3v3M18 3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z"/><path d="m8 13 8 8M16 13l-8 8"/></svg></span>
                                <span><strong>Não utilizar agenda</strong><small>A IA atende normalmente, mas não consulta nem registra horários.</small></span>
                            </label>
                            <label class="calendar-mode-card <?= $calendarMode === 'internal' ? 'is-selected' : '' ?>">
                                <input type="radio" name="calendar_mode" value="internal" <?= $calendarMode === 'internal' ? 'checked' : '' ?>>
                                <span class="calendar-mode-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 3v3M18 3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z"/><path d="m8 14 2 2 5-5"/></svg></span>
                                <span><strong>Agenda interna do RS Connect</strong><small>Disponibilidade, bloqueios e compromissos ficam no banco da plataforma, sem n8n e sem Google Calendar.</small></span>
                            </label>
                            <label class="calendar-mode-card <?= $calendarMode === 'smart' ? 'is-selected' : '' ?> <?= !$smartCalendarReady ? 'is-locked' : '' ?>">
                                <input type="radio" name="calendar_mode" value="smart" <?= $calendarMode === 'smart' ? 'checked' : '' ?> <?= !$smartCalendarReady ? 'disabled' : '' ?>>
                                <span class="calendar-mode-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 3v3M18 3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z"/><path d="M12 11v6M9 14h6"/></svg></span>
                                <span><strong>Agenda inteligente integrada</strong><small><?= $smartCalendarReady ? 'Integração técnica homologada e liberada pela equipe RS Connect.' : ($smartCalendarStatus === 'configuring' ? 'A integração está sendo preparada pela equipe RS Connect.' : 'Disponível somente após liberação e homologação do Super Admin.') ?></small></span>
                                <?php if (!$smartCalendarReady): ?><em class="badge badge-warning"><?= $smartCalendarStatus === 'configuring' ? 'Em configuração' : 'Não liberada' ?></em><?php endif; ?>
                            </label>
                        </div>

                        <section class="calendar-mode-panel" data-calendar-mode-panel="internal" <?= $calendarMode === 'internal' ? '' : 'hidden' ?>>
                            <div class="section-heading compact"><div><span class="eyebrow">Agenda interna</span><h3>Como seus horários funcionam?</h3><p>Por padrão, usamos os mesmos dias e horários informados na etapa anterior. Ajuste somente se a agenda funcionar de outro jeito.</p></div><span class="badge badge-success">Sem integração externa</span></div>

                            <div class="onboarding-choice-grid" role="radiogroup" aria-label="Capacidade por horário">
                                <label class="onboarding-choice-card <?= $bookingCapacityMode === 'single' ? 'is-selected' : '' ?>">
                                    <input type="radio" name="booking_capacity_mode" value="single" <?= $bookingCapacityMode === 'single' ? 'checked' : '' ?>>
                                    <span><strong>Uma pessoa por horário</strong><small>Modelo padrão para consultas e serviços individuais.</small></span>
                                </label>
                                <label class="onboarding-choice-card <?= $bookingCapacityMode === 'capacity' ? 'is-selected' : '' ?>">
                                    <input type="radio" name="booking_capacity_mode" value="capacity" <?= $bookingCapacityMode === 'capacity' ? 'checked' : '' ?>>
                                    <span><strong>Mais de uma pessoa no mesmo horário</strong><small>Ideal para pilates, turmas e atendimentos com capacidade.</small></span>
                                </label>
                            </div>
                            <label class="field onboarding-capacity-field" data-capacity-field <?= $bookingCapacityMode === 'capacity' ? '' : 'hidden' ?>><span>Quantas pessoas por horário?</span><input type="number" name="default_slot_capacity" min="2" max="100" value="<?= (int) $defaultSlotCapacity ?>"><small>Ex.: capacidade 4 → o horário permanece disponível até chegar a 4/4.</small></label>

                            <details class="onboarding-simple-details">
                                <summary><span><strong>Ajustar dias e horários da agenda</strong><small>Use somente se forem diferentes do horário de atendimento</small></span><em>Opcional</em></summary>
                                <div class="onboarding-simple-details-body">
                                    <div class="internal-calendar-days">
                                        <?php foreach ($calendarDayLabels as $dayNumber => $dayLabel): ?>
                                            <?php $dayConfig = $internalHoursByDay[(string) $dayNumber]; ?>
                                            <div class="internal-calendar-day">
                                                <label class="internal-day-toggle"><input type="checkbox" name="internal_days[]" value="<?= $dayNumber ?>" <?= !empty($dayConfig['enabled']) ? 'checked' : '' ?>><span><?= View::e($dayLabel) ?></span></label>
                                                <label class="field"><span>Início</span><input type="time" name="internal_start[<?= $dayNumber ?>]" value="<?= View::e((string) ($dayConfig['start'] ?? '08:00')) ?>"></label>
                                                <label class="field"><span>Fim</span><input type="time" name="internal_end[<?= $dayNumber ?>]" value="<?= View::e((string) ($dayConfig['end'] ?? '18:00')) ?>"></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </details>
                        </section>

                        <section class="calendar-mode-panel" data-calendar-mode-panel="smart" <?= $calendarMode === 'smart' ? '' : 'hidden' ?>>
                            <div class="message-info"><strong>Integração administrada pela RS Connect</strong><span>Credenciais e integrações são preparadas pela equipe RS. Você define apenas duração, antecedência e quem confirma o horário.</span></div>
                        </section>

                        <section class="calendar-commercial-rules" data-calendar-commercial-rules <?= $calendarMode === 'none' ? 'hidden' : '' ?>>
                            <div class="section-heading compact"><div><span class="eyebrow">Regras do negócio</span><h3>Como o agendamento deve funcionar?</h3><p>Estas são as decisões que realmente mudam o comportamento do agente.</p></div></div>
                            <div class="form-grid two">
                                <label class="field"><span>Duração padrão</span><div class="input-with-suffix"><input type="number" name="default_duration_minutes" min="15" max="240" value="<?= (int) ($calendarAvailability['default_duration_minutes'] ?? $preSchedule['default_duration_minutes'] ?? 60) ?>"><span>min</span></div></label>
                                <label class="field"><span>Antecedência mínima</span><div class="input-with-suffix"><input type="number" name="min_notice_hours" min="0" max="720" value="<?= (int) ($calendarAvailability['min_notice_hours'] ?? 2) ?>"><span>hora(s)</span></div><small>Quanto tempo antes o cliente precisa solicitar.</small></label>
                            </div>
                            <div class="onboarding-choice-grid" role="radiogroup" aria-label="Confirmação do agendamento">
                                <label class="onboarding-choice-card <?= (int) ($preSchedule['require_human_approval'] ?? 1) === 1 ? 'is-selected' : '' ?>">
                                    <input type="radio" name="require_human_approval" value="1" <?= (int) ($preSchedule['require_human_approval'] ?? 1) === 1 ? 'checked' : '' ?>>
                                    <span><strong>A equipe confirma</strong><small>O agente faz a pré-reserva e aguarda aprovação humana.</small></span>
                                </label>
                                <label class="onboarding-choice-card <?= (int) ($preSchedule['require_human_approval'] ?? 1) === 0 ? 'is-selected' : '' ?>">
                                    <input type="radio" name="require_human_approval" value="0" <?= (int) ($preSchedule['require_human_approval'] ?? 1) === 0 ? 'checked' : '' ?>>
                                    <span><strong>Confirmar automaticamente</strong><small>Somente quando a disponibilidade real estiver validada.</small></span>
                                </label>
                            </div>
                            <input type="hidden" name="ai_can_suggest_slots" value="1">
                            <input type="hidden" name="ai_can_confirm" value="1">

                            <details class="onboarding-simple-details">
                                <summary><span><strong>Ajustes avançados da agenda</strong><small>Intervalos, janela de busca e textos enviados ao cliente</small></span><em>Opcional</em></summary>
                                <div class="onboarding-simple-details-body">
                                    <div class="form-grid three">
                                        <label class="field"><span>Intervalo entre opções</span><input type="number" name="slot_interval_minutes" min="5" max="240" value="<?= (int) ($calendarAvailability['slot_interval_minutes'] ?? 30) ?>"><small>Ex.: opções a cada 30 minutos.</small></label>
                                        <label class="field"><span>Intervalo de segurança</span><input type="number" name="buffer_minutes" min="0" max="180" value="<?= (int) ($calendarAvailability['buffer_minutes'] ?? 0) ?>"><small>Margem entre atendimentos.</small></label>
                                        <label class="field"><span>Dias para pesquisar</span><input type="number" name="search_days_ahead" min="1" max="90" value="<?= (int) ($calendarAvailability['search_days_ahead'] ?? 30) ?>"></label>
                                        <label class="field"><span>Máximo de sugestões</span><input type="number" name="max_suggestions" min="1" max="20" value="<?= (int) ($calendarAvailability['max_suggestions'] ?? 5) ?>"></label>
                                    </div>
                                    <label class="field"><span>Mensagem para coletar dia/horário</span><textarea name="collect_message" rows="3"><?= View::e($preSchedule['collect_message'] ?? 'Certo. Me informe, por favor, o melhor dia e período ou horário para atendimento.') ?></textarea></label>
                                    <label class="field"><span>Mensagem após registrar preferência</span><textarea name="default_message" rows="3"><?= View::e($preSchedule['default_message'] ?? 'Vou registrar sua preferência e encaminhar para confirmação.') ?></textarea></label>
                                </div>
                            </details>
                        </section>

                        <div class="form-actions"><button class="btn btn-primary" type="submit">Continuar</button></div>
                    </form>
                    <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var form = document.querySelector('[data-calendar-onboarding]');
                        if (!form) return;
                        var refresh = function () {
                            var selected = form.querySelector('input[name="calendar_mode"]:checked');
                            var mode = selected ? selected.value : 'none';
                            form.querySelectorAll('.calendar-mode-card').forEach(function (card) {
                                var input = card.querySelector('input[name="calendar_mode"]');
                                card.classList.toggle('is-selected', !!input && input.checked);
                            });
                            form.querySelectorAll('[data-calendar-mode-panel]').forEach(function (panel) {
                                panel.hidden = panel.getAttribute('data-calendar-mode-panel') !== mode;
                            });
                            var rules = form.querySelector('[data-calendar-commercial-rules]');
                            if (rules) rules.hidden = mode === 'none';
                        };
                        var refreshChoices = function () {
                            form.querySelectorAll('.onboarding-choice-card').forEach(function (card) {
                                var input = card.querySelector('input[type="radio"]');
                                card.classList.toggle('is-selected', !!input && input.checked);
                            });
                            var capacityMode = form.querySelector('input[name="booking_capacity_mode"]:checked');
                            var capacityField = form.querySelector('[data-capacity-field]');
                            if (capacityField) capacityField.hidden = !capacityMode || capacityMode.value !== 'capacity';
                        };
                        form.querySelectorAll('input[name="calendar_mode"]').forEach(function (input) { input.addEventListener('change', refresh); });
                        form.querySelectorAll('.onboarding-choice-card input[type="radio"]').forEach(function (input) { input.addEventListener('change', refreshChoices); });
                        refresh();
                        refreshChoices();
                    });
                    </script>
                <?php elseif ($step['key'] === 'lgpd_acceptance'): ?>
                    <div class="onboarding-action-grid">
                        <div>
                            <h3>Privacidade e termos</h3>
                            <p class="muted-text">Acesse a central LGPD para revisar política, termo e aceite obrigatório da empresa.</p>
                        </div>
                        <div class="onboarding-actions-box">
                            <a class="btn btn-primary btn-block" href="<?= View::e(Router::url('/privacy/accept')) ?>">Ler e aceitar os termos</a>
                            <small class="field-hint">O aceite será registrado com usuário, data, versão da política e informações de auditoria.</small>
                        </div>
                    </div>
                <?php elseif ($step['key'] === 'agent_limits'): ?>
                    <form class="wizard-card onboarding-inline-form" method="post" action="<?= View::e(Router::url('/onboarding/limits')) ?>">
                        <?= Csrf::input() ?>
                        <div class="onboarding-pre-agent-note">
                            <strong>Limites são regras de negócio, não sugestões para a IA.</strong>
                            <span>O RS Connect valida estas situações no Policy Engine antes do modelo responder ou executar uma ação crítica.</span>
                        </div>

                        <section class="onboarding-essential-card">
                            <div class="section-heading compact"><div><span class="eyebrow">Essencial</span><h3>O que o assistente não pode decidir sozinho?</h3><p>Marque as situações que precisam de uma regra. As respostas recomendadas já estão preenchidas.</p></div></div>

                            <div class="onboarding-policy-list">
                                <div class="onboarding-policy-row">
                                    <label class="checkbox-field"><span>Pedido de desconto</span><span class="checkbox-control"><input type="checkbox" name="discount_rule_enabled" value="1" <?= !empty($agentLimits['discount_rule_enabled']) ? 'checked' : '' ?>><span>Exigir regra</span></span></label>
                                    <small>Impede que o agente invente ou conceda desconto sem autorização.</small>
                                    <details class="onboarding-inline-details"><summary>Personalizar resposta</summary><div class="form-grid two"><label class="field"><span>O que fazer</span><select name="discount_action"><option value="handoff" <?= ($agentLimits['discount_action'] ?? 'handoff') === 'handoff' ? 'selected' : '' ?>>Encaminhar para responsável</option><option value="warn" <?= ($agentLimits['discount_action'] ?? '') === 'warn' ? 'selected' : '' ?>>Informar a regra e continuar</option></select></label><label class="field"><span>Resposta</span><textarea name="discount_message" rows="3"><?= View::e($agentLimits['discount_message'] ?? '') ?></textarea></label></div></details>
                                </div>

                                <div class="onboarding-policy-row">
                                    <label class="checkbox-field"><span>Reembolso / estorno</span><span class="checkbox-control"><input type="checkbox" name="refund_rule_enabled" value="1" <?= !empty($agentLimits['refund_rule_enabled']) ? 'checked' : '' ?>><span>Exigir regra</span></span></label>
                                    <small>Evita promessas financeiras que dependem da equipe.</small>
                                    <details class="onboarding-inline-details"><summary>Personalizar resposta</summary><div class="form-grid two"><label class="field"><span>O que fazer</span><select name="refund_action"><option value="handoff" <?= ($agentLimits['refund_action'] ?? 'handoff') === 'handoff' ? 'selected' : '' ?>>Encaminhar para responsável</option><option value="warn" <?= ($agentLimits['refund_action'] ?? '') === 'warn' ? 'selected' : '' ?>>Informar a regra e continuar</option></select></label><label class="field"><span>Resposta</span><textarea name="refund_message" rows="3"><?= View::e($agentLimits['refund_message'] ?? '') ?></textarea></label></div></details>
                                </div>

                                <div class="onboarding-policy-row">
                                    <label class="checkbox-field"><span>Encaixe ou exceção fora da regra</span><span class="checkbox-control"><input type="checkbox" name="exception_rule_enabled" value="1" <?= !empty($agentLimits['exception_rule_enabled']) ? 'checked' : '' ?>><span>Exigir regra</span></span></label>
                                    <small>O agente não abre exceções de agenda ou operação por conta própria.</small>
                                    <details class="onboarding-inline-details"><summary>Personalizar resposta</summary><div class="form-grid two"><label class="field"><span>O que fazer</span><select name="exception_action"><option value="handoff" <?= ($agentLimits['exception_action'] ?? 'handoff') === 'handoff' ? 'selected' : '' ?>>Encaminhar para responsável</option><option value="warn" <?= ($agentLimits['exception_action'] ?? '') === 'warn' ? 'selected' : '' ?>>Informar a regra e continuar</option></select></label><label class="field"><span>Resposta</span><textarea name="exception_message" rows="3"><?= View::e($agentLimits['exception_message'] ?? '') ?></textarea></label></div></details>
                                </div>
                            </div>
                        </section>

                        <section class="onboarding-essential-card">
                            <div class="section-heading compact"><div><span class="eyebrow">Elegibilidade</span><h3>Existe limite de idade?</h3><p>Use 0 quando a empresa atende qualquer idade.</p></div></div>
                            <div class="form-grid three">
                                <label class="field"><span>Idade mínima</span><input type="number" name="minimum_age" min="0" max="130" value="<?= (int) ($agentLimits['minimum_age'] ?? 0) ?>"><small>0 = sem mínimo.</small></label>
                                <label class="field"><span>Idade máxima</span><input type="number" name="maximum_age" min="0" max="130" value="<?= (int) ($agentLimits['maximum_age'] ?? 0) ?>"><small>0 = sem máximo.</small></label>
                                <label class="field"><span>Se estiver fora da faixa</span><select name="age_action"><option value="block_schedule" <?= ($agentLimits['age_action'] ?? 'block_schedule') === 'block_schedule' ? 'selected' : '' ?>>Não permitir agenda</option><option value="handoff" <?= ($agentLimits['age_action'] ?? '') === 'handoff' ? 'selected' : '' ?>>Encaminhar para humano</option><option value="block" <?= ($agentLimits['age_action'] ?? '') === 'block' ? 'selected' : '' ?>>Interromper automação</option></select></label>
                            </div>
                            <details class="onboarding-simple-details"><summary><span><strong>Personalizar respostas de idade</strong><small>Os textos recomendados já funcionam sem alteração.</small></span><em>Opcional</em></summary><div class="onboarding-simple-details-body form-grid two"><label class="field"><span>Abaixo da idade mínima</span><textarea name="minimum_age_message" rows="3"><?= View::e($agentLimits['minimum_age_message'] ?? '') ?></textarea><small>Pode usar <code>{{minimum_age}}</code>.</small></label><label class="field"><span>Acima da idade máxima</span><textarea name="maximum_age_message" rows="3" placeholder="Ex.: Atualmente os atendimentos são realizados até 60 anos."><?= View::e($agentLimits['maximum_age_message'] ?? '') ?></textarea></label></div></details>
                        </section>

                        <details class="onboarding-simple-details">
                            <summary><span><strong>Casos específicos da empresa</strong><small>Serviços não atendidos, convênios, atendimento domiciliar e outras exceções</small></span><em>Opcional</em></summary>
                            <div class="onboarding-simple-details-body">
                                <label class="checkbox-field"><span>Aplicar regra para casos personalizados</span><span class="checkbox-control"><input type="checkbox" name="custom_case_enabled" value="1" <?= !empty($agentLimits['custom_case_enabled']) ? 'checked' : '' ?>><span>Ativar</span></span></label>
                                <div class="form-grid two"><label class="field"><span>Casos / serviços</span><textarea name="custom_case_terms" rows="5" placeholder="Um por linha"><?= View::e($agentLimits['custom_case_terms'] ?? '') ?></textarea><small>Ex.: terapia de casal, convênio X, atendimento domiciliar.</small></label><div><label class="field"><span>O que fazer</span><select name="custom_case_action"><option value="handoff" <?= ($agentLimits['custom_case_action'] ?? 'handoff') === 'handoff' ? 'selected' : '' ?>>Encaminhar para responsável</option><option value="warn" <?= ($agentLimits['custom_case_action'] ?? '') === 'warn' ? 'selected' : '' ?>>Informar a regra e continuar</option><option value="block" <?= ($agentLimits['custom_case_action'] ?? '') === 'block' ? 'selected' : '' ?>>Interromper automação</option></select></label><label class="field"><span>Resposta</span><textarea name="custom_case_message" rows="3"><?= View::e($agentLimits['custom_case_message'] ?? '') ?></textarea></label></div></div>
                            </div>
                        </details>

                        <details class="onboarding-simple-details">
                            <summary><span><strong>Situações urgentes / sensíveis</strong><small>Defina se o fluxo automático deve parar e ir para atendimento humano</small></span><em>Opcional</em></summary>
                            <div class="onboarding-simple-details-body">
                                <label class="checkbox-field"><span>Aplicar protocolo de situação urgente</span><span class="checkbox-control"><input type="checkbox" name="emergency_rule_enabled" value="1" <?= !empty($agentLimits['emergency_rule_enabled']) ? 'checked' : '' ?>><span>Ativar</span></span></label>
                                <div class="form-grid two"><label class="field"><span>O que fazer</span><select name="emergency_action"><option value="handoff" <?= ($agentLimits['emergency_action'] ?? 'handoff') === 'handoff' ? 'selected' : '' ?>>Encaminhar para atendimento humano</option><option value="block" <?= ($agentLimits['emergency_action'] ?? '') === 'block' ? 'selected' : '' ?>>Interromper automação</option><option value="warn" <?= ($agentLimits['emergency_action'] ?? '') === 'warn' ? 'selected' : '' ?>>Enviar orientação e continuar</option></select></label><label class="field"><span>Resposta aprovada</span><textarea name="emergency_message" rows="4"><?= View::e($agentLimits['emergency_message'] ?? '') ?></textarea></label></div>
                            </div>
                        </details>

                        <div class="message-info"><strong>Regra global de segurança</strong><span>O assistente não recebe autorização para inventar desconto, reembolso, exceção ou elegibilidade. Quando uma regra acima é acionada, a decisão estruturada prevalece sobre o texto gerado pela IA.</span></div>
                        <div class="form-actions"><button class="btn btn-primary" type="submit">Continuar</button></div>
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
