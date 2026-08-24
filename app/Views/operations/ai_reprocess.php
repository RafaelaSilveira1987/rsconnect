<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
use App\Services\OperationalLanguageService;

$data = $aiReprocessData ?? [];
$settings = $data['settings'] ?? [];
$pending = $data['pending'] ?? [];
$history = $data['history'] ?? [];
$recentFailures = $data['recent_failures'] ?? [];
$pendingInstances = $data['pending_instances'] ?? [];
$lastSummary = $settings['last_summary'] ?? [];
$blockedLast = (int) ($lastSummary['blocked'] ?? 0);
$pendingBlocked = (int) ($data['pending_blocked_total'] ?? 0);
$afterHours = $data['after_hours'] ?? ['total' => 0, 'blocked_plan' => 0, 'blocked_human' => 0, 'errors' => 0];
$afterHoursItems = $data['after_hours_items'] ?? [];
$formatDate = static function (?string $value): string {
    if (!$value || !($timestamp = strtotime($value))) return 'Ainda não executado';
    return date('d/m/Y H:i', $timestamp);
};
$statusLabel = static function (string $status): string {
    return [
        'success' => 'Concluída',
        'partial' => 'Concluída com atenção',
        'error' => 'Não foi concluída',
        'running' => 'Em execução',
        'skipped' => 'Ignorada',
    ][$status] ?? ucfirst($status ?: 'Sem registro');
};
?>

<?php if (!empty($data['migration_required'])): ?>
<section class="card operations-alert is-warning">
    <strong>Atualização do sistema necessária</strong>
    <p>Execute <code><?= View::e((string) ($data['migration'] ?? 'database/migrations/044_ai_pending_failures_message_link.sql')) ?></code> antes de usar esta rotina.</p>
</section>
<?php else: ?>
<?php if (!empty($data['migration_recommended'])): ?>
<section class="card operations-alert is-warning">
    <strong>Atualização recomendada</strong>
    <p>As pendências já podem ser encontradas, mas execute <code><?= View::e((string) ($data['migration'] ?? 'database/migrations/045_ai_webhook_ingestion_resilience.sql')) ?></code> para vincular cada tentativa à mensagem correta e otimizar a rotina.</p>
</section>
<?php endif; ?>
<section class="admin-module-summary">
    <article class="<?= (int) ($data['pending_total'] ?? 0) > 0 ? 'is-warning' : 'is-success' ?>">
        <span>Respostas automáticas pendentes</span>
        <strong><?= (int) ($data['pending_total'] ?? 0) ?></strong>
        <small><?= $pendingBlocked > 0 ? $pendingBlocked . ' aguardando reconexão do WhatsApp' : 'intervalo de espera ou tentativa não concluída' ?></small>
    </article>
    <article class="<?= !empty($settings['enabled']) ? 'is-success' : 'is-warning' ?>">
        <span>Rotina automática</span>
        <strong><?= !empty($settings['enabled']) ? 'Ativa' : 'Desativada' ?></strong>
        <small><?= View::e((string) ($settings['run_time'] ?? '03:00')) ?> · <?= View::e((string) ($settings['timezone'] ?? 'America/Sao_Paulo')) ?></small>
    </article>
    <article class="is-blue">
        <span>Última execução</span>
        <strong><?= View::e($blockedLast > 0 && (string) ($settings['last_run_status'] ?? '') === 'skipped' ? 'Aguardando conexão' : $statusLabel((string) ($settings['last_run_status'] ?? ''))) ?></strong>
        <small><?= View::e($formatDate($settings['last_run_at'] ?? null)) ?></small>
    </article>
    <article>
        <span>Último resultado</span>
        <strong><?= (int) ($lastSummary['replied'] ?? 0) ?> resposta(s)</strong>
        <small><?= (int) ($lastSummary['attempted'] ?? 0) ?> item(ns) reavaliado(s)<?= $blockedLast > 0 ? ' · ' . $blockedLast . ' bloqueado(s) por conexão' : '' ?></small>
    </article>
    <article class="<?= (int) ($afterHours['errors'] ?? 0) > 0 ? 'is-warning' : ((int) ($afterHours['total'] ?? 0) > 0 ? 'is-blue' : 'is-success') ?>">
        <span>Fora do horário</span>
        <strong><?= (int) ($afterHours['total'] ?? 0) ?> pendência(s)</strong>
        <small><?php if ((int) ($afterHours['blocked_plan'] ?? 0) > 0): ?><?= (int) $afterHours['blocked_plan'] ?> aguardando franquia · <?php endif; ?><?php if ((int) ($afterHours['blocked_human'] ?? 0) > 0): ?><?= (int) $afterHours['blocked_human'] ?> respeitando humano · <?php endif; ?><?= (int) ($afterHours['errors'] ?? 0) ?> com atenção</small>
    </article>
</section>

<section class="card" style="margin-bottom:16px">
    <div class="section-heading"><div><span class="eyebrow">Recursos 36.6.8+</span><h2>Onde validar consumo e continuidade</h2><p>Atalhos para os pontos de franquia, custeio da credencial e recuperação pós-horário.</p></div></div>
    <div class="action-row">
        <a class="btn btn-outline" href="<?= View::e(Router::url('/billing')) ?>">Franquia do assistente virtual</a>
        <a class="btn btn-outline" href="<?= View::e(Router::url('/ai-credentials')) ?>">Responsável pelo assistente virtual</a>
        <a class="btn btn-outline" href="#after-hours-recovery">Mensagens fora do horário</a>
        <a class="btn btn-outline" href="<?= View::e(Router::url('/operacao-alertas')) ?>">Avisos do sistema</a>
    </div>
</section>

<div class="operations-grid">
    <section class="card">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Agendamento geral</span>
                <h2>Retomar respostas automáticas pendentes</h2>
                <p>A rotina percorre todas as empresas e identifica conversas que ficaram sem resposta por intervalo de espera, indisponibilidade do assistente ou desconexão do WhatsApp. Conversas já respondidas não recebem novo envio.</p>
            </div>
        </div>

        <form method="post" action="<?= View::e(Router::url('/operations/ai-reprocess/save')) ?>" class="form-grid two">
            <?= Csrf::input() ?>
            <label class="switch-card field-span-2">
                <input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>>
                <span><strong>Ativar verificação diária</strong><small>A rotina automática pode consultar o sistema várias vezes; a execução ocorre somente uma vez por dia, depois do horário configurado.</small></span>
            </label>
            <label class="field">
                <span>Horário diário</span>
                <input type="time" name="run_time" value="<?= View::e((string) ($settings['run_time'] ?? '03:00')) ?>" required>
            </label>
            <label class="field">
                <span>Fuso horário</span>
                <input name="timezone" value="<?= View::e((string) ($settings['timezone'] ?? 'America/Sao_Paulo')) ?>" required>
                <small class="field-hint">Ex.: America/Sao_Paulo</small>
            </label>
            <label class="field field-span-2">
                <span>Limite de mensagens por execução</span>
                <input type="number" name="max_messages_per_run" min="1" max="1000" value="<?= (int) ($settings['max_messages_per_run'] ?? 100) ?>" required>
                <small class="field-hint">Protege o servidor caso exista um volume anormal de mensagens pendentes.</small>
            </label>
            <div class="field-span-2 action-row">
                <button class="btn btn-primary" type="submit">Salvar rotina</button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Ação imediata</span><h2>Verificar agora</h2></div>
        </div>
        <p>Executa a mesma verificação segura em todas as empresas. Tentativas não concluídas continuam registradas e nenhuma mensagem é repetida quando a conversa já recebeu resposta.</p>
        <form method="post" action="<?= View::e(Router::url('/operations/ai-reprocess/run')) ?>" onsubmit="return confirm('Verificar agora as respostas automáticas pendentes de todas as empresas? Mensagens já respondidas não serão reenviadas.');">
            <?= Csrf::input() ?>
            <button class="btn btn-primary" type="submit">Tentar respostas pendentes agora</button>
        </form>

        <div class="operations-alert <?= !empty($data['cron_token_configured']) ? 'is-ok' : 'is-warning' ?>" style="margin-top:16px">
            <strong>Retomada rápida após o intervalo</strong>
            <p><?= !empty($data['cron_token_configured']) ? 'A retomada rápida está configurada. Mantenha a automação ativa para continuar as conversas quando o intervalo terminar.' : 'A retomada rápida ainda precisa ser configurada pela equipe técnica.' ?></p>
            <small>Endereço técnico da retomada: <code><?= View::e((string) ($data['queue_url'] ?? '')) ?></code></small><br>
            <small>Endereço técnico da verificação diária: <code><?= View::e((string) ($data['cron_url'] ?? '')) ?></code></small>
        </div>
    </section>
</div>

<section class="card" id="after-hours-recovery" style="margin-top:16px">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Continuidade do atendimento</span>
            <h2>Mensagens fora do horário</h2>
            <p>Mensagens recebidas fora do expediente ficam preservadas e voltam a ser avaliadas no próximo período válido. Várias mensagens da mesma conversa são tratadas como uma única demanda, sem consumir franquia enquanto aguardam.</p>
        </div>
        <span class="badge <?= (int) ($afterHours['errors'] ?? 0) > 0 ? 'badge-warning' : ((int) ($afterHours['total'] ?? 0) > 0 ? 'badge-info' : 'badge-success') ?>"><?= (int) ($afterHours['total'] ?? 0) ?> pendente(s)</span>
    </div>
    <div class="operations-alert <?= (int) ($afterHours['errors'] ?? 0) > 0 ? 'is-warning' : 'is-ok' ?>">
        <strong>Proteções ativas</strong>
        <p>A retomada só responde quando a empresa já está no horário de atendimento, a conversa continua em modo IA, ninguém da equipe respondeu manualmente, o WhatsApp está disponível e — quando a credencial é custeada pela RS Connect — ainda existe franquia no plano.</p>
        <?php if ((int) ($afterHours['blocked_plan'] ?? 0) > 0): ?><small><?= (int) $afterHours['blocked_plan'] ?> conversa(s) estão preservadas aguardando renovação/aumento da franquia.</small><?php endif; ?>
        <?php if ((int) ($afterHours['blocked_human'] ?? 0) > 0): ?><small><?= (int) $afterHours['blocked_human'] ?> conversa(s) não serão automatizadas enquanto estiverem sob atendimento humano ou assistente pausado.</small><?php endif; ?>
    </div>
    <?php if ($afterHoursItems): ?>
    <div class="after-hours-operations-list" data-collapsible-list="5">
        <?php foreach ($afterHoursItems as $item): ?>
            <?php
                $pendingStatus = (string) ($item['status'] ?? 'pending');
                $pendingLabel = [
                    'pending' => 'Aguardando horário',
                    'processing' => 'Retomando agora',
                    'blocked_plan' => 'Aguardando franquia',
                    'blocked_human' => 'Pausada para humano',
                    'error' => 'Nova tentativa programada',
                ][$pendingStatus] ?? ucfirst($pendingStatus);
                $pendingClass = [
                    'pending' => 'is-waiting',
                    'processing' => 'is-processing',
                    'blocked_plan' => 'is-blocked',
                    'blocked_human' => 'is-human',
                    'error' => 'is-error',
                ][$pendingStatus] ?? 'is-waiting';
                $messageCount = max(1, (int) ($item['message_count'] ?? 0));
                $nextOpening = trim((string) ($item['next_opening_at'] ?? ''));
                $ackSent = !empty($item['ack_sent_at']);
            ?>
            <article class="after-hours-operation-card <?= View::e($pendingClass) ?>">
                <div class="after-hours-operation-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                </div>
                <div class="after-hours-operation-main">
                    <div class="after-hours-operation-head">
                        <div>
                            <span class="after-hours-operation-status"><?= View::e($pendingLabel) ?></span>
                            <h3><?= View::e((string) (($item['contact_name'] ?? '') ?: ($item['contact_phone'] ?? 'Contato'))) ?></h3>
                            <p><?= View::e((string) ($item['tenant_name'] ?? 'Empresa')) ?> · Assistente <?= View::e((string) ($item['agent_name'] ?? 'não definido')) ?></p>
                        </div>
                        <span class="after-hours-operation-count"><strong><?= $messageCount ?></strong><?= $messageCount === 1 ? ' mensagem' : ' mensagens' ?></span>
                    </div>
                    <div class="after-hours-operation-metrics">
                        <span><small>Primeira mensagem</small><strong><?= View::e($formatDate($item['first_received_at'] ?? null)) ?></strong></span>
                        <span><small>Última mensagem</small><strong><?= View::e($formatDate($item['last_received_at'] ?? null)) ?></strong></span>
                        <span><small>Aviso de ausência</small><strong><?= $ackSent ? 'Enviado' : 'Não enviado' ?></strong></span>
                        <span><small>Retomada prevista</small><strong><?= View::e($nextOpening !== '' ? $formatDate($nextOpening) : 'Próximo expediente') ?></strong></span>
                    </div>
                    <?php if (!empty($item['last_error'])): ?>
                        <div class="after-hours-operation-note is-error"><?= View::e(OperationalLanguageService::replaceTechnicalTerms((string) $item['last_error'])) ?></div>
                    <?php elseif ($pendingStatus === 'blocked_human'): ?>
                        <div class="after-hours-operation-note">A automação está respeitando o atendimento humano ou a IA pausada.</div>
                    <?php elseif ($pendingStatus === 'blocked_plan'): ?>
                        <div class="after-hours-operation-note">A conversa permanece preservada até a franquia estar disponível.</div>
                    <?php else: ?>
                        <div class="after-hours-operation-note">A demanda será retomada sem repetir respostas já enviadas.</div>
                    <?php endif; ?>
                </div>
                <div class="after-hours-operation-actions">
                    <?php $conversationQuery = http_build_query(['tenant_id' => (int) ($item['tenant_id'] ?? 0), 'conversation_id' => (int) ($item['conversation_id'] ?? 0)]); ?>
                    <a class="btn btn-small btn-outline" href="<?= View::e(Router::url('/conversations?' . $conversationQuery)) ?>">Abrir conversa</a>
                    <small><?= (int) ($item['recovery_attempts'] ?? 0) ?> tentativa(s)</small>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <div class="empty-state after-hours-empty-state"><strong>Nenhuma mensagem aguardando horário.</strong><span>As conversas fora do expediente aparecerão aqui quando houver demanda preservada.</span></div>
    <?php endif; ?>
</section>

<section class="card ai-reprocess-failures" style="margin-top:16px">
    <div class="section-heading">
        <div><span class="eyebrow">O que precisa de atenção</span><h2>Tentativas recentes não concluídas</h2><p>Mostra em qual etapa a resposta parou e qual área precisa ser revisada.</p></div>
        <span class="badge <?= $recentFailures ? 'badge-warning' : 'badge-success' ?>"><?= count($recentFailures) ?> registro(s)</span>
    </div>
    <div class="ai-reprocess-failure-list" data-collapsible-list="3">
        <?php foreach ($recentFailures as $failure): ?>
            <article class="ai-reprocess-failure-card">
                <div class="ai-reprocess-failure-main">
                    <span class="badge badge-danger"><?= View::e((string) ($failure['phase_label'] ?? 'Não concluída')) ?></span>
                    <strong><?= View::e((string) ($failure['tenant_name'] ?? 'Empresa')) ?></strong>
                    <p><?= View::e((string) OperationalLanguageService::replaceTechnicalTerms((string) ($failure['diagnostic_message'] ?? $failure['error_message'] ?? 'A tentativa não foi concluída.'))) ?></p>
                    <small>
                        <?= View::e($formatDate($failure['created_at'] ?? null)) ?>
                        · Assistente: <?= View::e((string) ($failure['agent_name'] ?? 'não identificado')) ?>
                        · Instância: <?= View::e((string) (($failure['instance_label'] ?? '') ?: ($failure['instance_name'] ?? 'não identificada'))) ?><?= !empty($failure['connection_state']) ? ' (' . View::e((string) $failure['connection_state']) . ')' : '' ?>
                        · Contato: <?= View::e((string) (($failure['contact_name'] ?? '') ?: ($failure['contact_phone'] ?? 'não identificado'))) ?>
                    </small>
                </div>
                <div class="ai-reprocess-failure-actions">
                    <?php if (!empty($failure['conversation_id'])): ?><a class="btn btn-small btn-outline" href="<?= View::e(Router::url('/conversations?conversation_id=' . (int) $failure['conversation_id'])) ?>">Abrir conversa</a><?php endif; ?>
                    <a class="btn btn-small btn-quiet" href="<?= View::e(Router::url('/instances')) ?>">Abrir WhatsApp</a>
                    <a class="btn btn-small btn-quiet" href="<?= View::e(Router::url('/ai-credentials')) ?>">Configurar assistente</a>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$recentFailures): ?><div class="empty-state">Nenhuma tentativa recente precisa de atenção.</div><?php endif; ?>
    </div>
</section>

<section class="card ai-pending-instance-card" style="margin-top:16px">
    <div class="section-heading">
        <div><span class="eyebrow">Situação atual</span><h2>Conexões com respostas pendentes</h2><p>As pendências são agrupadas pelo WhatsApp e pelo assistente responsável. Quando uma tentativa não é concluída, o sistema pausa novas tentativas daquele grupo para evitar repetições.</p></div>
        <span class="badge <?= $pendingInstances ? 'badge-warning' : 'badge-success' ?>"><?= count($pendingInstances) ?> grupo(s)</span>
    </div>
    <div class="ai-pending-instance-list" data-collapsible-list="3">
        <?php foreach ($pendingInstances as $item): ?>
            <?php
                $instanceState = strtolower((string) (($item['connection_state'] ?? '') ?: ($item['instance_status'] ?? '')));
                $instanceOk = in_array($instanceState, ['open','connected','active','online'], true);
                $liveCheckError = trim((string) ($item['live_check_error'] ?? ''));
                $instanceBadge = $instanceOk ? 'Conectada ao vivo' : ($instanceState === 'unverified' ? 'Estado não confirmado' : 'Revisar conexão');
            ?>
            <article class="ai-pending-instance-item <?= $instanceOk ? 'is-connected' : 'is-warning' ?>">
                <div class="ai-pending-instance-main">
                    <div class="ai-pending-instance-title">
                        <span class="badge <?= $instanceOk ? 'badge-success' : 'badge-warning' ?>"><?= View::e($instanceBadge) ?></span>
                        <strong><?= View::e((string) ($item['tenant_name'] ?? 'Empresa')) ?></strong>
                    </div>
                    <h3><?= View::e((string) ($item['instance_label'] ?? 'Instância não identificada')) ?></h3>
                    <p>Assistente: <strong><?= View::e((string) ($item['agent_name'] ?? 'não identificado')) ?></strong> · <?= (int) ($item['pending_count'] ?? 0) ?> conversa(s) pendente(s)</p>
                    <?php if (!$instanceOk): ?>
                        <div class="ai-pending-instance-error">
                            <strong><?= $instanceState === 'unverified' ? 'Não foi possível validar a conexão' : 'Aguardando reconexão' ?></strong>
                            <span><?= $instanceState === 'unverified' ? 'O RS Connect aguardará a confirmação da conexão antes de tentar novamente.' : 'As mensagens permanecem guardadas, mas o RS Connect não repetirá tentativas enquanto o WhatsApp estiver desconectado.' ?></span>
                            <?php if ($liveCheckError !== ''): ?><small><?= View::e(OperationalLanguageService::replaceTechnicalTerms($liveCheckError)) ?></small><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <small>Mais antiga: <?= View::e($formatDate($item['oldest_pending_at'] ?? null)) ?> · Mais recente: <?= View::e($formatDate($item['latest_pending_at'] ?? null)) ?><?= !empty($item['last_status_check_at']) ? ' · WhatsApp verificado em ' . View::e($formatDate($item['last_status_check_at'])) : '' ?></small>
                    <?php if (!empty($item['last_error_message'])): ?>
                        <div class="ai-pending-instance-error"><strong>Última tentativa não concluída</strong><span><?= View::e(OperationalLanguageService::replaceTechnicalTerms((string) $item['last_error_message'])) ?></span><?php if (!empty($item['last_error_at'])): ?><small><?= View::e($formatDate($item['last_error_at'])) ?></small><?php endif; ?></div>
                    <?php endif; ?>
                </div>
                <div class="ai-pending-instance-actions">
                    <a class="btn btn-small btn-outline" href="<?= View::e(Router::url('/instances')) ?>">Abrir WhatsApp</a>
                    <a class="btn btn-small btn-quiet" href="<?= View::e(Router::url('/companies/health?tenant_id=' . (int) ($item['tenant_id'] ?? 0))) ?>">Ver situação da empresa</a>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$pendingInstances): ?><div class="empty-state">Nenhuma resposta automática está pendente.</div><?php endif; ?>
    </div>
</section>

<section class="card" style="margin-top:16px">
    <div class="section-heading">
        <div><span class="eyebrow">Auditoria</span><h2>Últimas execuções</h2></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Início</th><th>Origem</th><th>Status</th><th>Reavaliadas</th><th>Respondidas</th><th>Pendentes após</th><th>Responsável</th></tr></thead>
            <tbody data-collapsible-list="3">
                <?php foreach ($history as $run): ?>
                    <tr>
                        <td><?= View::e($formatDate($run['started_at'] ?? null)) ?></td>
                        <td><?= View::e(['manual' => 'Manual', 'scheduled' => 'Agendada', 'webhook' => 'Integração', 'cli' => 'CLI'][(string) ($run['source'] ?? '')] ?? (string) ($run['source'] ?? '')) ?></td>
                        <td><span class="badge <?= ($run['status'] ?? '') === 'success' ? 'badge-success' : (($run['status'] ?? '') === 'error' ? 'badge-danger' : 'badge-warning') ?>"><?= View::e($statusLabel((string) ($run['status'] ?? ''))) ?></span></td>
                        <td><?= (int) ($run['attempted_count'] ?? 0) ?></td>
                        <td><?= (int) ($run['replied_count'] ?? 0) ?></td>
                        <td><?= (int) ($run['pending_after'] ?? 0) ?></td>
                        <td><?= View::e((string) ($run['created_by_name'] ?? 'Rotina automática')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$history): ?><tr><td colspan="7"><div class="empty-state">Nenhuma execução registrada ainda.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
