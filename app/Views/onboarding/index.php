<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$guide = $guide ?? [];
$summary = $guide['summary'] ?? ['percent' => 0, 'done' => 0, 'total' => 7, 'pending' => 0, 'attention' => 0, 'blocked' => 0];
$steps = $guide['steps'] ?? [];
$next = $guide['next'] ?? null;
$instances = $guide['instances'] ?? ($instances ?? []);
$agents = $guide['agents'] ?? ($agents ?? []);
$defaultAgent = $guide['default_agent'] ?? ($agents[0] ?? []);
$preSchedule = $guide['pre_schedule'] ?? [];
$events = $guide['events'] ?? [];
$company = $guide['tenant'] ?? ($company ?? []);
$percent = max(0, min(100, (int) ($summary['percent'] ?? 0)));
$statusText = $summary['is_complete'] ?? false ? 'Configuração concluída' : ($next ? 'Próxima etapa: ' . ($next['short'] ?? $next['title']) : 'Em andamento');

$hours = ['start' => '08:00', 'end' => '18:00', 'days' => ['mon', 'tue', 'wed', 'thu', 'fri']];
if (!empty($defaultAgent['business_hours_json'])) {
    $decoded = json_decode((string) $defaultAgent['business_hours_json'], true);
    if (is_array($decoded)) {
        $hours = array_merge($hours, $decoded);
    }
}
$dayLabels = ['mon' => 'Seg', 'tue' => 'Ter', 'wed' => 'Qua', 'thu' => 'Qui', 'fri' => 'Sex', 'sat' => 'Sáb', 'sun' => 'Dom'];
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
?>

<section class="hero-card onboarding-guide-hero">
    <div>
        <span class="eyebrow">Primeiros passos</span>
        <h2><?= ($summary['is_complete'] ?? false) ? 'Sua operação está pronta para uso.' : 'Configure sua operação com orientação.' ?></h2>
        <p>Complete as etapas centrais: empresa, WhatsApp, IA, atendimento, agenda, LGPD e teste final. O painel RS acompanha esse progresso pela implantação.</p>
    </div>
    <div class="onboarding-score-card">
        <strong><?= $percent ?>%</strong>
        <span><?= View::e($statusText) ?></span>
        <div class="onboarding-score-bar"><i style="width: <?= $percent ?>%"></i></div>
    </div>
</section>

<div class="report-kpi-grid onboarding-kpis">
    <article class="card report-kpi"><span>Concluídas</span><strong><?= (int) ($summary['done'] ?? 0) ?></strong><small>de <?= (int) ($summary['total'] ?? count($steps)) ?> etapas</small></article>
    <article class="card report-kpi"><span>Pendentes</span><strong><?= (int) ($summary['pending'] ?? 0) ?></strong><small>faltam revisar</small></article>
    <article class="card report-kpi"><span>Atenções</span><strong><?= (int) ($summary['attention'] ?? 0) ?></strong><small>exigem correção</small></article>
    <article class="card report-kpi"><span>Bloqueadas</span><strong><?= (int) ($summary['blocked'] ?? 0) ?></strong><small>dependem de etapa anterior</small></article>
</div>

<div class="onboarding-guide-layout">
    <aside class="card onboarding-steps-card">
        <div class="section-heading compact-heading">
            <div><span class="eyebrow">Roteiro</span><h2>Etapas</h2></div>
        </div>
        <div class="onboarding-step-list">
            <?php foreach ($steps as $step): ?>
                <a class="onboarding-step-link <?= View::e($statusClass((string) $step['status'])) ?>" href="#<?= View::e($step['key']) ?>">
                    <span class="step-bullet"><?= View::e($statusIcon((string) $step['status'])) ?></span>
                    <span><strong><?= View::e($step['short'] ?? $step['title']) ?></strong><small><?= View::e($step['status_label'] ?? '') ?></small></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="onboarding-next-box">
            <span class="eyebrow">Próxima ação</span>
            <?php if ($next): ?>
                <strong><?= View::e($next['title']) ?></strong>
                <p><?= View::e($next['message'] ?? $next['description'] ?? '') ?></p>
                <a class="btn btn-primary btn-block" href="#<?= View::e($next['key']) ?>">Ir para etapa</a>
            <?php else: ?>
                <strong>Tudo revisado</strong>
                <p>As etapas centrais estão finalizadas.</p>
            <?php endif; ?>
        </div>
    </aside>

    <main class="onboarding-main-flow">
        <?php foreach ($steps as $step): ?>
            <section class="card onboarding-step-panel <?= View::e($statusClass((string) $step['status'])) ?>" id="<?= View::e($step['key']) ?>">
                <div class="onboarding-step-head">
                    <div class="step-number"><?= (int) $step['index'] ?></div>
                    <div>
                        <span class="eyebrow"><?= View::e($step['subtitle'] ?? '') ?></span>
                        <h2><?= View::e($step['title']) ?></h2>
                        <p><?= View::e($step['description'] ?? '') ?></p>
                    </div>
                    <span class="badge <?= View::e($step['status_badge'] ?? 'badge-warning') ?>"><?= View::e($step['status_label'] ?? 'Pendente') ?></span>
                </div>
                <div class="onboarding-step-message">
                    <strong>Status atual:</strong> <?= View::e($step['message'] ?? '') ?>
                </div>

                <?php if ($step['key'] === 'company_profile'): ?>
                    <form class="wizard-card onboarding-inline-form" method="post" action="<?= View::e(Router::url('/onboarding/company')) ?>">
                        <?= Csrf::input() ?>
                        <div class="form-grid two">
                            <label class="field"><span>Nome de exibição</span><input name="name" value="<?= View::e($company['name'] ?? '') ?>" required></label>
                            <label class="field"><span>Razão social</span><input name="legal_name" value="<?= View::e($company['legal_name'] ?? '') ?>"></label>
                            <label class="field"><span>CNPJ/CPF</span><input name="document" value="<?= View::e($company['document'] ?? '') ?>"></label>
                            <label class="field"><span>Segmento</span><input name="segment" value="<?= View::e($company['segment'] ?? '') ?>" placeholder="Ex.: psicologia, clínica, consultoria" required></label>
                            <label class="field"><span>E-mail comercial</span><input type="email" name="email" value="<?= View::e($company['email'] ?? '') ?>"></label>
                            <label class="field"><span>Telefone</span><input name="phone" value="<?= View::e($company['phone'] ?? '') ?>"></label>
                        </div>
                        <label class="field"><span>Site</span><input type="url" name="website" value="<?= View::e($company['website'] ?? '') ?>" placeholder="https://empresa.com.br"></label>
                        <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar empresa</button></div>
                    </form>
                <?php elseif ($step['key'] === 'whatsapp_connection'): ?>
                    <div class="onboarding-action-grid">
                        <div>
                            <h3>Instâncias cadastradas</h3>
                            <?php foreach ($instances as $instance): ?>
                                <div class="mini-status-row"><strong><?= View::e($instance['name'] ?? $instance['instance_name'] ?? 'WhatsApp') ?></strong><span class="badge <?= ($instance['status'] ?? '') === 'connected' ? 'badge-success' : 'badge-warning' ?>"><?= View::e($instance['status'] ?? 'pendente') ?></span></div>
                            <?php endforeach; ?>
                            <?php if (!$instances): ?><div class="empty-state compact-empty">Nenhuma instância cadastrada.</div><?php endif; ?>
                        </div>
                        <div class="onboarding-actions-box">
                            <a class="btn btn-primary btn-block" href="<?= View::e(Router::url('/instances')) ?>">Abrir instâncias</a>
                            <form method="post" action="<?= View::e(Router::url('/onboarding/step')) ?>">
                                <?= Csrf::input() ?><input type="hidden" name="step_key" value="whatsapp_connection"><input type="hidden" name="status" value="complete">
                                <input type="hidden" name="notes" value="WhatsApp validado manualmente no onboarding.">
                                <button class="btn btn-secondary btn-block" type="submit">Marcar WhatsApp validado</button>
                            </form>
                        </div>
                    </div>
                <?php elseif ($step['key'] === 'ai_agent'): ?>
                    <div class="onboarding-action-grid">
                        <div>
                            <h3>Agentes IA</h3>
                            <?php foreach ($agents as $agent): ?>
                                <div class="mini-status-row"><strong><?= View::e($agent['name'] ?? 'Agente IA') ?></strong><span class="badge <?= ($agent['status'] ?? '') === 'active' ? 'badge-success' : 'badge-warning' ?>"><?= View::e($agent['status'] ?? 'pendente') ?></span></div>
                            <?php endforeach; ?>
                            <?php if (!$agents): ?><div class="empty-state compact-empty">Nenhum agente criado.</div><?php endif; ?>
                        </div>
                        <div class="onboarding-actions-box">
                            <a class="btn btn-primary btn-block" href="<?= View::e(Router::url('/agents')) ?>">Abrir agentes IA</a>
                            <form method="post" action="<?= View::e(Router::url('/onboarding/step')) ?>">
                                <?= Csrf::input() ?><input type="hidden" name="step_key" value="ai_agent"><input type="hidden" name="status" value="complete">
                                <input type="hidden" name="notes" value="Agente IA validado manualmente no onboarding.">
                                <button class="btn btn-secondary btn-block" type="submit">Marcar IA validada</button>
                            </form>
                        </div>
                    </div>
                <?php elseif ($step['key'] === 'attendance_rules'): ?>
                    <form class="wizard-card onboarding-inline-form" method="post" action="<?= View::e(Router::url('/onboarding/attendance')) ?>">
                        <?= Csrf::input() ?>
                        <label class="field"><span>Agente principal</span><select name="agent_id" <?= !$agents ? 'disabled' : '' ?>><?php foreach ($agents as $agent): ?><option value="<?= (int) $agent['id'] ?>" <?= (int) ($defaultAgent['id'] ?? 0) === (int) $agent['id'] ? 'selected' : '' ?>><?= View::e($agent['name'] ?? 'Agente IA') ?></option><?php endforeach; ?></select></label>
                        <div class="form-grid two">
                            <label class="field"><span>Início do atendimento</span><input type="time" name="start_time" value="<?= View::e($hours['start'] ?? '08:00') ?>"></label>
                            <label class="field"><span>Fim do atendimento</span><input type="time" name="end_time" value="<?= View::e($hours['end'] ?? '18:00') ?>"></label>
                        </div>
                        <div class="field"><span>Dias de atendimento</span><div class="inline-checks"><?php foreach ($dayLabels as $key => $label): ?><label><input type="checkbox" name="days[]" value="<?= View::e($key) ?>" <?= in_array($key, (array) ($hours['days'] ?? []), true) ? 'checked' : '' ?>> <?= View::e($label) ?></label><?php endforeach; ?></div></div>
                        <label class="field"><span>Mensagem fora de horário</span><textarea name="after_hours_message" rows="3"><?= View::e($defaultAgent['after_hours_message'] ?? 'No momento estamos fora do horário de atendimento. Assim que possível, nossa equipe retorna o contato.') ?></textarea></label>
                        <label class="field"><span>Mensagem de encaminhamento humano</span><textarea name="human_handoff_message" rows="3"><?= View::e($defaultAgent['human_handoff_message'] ?? 'Vou encaminhar sua solicitação para uma pessoa da equipe continuar o atendimento.') ?></textarea></label>
                        <label class="field"><span>Cooldown da IA em segundos</span><input type="number" name="cooldown_seconds" min="0" max="3600" value="<?= (int) ($defaultAgent['cooldown_seconds'] ?? 10) ?>"></label>
                        <div class="form-actions"><button class="btn btn-primary" type="submit" <?= !$agents ? 'disabled' : '' ?>>Salvar atendimento</button></div>
                    </form>
                <?php elseif ($step['key'] === 'agenda_setup'): ?>
                    <form class="wizard-card onboarding-inline-form" method="post" action="<?= View::e(Router::url('/onboarding/agenda')) ?>">
                        <?= Csrf::input() ?>
                        <div class="inline-checks stacked-checks">
                            <label><input type="checkbox" name="enabled" value="1" <?= (int) ($preSchedule['enabled'] ?? 0) === 1 ? 'checked' : '' ?>> Usar agenda/pré-agendamento nesta empresa</label>
                            <label><input type="checkbox" name="require_human_approval" value="1" <?= (int) ($preSchedule['require_human_approval'] ?? 1) === 1 ? 'checked' : '' ?>> Exigir aprovação humana</label>
                            <label><input type="checkbox" name="ai_can_suggest_slots" value="1" <?= (int) ($preSchedule['ai_can_suggest_slots'] ?? 1) === 1 ? 'checked' : '' ?>> IA pode sugerir disponibilidade</label>
                            <label><input type="checkbox" name="ai_can_confirm" value="1" <?= (int) ($preSchedule['ai_can_confirm'] ?? 0) === 1 ? 'checked' : '' ?>> IA pode confirmar sozinha</label>
                        </div>
                        <label class="field"><span>Duração padrão em minutos</span><input type="number" name="default_duration_minutes" min="15" max="240" value="<?= (int) ($preSchedule['default_duration_minutes'] ?? 50) ?>"></label>
                        <label class="field"><span>Mensagem para coletar dia/horário</span><textarea name="collect_message" rows="3"><?= View::e($preSchedule['collect_message'] ?? 'Certo. Me informe, por favor, o melhor dia e período ou horário para atendimento.') ?></textarea></label>
                        <label class="field"><span>Mensagem após registrar preferência</span><textarea name="default_message" rows="3"><?= View::e($preSchedule['default_message'] ?? 'Vou registrar sua preferência e encaminhar para confirmação.') ?></textarea></label>
                        <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar agenda</button><button class="btn btn-secondary" name="enabled" value="0" type="submit">Dispensar agenda</button></div>
                    </form>
                <?php elseif ($step['key'] === 'lgpd_acceptance'): ?>
                    <div class="onboarding-action-grid">
                        <div>
                            <h3>Privacidade e termos</h3>
                            <p class="muted-text">Acesse a central LGPD para revisar política, termo e aceite obrigatório da empresa.</p>
                        </div>
                        <div class="onboarding-actions-box">
                            <a class="btn btn-primary btn-block" href="<?= View::e(Router::url('/privacy')) ?>">Abrir Privacidade/LGPD</a>
                            <form method="post" action="<?= View::e(Router::url('/onboarding/step')) ?>">
                                <?= Csrf::input() ?><input type="hidden" name="step_key" value="lgpd_acceptance"><input type="hidden" name="status" value="complete">
                                <input type="hidden" name="notes" value="LGPD e termos revisados manualmente no onboarding.">
                                <button class="btn btn-secondary btn-block" type="submit">Marcar LGPD revisada</button>
                            </form>
                        </div>
                    </div>
                <?php elseif ($step['key'] === 'final_test'): ?>
                    <div class="onboarding-action-grid">
                        <div>
                            <h3>Teste operacional final</h3>
                            <p class="muted-text">Faça um teste de conversa no WhatsApp, confirme IA, pausa humana, agenda quando aplicável e aceite LGPD.</p>
                            <div class="onboarding-final-links"><a href="<?= View::e(Router::url('/conversations')) ?>">Conversas</a><a href="<?= View::e(Router::url('/calendar')) ?>">Agenda</a><a href="<?= View::e(Router::url('/subscription')) ?>">Assinatura</a></div>
                        </div>
                        <form class="onboarding-actions-box" method="post" action="<?= View::e(Router::url('/onboarding/final-test')) ?>">
                            <?= Csrf::input() ?>
                            <label class="field"><span>Observação do teste</span><textarea name="notes" rows="4" placeholder="Ex.: WhatsApp enviou e recebeu, IA respondeu, agenda validada."></textarea></label>
                            <button class="btn btn-primary btn-block" type="submit">Finalizar onboarding</button>
                        </form>
                    </div>
                <?php endif; ?>

                <details class="onboarding-manual-details">
                    <summary>Ajuste manual desta etapa</summary>
                    <form method="post" action="<?= View::e(Router::url('/onboarding/step')) ?>" class="manual-step-form">
                        <?= Csrf::input() ?>
                        <input type="hidden" name="step_key" value="<?= View::e($step['key']) ?>">
                        <label class="field"><span>Status manual</span><select name="status"><option value="auto" <?= ($step['manual_status'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Automático</option><option value="pending">Pendente</option><option value="complete">Concluído</option><option value="skipped">Dispensado</option><option value="attention">Atenção</option></select></label>
                        <label class="field"><span>Notas</span><textarea name="notes" rows="2"><?= View::e($step['notes'] ?? '') ?></textarea></label>
                        <button class="btn btn-secondary" type="submit">Salvar ajuste</button>
                    </form>
                </details>
            </section>
        <?php endforeach; ?>

        <section class="card onboarding-history-card">
            <div class="section-heading"><div><span class="eyebrow">Histórico</span><h2>Atividades do onboarding</h2></div></div>
            <div class="security-timeline">
                <?php foreach ($events as $event): ?>
                    <div class="timeline-item"><strong><?= View::e($event['message'] ?? $event['event'] ?? '') ?></strong><span><?= View::e($event['user_name'] ?? 'Sistema') ?> · <?= View::e($event['created_at'] ?? '') ?></span></div>
                <?php endforeach; ?>
                <?php if (!$events): ?><div class="empty-state">Nenhuma atividade registrada ainda.</div><?php endif; ?>
            </div>
        </section>
    </main>
</div>
