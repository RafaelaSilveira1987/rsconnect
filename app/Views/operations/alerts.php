<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$preferences = is_array($data['preferences'] ?? null) ? $data['preferences'] : [];
$notifications = is_array($data['notifications'] ?? null) ? $data['notifications'] : [];
$deliveries = is_array($data['deliveries'] ?? null) ? $data['deliveries'] : [];
$incidents = is_array($data['incidents'] ?? null) ? $data['incidents'] : [];
$channels = is_array($data['channels'] ?? null) ? $data['channels'] : [];
$monitorRuns = is_array($data['monitor_runs'] ?? null) ? $data['monitor_runs'] : [];
$checked = static fn (string $key): string => !empty($preferences[$key]) ? 'checked' : '';
$statusLabel = static fn (string $status): string => match ($status) {
    'sent' => 'Enviado',
    'error' => 'Falhou',
    'pending_configuration' => 'Configuração pendente',
    'skipped' => 'Ignorado',
    default => $status !== '' ? $status : '—',
};
$kindLabel = static fn (string $kind): string => match ($kind) {
    'opened' => 'Abertura',
    'reminder' => 'Lembrete',
    'recovered' => 'Recuperação',
    'manual' => 'Teste manual',
    default => $kind,
};
?>

<style>
.ops-monitor-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:14px 0 0}.ops-monitor-summary article,.ops-channel-state{border:1px solid var(--border-color,#dbe4ec);border-radius:14px;padding:14px;background:rgba(255,255,255,.7)}.ops-monitor-summary strong{display:block;font-size:1.35rem}.ops-channel-state-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:14px}.ops-channel-state.is-ready{border-color:#a7e8c1}.ops-channel-state.is-pending{border-color:#f1d79a}.ops-channel-state small{display:block;margin-top:5px}.ops-incident-list{display:grid;gap:14px}.ops-incident-card{border:1px solid var(--border-color,#dbe4ec);border-radius:16px;padding:16px;background:#fff}.ops-incident-card.is-critical{border-left:5px solid #d23d4e}.ops-incident-card.is-warning{border-left:5px solid #d69b20}.ops-incident-card header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.ops-incident-meta{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0}.ops-incident-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.ops-incident-actions form{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.ops-incident-actions input{min-width:240px}.ops-delivery-error{max-width:360px;white-space:normal}.ops-run-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.ops-run{border:1px solid var(--border-color,#dbe4ec);border-radius:12px;padding:12px}.ops-run small{display:block}.operations-alert-layout{align-items:start}@media(max-width:900px){.ops-monitor-summary,.ops-channel-state-list,.ops-run-grid{grid-template-columns:1fr}.ops-incident-card header{display:block}.ops-incident-actions form{display:grid;width:100%}.ops-incident-actions input{min-width:0;width:100%}}
</style>

<section class="admin-module-hero operations-alert-hero">
    <div>
        <span class="eyebrow">Operação RS</span>
        <h2>Monitoramento e alertas operacionais</h2>
        <p>Acompanhe falhas, reconheça incidentes, evite alertas repetidos e receba a recuperação pelos canais configurados.</p>
        <div class="ops-monitor-summary">
            <article><small>Incidentes ativos</small><strong><?= count($incidents) ?></strong></article>
            <article><small>Alertas não lidos</small><strong><?= (int) ($data['unread'] ?? 0) ?></strong></article>
            <article><small>Últimas entregas</small><strong><?= count($deliveries) ?></strong></article>
        </div>
    </div>
    <div class="hero-actions">
        <a class="btn btn-outline" href="<?= View::e(Router::url('/central-operacao?tab=status')) ?>">Abrir status do sistema</a>
        <?php if ((int) ($data['unread'] ?? 0) > 0): ?>
            <form method="post" action="<?= View::e(Router::url('/operacao-alertas/read-all')) ?>">
                <?= Csrf::input() ?>
                <button class="btn btn-primary" type="submit">Marcar alertas como lidos</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Canais</span>
            <h2>Prontidão de entrega</h2>
            <p class="muted-text">O canal interno funciona sem configuração adicional. WhatsApp e e-mail dependem das variáveis do ambiente.</p>
        </div>
        <form method="post" action="<?= View::e(Router::url('/operacao-alertas/test')) ?>">
            <?= Csrf::input() ?>
            <button class="btn btn-outline" type="submit">Testar canais habilitados</button>
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
                <p class="muted-text">Abertura, lembretes e recuperação são controlados por incidente. O mesmo evento não gera spam contínuo.</p>
            </div>
        </div>
        <form method="post" action="<?= View::e(Router::url('/operacao-alertas/save')) ?>">
            <?= Csrf::input() ?>
            <div class="operations-alert-grid">
                <label class="ops-check"><input type="checkbox" name="critical_enabled" value="1" <?= $checked('critical_enabled') ?>><span><strong>Críticos</strong><small>Falhas que exigem ação imediata.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="warning_enabled" value="1" <?= $checked('warning_enabled') ?>><span><strong>Atenções</strong><small>Situações que precisam de revisão.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="evolution_enabled" value="1" <?= $checked('evolution_enabled') ?>><span><strong>WhatsApp / Evolution</strong><small>Instância desconectada ou degradada.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="ai_enabled" value="1" <?= $checked('ai_enabled') ?>><span><strong>IA</strong><small>OpenAI, credenciais e fila da IA.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="n8n_enabled" value="1" <?= $checked('n8n_enabled') ?>><span><strong>n8n</strong><small>Falhas consecutivas e ausência de sucesso.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="webhooks_enabled" value="1" <?= $checked('webhooks_enabled') ?>><span><strong>Webhooks</strong><small>Ausência de mensagens e callbacks.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="backup_enabled" value="1" <?= $checked('backup_enabled') ?>><span><strong>Backup</strong><small>Falha, atraso ou arquivo inválido.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="disk_enabled" value="1" <?= $checked('disk_enabled') ?>><span><strong>Espaço em disco</strong><small>Limites de atenção e criticidade da VPS.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="queue_enabled" value="1" <?= $checked('queue_enabled') ?>><span><strong>Fila de mensagens</strong><small>Pendências antigas e falhas de envio.</small></span></label>
                <label class="ops-check"><input type="checkbox" name="routines_enabled" value="1" <?= $checked('routines_enabled') ?>><span><strong>Rotinas</strong><small>Cron, relatórios e execuções automáticas.</small></span></label>
            </div>

            <div class="section-heading compact"><div><span class="eyebrow">Destinos</span><h3>Onde receber</h3></div></div>
            <div class="operations-channel-grid">
                <label class="ops-channel"><input type="checkbox" name="platform_enabled" value="1" <?= $checked('platform_enabled') ?>><span><strong>RS Connect</strong><small>Sino e central do Super Admin.</small></span></label>
                <label class="ops-channel"><input type="checkbox" name="whatsapp_enabled" value="1" <?= $checked('whatsapp_enabled') ?>><span><strong>WhatsApp</strong><small>Usa a instância administrativa configurada no ambiente.</small></span><input class="input" name="whatsapp_recipient" value="<?= View::e((string) ($preferences['whatsapp_recipient'] ?? '')) ?>" placeholder="5511999999999"></label>
                <label class="ops-channel"><input type="checkbox" name="email_enabled" value="1" <?= $checked('email_enabled') ?>><span><strong>E-mail</strong><small>Usa webhook de entrega ou transportador nativo configurado.</small></span><input class="input" type="email" name="email_recipient" value="<?= View::e((string) ($preferences['email_recipient'] ?? '')) ?>" placeholder="operacao@empresa.com"></label>
            </div>
            <div class="form-grid">
                <label>Relembrar incidente ainda ativo após
                    <input class="input" type="number" min="1" max="72" name="reminder_hours" value="<?= (int) ($preferences['reminder_hours'] ?? 3) ?>">
                    <small>horas</small>
                </label>
            </div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar alertas</button></div>
        </form>
    </section>

    <section class="card operations-alert-feed">
        <div class="section-heading"><div><span class="eyebrow">Central interna</span><h2>Últimos alertas</h2></div></div>
        <div class="notification-list">
            <?php foreach ($notifications as $notification): ?>
                <article class="notification-item notification-<?= View::e((string) ($notification['severity'] ?? 'info')) ?> <?= ($notification['status'] ?? '') === 'unread' ? 'is-unread' : '' ?>">
                    <div class="notification-marker"></div>
                    <div class="notification-main">
                        <strong><?= View::e((string) ($notification['title'] ?? '')) ?></strong>
                        <p><?= View::e((string) ($notification['message'] ?? '')) ?></p>
                        <small><?= View::e((string) ($notification['created_at'] ?? '')) ?> · <?= View::e($kindLabel((string) ($notification['notification_kind'] ?? ''))) ?></small>
                    </div>
                    <?php if (!empty($notification['action_url'])): ?><a class="btn btn-small btn-outline" href="<?= View::e(Router::url((string) $notification['action_url'])) ?>">Diagnosticar</a><?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$notifications): ?><div class="empty-state">Nenhum alerta operacional registrado.</div><?php endif; ?>
        </div>
    </section>
</div>

<section class="card">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Ciclo operacional</span>
            <h2>Incidentes ativos</h2>
            <p class="muted-text">Reconhecer registra que alguém assumiu a ocorrência. Resolver encerra o incidente e envia a recuperação.</p>
        </div>
    </div>
    <div class="ops-incident-list">
        <?php foreach ($incidents as $incident): ?>
            <?php
            $severity = (string) ($incident['severity'] ?? 'warning');
            $acknowledged = !empty($incident['acknowledged_at']);
            $tenantId = (int) ($incident['tenant_id'] ?? 0);
            ?>
            <article class="ops-incident-card <?= in_array($severity, ['critical', 'error'], true) ? 'is-critical' : 'is-warning' ?>">
                <header>
                    <div>
                        <span class="eyebrow"><?= View::e(strtoupper($severity)) ?> · Incidente #<?= (int) ($incident['id'] ?? 0) ?></span>
                        <h3><?= View::e((string) ($incident['event'] ?? 'Incidente operacional')) ?></h3>
                    </div>
                    <span class="badge <?= $acknowledged ? 'badge-active' : 'badge-overdue' ?>"><?= $acknowledged ? 'Reconhecido' : 'Aguardando responsável' ?></span>
                </header>
                <p><?= View::e((string) ($incident['message'] ?? '')) ?></p>
                <div class="ops-incident-meta">
                    <?php if (!empty($incident['tenant_name'])): ?><span class="badge">Empresa: <?= View::e((string) $incident['tenant_name']) ?></span><?php endif; ?>
                    <span class="badge">Aberto: <?= View::e((string) ($incident['created_at'] ?? '')) ?></span>
                    <span class="badge">Última evidência: <?= View::e((string) ($incident['last_seen_at'] ?? '')) ?></span>
                    <?php if ($acknowledged): ?><span class="badge">Por <?= View::e((string) ($incident['acknowledged_by_name'] ?? 'usuário administrativo')) ?> em <?= View::e((string) $incident['acknowledged_at']) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($incident['acknowledgement_note'])): ?><p><strong>Observação:</strong> <?= View::e((string) $incident['acknowledgement_note']) ?></p><?php endif; ?>
                <div class="ops-incident-actions">
                    <?php if (!$acknowledged): ?>
                        <form method="post" action="<?= View::e(Router::url('/operacao-alertas/acknowledge')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="incident_id" value="<?= (int) ($incident['id'] ?? 0) ?>">
                            <label>Observação opcional<input class="input" name="note" maxlength="500" placeholder="Ex.: verificando a instância e os logs."></label>
                            <button class="btn btn-outline" type="submit">Reconhecer incidente</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="<?= View::e(Router::url('/operacao-alertas/resolve')) ?>" onsubmit="return confirm('Marcar este incidente como resolvido?');">
                        <?= Csrf::input() ?>
                        <input type="hidden" name="incident_id" value="<?= (int) ($incident['id'] ?? 0) ?>">
                        <button class="btn btn-primary" type="submit">Marcar como resolvido</button>
                    </form>
                    <a class="btn btn-outline" href="<?= View::e(Router::url('/comunicados?incident_id=' . (int) ($incident['id'] ?? 0) . ($tenantId > 0 ? '&tenant_id=' . $tenantId : '') . '&type=incident')) ?>">Avisar cliente</a>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$incidents): ?><div class="empty-state">Nenhum incidente operacional ativo.</div><?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="section-heading"><div><span class="eyebrow">Entregas</span><h2>Status dos canais</h2><p class="muted-text">Cada tentativa fica registrada com destino, resultado e erro técnico quando houver.</p></div></div>
    <div class="table-responsive">
        <table>
            <thead><tr><th>Incidente</th><th>Evento</th><th>Canal</th><th>Status</th><th>Destino</th><th>Tentativas</th><th>Data</th><th>Detalhe</th></tr></thead>
            <tbody>
                <?php foreach ($deliveries as $delivery): ?>
                    <tr>
                        <td>#<?= (int) ($delivery['incident_id'] ?? 0) ?></td>
                        <td><?= View::e($kindLabel((string) ($delivery['notification_kind'] ?? ''))) ?></td>
                        <td><?= View::e((string) ($delivery['channel'] ?? '')) ?></td>
                        <td><span class="badge"><?= View::e($statusLabel((string) ($delivery['status'] ?? ''))) ?></span></td>
                        <td><?= View::e((string) ($delivery['destination'] ?? '—')) ?></td>
                        <td><?= (int) ($delivery['attempt_count'] ?? 1) ?></td>
                        <td><?= View::e((string) ($delivery['last_attempt_at'] ?? $delivery['created_at'] ?? '')) ?></td>
                        <td class="ops-delivery-error"><?= View::e((string) ($delivery['error_message'] ?? $delivery['provider_message_id'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$deliveries): ?><tr><td colspan="8">Nenhuma entrega registrada.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div class="section-heading"><div><span class="eyebrow">Automação</span><h2>Últimas execuções do monitor</h2><p class="muted-text">Use o template n8n do Monitor operacional para executar a verificação automaticamente.</p></div></div>
    <div class="ops-run-grid">
        <?php foreach ($monitorRuns as $run): ?>
            <article class="ops-run">
                <strong><?= View::e((string) ($run['status'] ?? '')) ?> · <?= View::e((string) ($run['trigger_source'] ?? '')) ?></strong>
                <small><?= View::e((string) ($run['started_at'] ?? '')) ?> · <?= (int) ($run['duration_ms'] ?? 0) ?> ms</small>
                <small><?= (int) ($run['healthy_total'] ?? 0) ?> saudáveis · <?= (int) ($run['warning_total'] ?? 0) ?> atenções · <?= (int) ($run['down_total'] ?? 0) ?> críticos</small>
                <?php if (!empty($run['error_message'])): ?><small><?= View::e((string) $run['error_message']) ?></small><?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$monitorRuns): ?><div class="empty-state">Nenhuma execução registrada após a migration 073.</div><?php endif; ?>
    </div>
</section>
