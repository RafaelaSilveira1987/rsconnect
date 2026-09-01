<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
use App\Services\OperationalLanguageService;

$preferences = is_array($data['preferences'] ?? null) ? $data['preferences'] : [];
$notifications = is_array($data['notifications'] ?? null) ? $data['notifications'] : [];
$deliveries = is_array($data['deliveries'] ?? null) ? $data['deliveries'] : [];
$incidents = is_array($data['incidents'] ?? null) ? $data['incidents'] : [];
$channels = is_array($data['channels'] ?? null) ? $data['channels'] : [];
$monitorRuns = is_array($data['monitor_runs'] ?? null) ? $data['monitor_runs'] : [];
$checked = static fn (string $key): string => !empty($preferences[$key]) ? 'checked' : '';
$statusLabel = static fn (string $status): string => OperationalLanguageService::statusLabel($status);
$kindLabel = static fn (string $kind): string => OperationalLanguageService::notificationKindLabel($kind);
$channelLabel = static fn (string $channel): string => OperationalLanguageService::channelLabel($channel);
$isMessagingIncident = static function (string $event): bool {
    return str_starts_with($event, 'operations.alert.evolution') || $event === 'operations.alert.message_queue';
};
?>

<style>
.ops-monitor-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:14px 0 0}.ops-monitor-summary article,.ops-channel-state{border:1px solid var(--border-color,#dbe4ec);border-radius:14px;padding:14px;background:rgba(255,255,255,.7)}.ops-monitor-summary strong{display:block;font-size:1.35rem}.ops-channel-state-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:14px}.ops-channel-state.is-ready{border-color:#a7e8c1}.ops-channel-state.is-pending{border-color:#f1d79a}.ops-channel-state small{display:block;margin-top:5px}.ops-incident-list{display:grid;gap:14px}.ops-incident-card{border:1px solid var(--border-color,#dbe4ec);border-radius:16px;padding:16px;background:#fff}.ops-incident-card.is-critical{border-left:5px solid #d23d4e}.ops-incident-card.is-warning{border-left:5px solid #d69b20}.ops-incident-card header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.ops-incident-meta{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0}.ops-incident-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.ops-incident-actions form{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.ops-incident-actions input{min-width:240px}.ops-delivery-error{max-width:360px;white-space:normal}.ops-run-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.ops-run{border:1px solid var(--border-color,#dbe4ec);border-radius:12px;padding:12px}.ops-run small{display:block}.operations-alert-layout{align-items:start}@media(max-width:900px){.ops-monitor-summary,.ops-channel-state-list,.ops-run-grid{grid-template-columns:1fr}.ops-incident-card header{display:block}.ops-incident-actions form{display:grid;width:100%}.ops-incident-actions input{min-width:0;width:100%}}
</style>

<section class="admin-module-hero operations-alert-hero">
    <div>
        <span class="eyebrow">Operação RS</span>
        <h2>Avisos sobre o funcionamento do RS Connect</h2>
        <p>Veja rapidamente o que aconteceu, o que pode ser afetado e o que fazer para normalizar.</p>
        <div class="ops-monitor-summary">
            <article><small>Situações em aberto</small><strong><?= count($incidents) ?></strong></article>
            <article><small>Avisos não lidos</small><strong><?= (int) ($data['unread'] ?? 0) ?></strong></article>
            <article><small>Avisos enviados</small><strong><?= count($deliveries) ?></strong></article>
        </div>
    </div>
    <div class="hero-actions">
        <a class="btn btn-outline" href="<?= View::e(Router::url('/central-operacao?tab=status')) ?>">Ver situação do sistema</a>
        <?php if ((int) ($data['unread'] ?? 0) > 0): ?>
            <form method="post" action="<?= View::e(Router::url('/operacao-alertas/read-all')) ?>">
                <?= Csrf::input() ?>
                <button class="btn btn-primary" type="submit">Marcar avisos como lidos</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Canais</span>
            <h2>Onde os avisos serão recebidos</h2>
            <p class="muted-text">A RS Connect funciona sem configuração adicional. O WhatsApp precisa estar conectado e com destinatário definido.</p>
        </div>
        <form method="post" action="<?= View::e(Router::url('/operacao-alertas/test')) ?>">
            <?= Csrf::input() ?>
            <button class="btn btn-outline" type="submit">Testar avisos habilitados</button>
        </form>
    </div>
    <div class="ops-channel-state-list">
        <?php foreach (['platform' => 'RS Connect', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail'] as $key => $label): ?>
            <?php $ready = !empty($channels[$key]['ready']); ?>
            <article class="ops-channel-state <?= $ready ? 'is-ready' : 'is-pending' ?>">
                <strong><?= View::e($label) ?></strong>
                <small><?= View::e((string) ($channels[$key]['label'] ?? ($ready ? 'Disponível' : 'Pendente'))) ?></small>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<div class="operations-alert-layout">
    <section class="card operations-alert-settings">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Regras</span>
                <h2>Quando avisar</h2>
                <p class="muted-text">O mesmo problema não gera avisos repetidos o tempo todo. Um novo lembrete só é enviado após o período definido.</p>
            </div>
        </div>
        <form method="post" action="<?= View::e(Router::url('/operacao-alertas/save')) ?>">
            <?= Csrf::input() ?>
            <div class="operations-alert-grid">
                <label class="ops-check"><input type="checkbox" name="critical_enabled" value="1" <?= $checked('critical_enabled') ?>><span><strong>Ação imediata</strong><small>Situações que podem interromper o atendimento.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="warning_enabled" value="1" <?= $checked('warning_enabled') ?>><span><strong>Precisa de atenção</strong><small>Situações que devem ser conferidas.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="evolution_enabled" value="1" <?= $checked('evolution_enabled') ?>><span><strong>WhatsApp</strong><small>Número desconectado ou envio não confirmado.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="ai_enabled" value="1" <?= $checked('ai_enabled') ?>><span><strong>Assistente virtual</strong><small>Respostas automáticas e configurações do assistente.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="n8n_enabled" value="1" <?= $checked('n8n_enabled') ?>><span><strong>Automações</strong><small>Tarefas automáticas que não foram concluídas.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="webhooks_enabled" value="1" <?= $checked('webhooks_enabled') ?>><span><strong>Recebimento de atualizações</strong><small>Mensagens e mudanças externas que deixaram de chegar.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="backup_enabled" value="1" <?= $checked('backup_enabled') ?>><span><strong>Cópia de segurança</strong><small>Cópia atrasada, incompleta ou não confirmada.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="disk_enabled" value="1" <?= $checked('disk_enabled') ?>><span><strong>Espaço do servidor</strong><small>Armazenamento abaixo do recomendado.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="queue_enabled" value="1" <?= $checked('queue_enabled') ?>><span><strong>Envio de mensagens</strong><small>Mensagens aguardando confirmação de envio.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="routines_enabled" value="1" <?= $checked('routines_enabled') ?>><span><strong>Rotinas automáticas</strong><small>Cobranças, relatórios e tarefas agendadas.</small></span></label>
            </div>

            <div class="section-heading compact"><div><span class="eyebrow">Destinos</span><h3>Onde receber</h3></div></div>
            <div class="operations-channel-grid">
                <label class="ops-channel"><input type="checkbox" name="platform_enabled" value="1" <?= $checked('platform_enabled') ?>><span><strong>RS Connect</strong><small>Sino e central do Super Admin.</small></span></label>
                <label class="ops-channel"><input type="checkbox" name="whatsapp_enabled" value="1" <?= $checked('whatsapp_enabled') ?>><span><strong>WhatsApp</strong><small>Envia pelo WhatsApp administrativo configurado.</small></span><input class="input" name="whatsapp_recipient" value="<?= View::e((string) ($preferences['whatsapp_recipient'] ?? '')) ?>" placeholder="5511999999999"></label>
                <label class="ops-channel"><input type="checkbox" name="email_enabled" value="1" <?= $checked('email_enabled') ?>><span><strong>E-mail</strong><small>Envia pelo serviço de e-mail configurado.</small></span><input class="input" type="email" name="email_recipient" value="<?= View::e((string) ($preferences['email_recipient'] ?? '')) ?>" placeholder="operacao@empresa.com"></label>
            </div>
            <div class="form-grid">
                <label>Relembrar uma situação ainda aberta após
                    <input class="input" type="number" min="1" max="72" name="reminder_hours" value="<?= (int) ($preferences['reminder_hours'] ?? 3) ?>">
                    <small>horas</small>
                </label>
            </div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar avisos</button></div>
        </form>
    </section>

    <section class="card operations-alert-feed">
        <div class="section-heading"><div><span class="eyebrow">Central interna</span><h2>Últimos avisos</h2></div></div>
        <div class="notification-list">
            <?php foreach ($notifications as $notification): ?>
                <?php $notice = OperationalLanguageService::notification($notification, false); ?>
                <article class="notification-item notification-<?= View::e((string) ($notification['severity'] ?? 'info')) ?> <?= ($notification['status'] ?? '') === 'unread' ? 'is-unread' : '' ?>">
                    <div class="notification-marker"></div>
                    <div class="notification-main">
                        <strong><?= View::e((string) $notice['title']) ?></strong>
                        <p><?= nl2br(View::e((string) $notice['summary'])) ?></p>
                        <small><?= View::e((string) ($notification['created_at'] ?? '')) ?> · <?= View::e($kindLabel((string) ($notification['notification_kind'] ?? ''))) ?></small>
                        <?php if (($notice['technical_title'] ?? '') !== '' || ($notice['technical_message'] ?? '') !== ''): ?>
                            <details class="health-technical-details"><summary>Ver detalhes técnicos</summary><pre><?= View::e(trim((string) ($notice['technical_event'] ?? '') . "
" . (string) ($notice['technical_title'] ?? '') . "
" . (string) ($notice['technical_message'] ?? ''))) ?></pre></details>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($notification['action_url'])): ?><a class="btn btn-small btn-outline" href="<?= View::e(Router::url((string) $notification['action_url'])) ?>">Ver como corrigir</a><?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$notifications): ?><div class="empty-state">Nenhum aviso sobre o funcionamento foi registrado.</div><?php endif; ?>
        </div>
    </section>
</div>

<section class="card">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Acompanhamento</span>
            <h2>Situações em aberto</h2>
            <p class="muted-text">Assumir a análise mostra que alguém já está cuidando. Em falhas de WhatsApp, você pode apenas silenciar o alerta ou também retirar da fila as respostas que não devem mais ser enviadas.</p>
        </div>
    </div>
    <div class="ops-incident-list">
        <?php foreach ($incidents as $incident): ?>
            <?php
            $severity = (string) ($incident['severity'] ?? 'warning');
            $acknowledged = !empty($incident['acknowledged_at']);
            $tenantId = (int) ($incident['tenant_id'] ?? 0);
            $event = (string) ($incident['event'] ?? '');
            $messagingIncident = $isMessagingIncident($event);
            $presentation = OperationalLanguageService::incident($incident, false);
            ?>
            <article class="ops-incident-card <?= in_array($severity, ['critical', 'error'], true) ? 'is-critical' : 'is-warning' ?>">
                <header>
                    <div>
                        <span class="eyebrow"><?= View::e((string) $presentation['severity_label']) ?> · Situação #<?= (int) ($incident['id'] ?? 0) ?></span>
                        <h3><?= View::e((string) $presentation['title']) ?></h3>
                    </div>
                    <span class="badge <?= $acknowledged ? 'badge-active' : 'badge-overdue' ?>"><?= $acknowledged ? 'Em análise' : 'Aguardando análise' ?></span>
                </header>
                <div class="ops-friendly-diagnosis"><p><strong>O que aconteceu:</strong> <?= View::e((string) $presentation['summary']) ?></p><p><strong>O que pode ser afetado:</strong> <?= View::e((string) $presentation['impact']) ?></p><p><strong>O que fazer agora:</strong> <?= View::e((string) $presentation['action']) ?></p></div>
                <details class="health-technical-details"><summary>Ver detalhes técnicos</summary><pre><?= View::e(trim((string) $presentation['technical_event'] . "\n" . (string) $presentation['technical_title'] . "\n" . (string) $presentation['technical_message'])) ?></pre></details>
                <div class="ops-incident-meta">
                    <?php if (!empty($incident['tenant_name'])): ?><span class="badge">Empresa: <?= View::e((string) $incident['tenant_name']) ?></span><?php endif; ?>
                    <span class="badge">Identificado: <?= View::e((string) ($incident['created_at'] ?? '')) ?></span>
                    <span class="badge">Última confirmação: <?= View::e((string) ($incident['last_seen_at'] ?? '')) ?></span>
                    <?php if ($acknowledged): ?><span class="badge">Por <?= View::e((string) ($incident['acknowledged_by_name'] ?? 'usuário administrativo')) ?> em <?= View::e((string) $incident['acknowledged_at']) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($incident['acknowledgement_note'])): ?><p><strong>Observação:</strong> <?= View::e((string) $incident['acknowledgement_note']) ?></p><?php endif; ?>
                <div class="ops-incident-actions">
                    <?php if (!$acknowledged): ?>
                        <form method="post" action="<?= View::e(Router::url('/operacao-alertas/acknowledge')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="incident_id" value="<?= (int) ($incident['id'] ?? 0) ?>">
                            <label>Observação opcional<input class="input" name="note" maxlength="500" placeholder="Ex.: reconectando o WhatsApp da empresa."></label>
                            <button class="btn btn-outline" type="submit">Assumir análise</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($messagingIncident): ?>
                        <form method="post" action="<?= View::e(Router::url('/operacao-alertas/resolve')) ?>" data-confirm="As respostas pendentes/falhas desta conexão serão canceladas no histórico e não serão enviadas após a reconexão. Confirmar?">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="incident_id" value="<?= (int) ($incident['id'] ?? 0) ?>">
                            <input type="hidden" name="release_queue" value="1">
                            <button class="btn btn-primary" type="submit">Resolver e liberar fila</button>
                        </form>
                        <form method="post" action="<?= View::e(Router::url('/operacao-alertas/resolve')) ?>" data-confirm="O alerta será encerrado e silenciado até a reconexão, mas as respostas permanecerão preservadas na fila. Confirmar?">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="incident_id" value="<?= (int) ($incident['id'] ?? 0) ?>">
                            <button class="btn btn-outline" type="submit">Resolver sem limpar fila</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= View::e(Router::url('/operacao-alertas/resolve')) ?>" data-confirm="Confirmar que esta situação voltou ao normal?">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="incident_id" value="<?= (int) ($incident['id'] ?? 0) ?>">
                            <button class="btn btn-primary" type="submit">Marcar como normalizado</button>
                        </form>
                    <?php endif; ?>
                    <a class="btn btn-outline" href="<?= View::e(Router::url('/comunicados?incident_id=' . (int) ($incident['id'] ?? 0) . ($tenantId > 0 ? '&tenant_id=' . $tenantId : '') . '&type=incident')) ?>">Avisar empresa</a>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$incidents): ?><div class="empty-state">Nenhuma situação precisa de atenção neste momento.</div><?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="section-heading"><div><span class="eyebrow">Envios</span><h2>Resultado dos avisos</h2><p class="muted-text">Veja para onde cada aviso foi enviado e se a entrega foi confirmada.</p></div></div>
    <div class="table-responsive">
        <table>
            <thead><tr><th>Situação</th><th>Tipo do aviso</th><th>Canal</th><th>Resultado</th><th>Destino</th><th>Tentativas</th><th>Data</th><th>Observação</th></tr></thead>
            <tbody>
                <?php foreach ($deliveries as $delivery): ?>
                    <tr>
                        <td>Situação #<?= (int) ($delivery['incident_id'] ?? 0) ?></td>
                        <td><?= View::e($kindLabel((string) ($delivery['notification_kind'] ?? ''))) ?></td>
                        <td><?= View::e($channelLabel((string) ($delivery['channel'] ?? ''))) ?></td>
                        <td><span class="badge"><?= View::e($statusLabel((string) ($delivery['status'] ?? ''))) ?></span></td>
                        <td><?= View::e((string) ($delivery['destination'] ?? '—')) ?></td>
                        <td><?= (int) ($delivery['attempt_count'] ?? 1) ?></td>
                        <td><?= View::e((string) ($delivery['last_attempt_at'] ?? $delivery['created_at'] ?? '')) ?></td>
                        <td class="ops-delivery-error"><?php if (!empty($delivery['error_message'])): ?><span>O aviso não foi entregue. Abra os detalhes para conferir.</span><details class="health-technical-details"><summary>Detalhes técnicos</summary><pre><?= View::e((string) $delivery['error_message']) ?></pre></details><?php else: ?><?= View::e((string) ($delivery['provider_message_id'] ?? 'Entrega confirmada')) ?><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$deliveries): ?><tr><td colspan="8">Nenhum envio registrado.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div class="section-heading"><div><span class="eyebrow">Verificações automáticas</span><h2>Últimas verificações do sistema</h2><p class="muted-text">A verificação automática confere o funcionamento e abre avisos somente quando necessário.</p></div></div>
    <div class="ops-run-grid">
        <?php foreach ($monitorRuns as $run): ?>
            <article class="ops-run">
                <strong><?= View::e(OperationalLanguageService::monitorRunLabel((string) ($run['status'] ?? ''))) ?> · <?= View::e(OperationalLanguageService::triggerLabel((string) ($run['trigger_source'] ?? ''))) ?></strong>
                <small><?= View::e((string) ($run['started_at'] ?? '')) ?> · <?= (int) ($run['duration_ms'] ?? 0) ?> ms</small>
                <small><?= (int) ($run['healthy_total'] ?? 0) ?> funcionando · <?= (int) ($run['warning_total'] ?? 0) ?> precisam de atenção · <?= (int) ($run['down_total'] ?? 0) ?> exigem ação imediata</small>
                <?php if (!empty($run['error_message'])): ?><small>A verificação não foi concluída.</small><details class="health-technical-details"><summary>Detalhes técnicos</summary><pre><?= View::e((string) $run['error_message']) ?></pre></details><?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$monitorRuns): ?><div class="empty-state">Nenhuma verificação automática foi registrada ainda.</div><?php endif; ?>
    </div>
</section>
