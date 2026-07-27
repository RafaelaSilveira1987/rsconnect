<?php
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$tenants = $data['tenants'] ?? [];
$history = $data['history'] ?? [];
$replies = $data['replies'] ?? [];
$summary = $data['summary'] ?? [];
$prefillTenant = (int) ($prefillTenant ?? 0);
$prefillIncident = (int) ($prefillIncident ?? 0);
$prefillType = (string) ($prefillType ?? '');
$tab = (string) ($_GET['tab'] ?? 'compose');
if ($prefillTenant > 0 || $prefillIncident > 0 || $prefillType !== '') {
    $tab = 'compose';
}
if (!in_array($tab, ['compose', 'history', 'replies'], true)) {
    $tab = 'compose';
}

$icon = static function (string $name): string {
    $paths = [
        'send' => '<path d="m4 4 16 8-16 8 3-8-3-8Z"/><path d="M7 12h13"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'reply' => '<path d="m9 17-5-5 5-5"/><path d="M4 12h10a6 6 0 0 1 6 6"/>',
        'alert' => '<path d="M12 3 2.5 20h19L12 3Z"/><path d="M12 9v4M12 17h.01"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="7" r="4"/>',
        'inbox' => '<path d="M4 4h16v13H4z"/><path d="m4 13 4 4h8l4-4"/>',
    ];
    $path = $paths[$name] ?? $paths['mail'];
    return '<svg class="ui-line-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
};

$typeLabel = static fn (string $type): string => [
    'information' => 'Informação',
    'maintenance' => 'Manutenção',
    'attention' => 'Atenção',
    'incident' => 'Incidente',
    'resolved' => 'Resolvido',
][$type] ?? 'Informação';
$priorityLabel = static fn (string $priority): string => [
    'normal' => 'Normal',
    'important' => 'Importante',
    'critical' => 'Crítica',
][$priority] ?? 'Normal';
?>
<section class="admin-module-hero communications-hero-v3">
    <div class="communications-hero-copy">
        <span class="eyebrow">Operação RS</span>
        <h2>Central de comunicação</h2>
        <p>Comunique a empresa cliente dentro da plataforma, acompanhe a leitura e mantenha respostas administrativas separadas do atendimento no WhatsApp.</p>
    </div>
    <div class="communication-summary-strip communication-summary-v3" aria-label="Resumo da comunicação">
        <span><?= $icon('send') ?><strong><?= (int) ($summary['sent'] ?? 0) ?></strong><small>Enviados</small></span>
        <span><?= $icon('eye') ?><strong><?= (int) ($summary['unread'] ?? 0) ?></strong><small>Não lidos</small></span>
        <span><?= $icon('reply') ?><strong><?= (int) ($summary['replies'] ?? 0) ?></strong><small>Respostas</small></span>
        <span><?= $icon('alert') ?><strong><?= (int) ($summary['active_incidents'] ?? 0) ?></strong><small>Incidentes ativos</small></span>
    </div>
</section>

<nav class="communication-tabs" aria-label="Seções da Central de comunicação">
    <a class="communication-tab<?= $tab === 'compose' ? ' is-active' : '' ?>" href="<?= View::e(Router::url('/comunicados?tab=compose')) ?>"><?= $icon('send') ?><span>Novo comunicado</span></a>
    <a class="communication-tab<?= $tab === 'history' ? ' is-active' : '' ?>" href="<?= View::e(Router::url('/comunicados?tab=history')) ?>"><?= $icon('history') ?><span>Histórico</span><?php if ((int) ($summary['unread'] ?? 0) > 0): ?><b><?= (int) $summary['unread'] ?></b><?php endif; ?></a>
    <a class="communication-tab<?= $tab === 'replies' ? ' is-active' : '' ?>" href="<?= View::e(Router::url('/comunicados?tab=replies')) ?>"><?= $icon('reply') ?><span>Respostas</span><?php if ((int) ($summary['replies'] ?? 0) > 0): ?><b><?= (int) $summary['replies'] ?></b><?php endif; ?></a>
</nav>

<?php if ($tab === 'compose'): ?>
<div class="communications-compose-shell">
    <section class="card communication-compose-v3">
        <div class="communication-card-heading">
            <span class="communication-heading-icon"><?= $icon('mail') ?></span>
            <div><span class="eyebrow">Novo comunicado</span><h2>Mensagem para empresas</h2><p>A entrega interna é imediata. Configure o conteúdo, o público e como o cliente poderá interagir.</p></div>
        </div>

        <form method="post" action="<?= View::e(Router::url('/comunicados/send')) ?>" data-communication-compose>
            <?= Csrf::input() ?>
            <fieldset class="communication-form-section">
                <legend>Conteúdo</legend>
                <div class="form-grid two">
                    <label>Tipo
                        <select class="input" name="communication_type" data-communication-field="type">
                            <option value="information">Informação</option>
                            <option value="maintenance">Manutenção</option>
                            <option value="attention">Atenção</option>
                            <option value="incident" <?= $prefillType === 'incident' ? 'selected' : '' ?>>Incidente</option>
                            <option value="resolved" <?= $prefillType === 'resolved' ? 'selected' : '' ?>>Resolvido</option>
                        </select>
                    </label>
                    <label>Prioridade
                        <select class="input" name="priority" data-communication-field="priority">
                            <option value="normal">Normal</option>
                            <option value="important">Importante</option>
                            <option value="critical">Crítica</option>
                        </select>
                    </label>
                </div>
                <label>Título
                    <input class="input" name="title" maxlength="180" required placeholder="Ex.: Manutenção programada da agenda" data-communication-field="title">
                </label>
                <label>Mensagem
                    <textarea class="input communication-message-editor" name="message" rows="5" required placeholder="Explique o impacto, a orientação e o que o cliente precisa fazer, sem expor detalhes técnicos internos." data-communication-field="message"></textarea>
                </label>
            </fieldset>

            <fieldset class="communication-form-section">
                <legend>Destino e interação</legend>
                <div class="form-grid two">
                    <label>Público
                        <select class="input" name="audience_type">
                            <option value="selected">Empresas selecionadas</option>
                            <option value="all">Todas as empresas</option>
                            <?php if ($prefillIncident > 0): ?><option value="incident" selected>Empresa afetada pelo incidente</option><?php endif; ?>
                        </select>
                    </label>
                    <label>Resposta do cliente
                        <select class="input" name="response_mode" data-communication-field="response_mode">
                            <option value="none">Somente leitura</option>
                            <option value="acknowledge">Solicitar confirmação</option>
                            <option value="reply">Permitir resposta</option>
                        </select>
                    </label>
                </div>
                <input type="hidden" name="incident_id" value="<?= $prefillIncident ?>">
                <div class="communication-tenant-picker communication-tenant-picker-v3">
                    <div class="communication-picker-heading"><strong>Empresas destinatárias</strong><small>Use quando o público estiver em “Empresas selecionadas”.</small></div>
                    <div class="communication-tenant-list">
                        <?php foreach ($tenants as $tenant): ?>
                            <label>
                                <input type="checkbox" name="tenant_ids[]" value="<?= (int) $tenant['id'] ?>" <?= $prefillTenant === (int) $tenant['id'] ? 'checked' : '' ?>>
                                <span><strong><?= View::e((string) $tenant['name']) ?></strong><small><?= View::e((string) ($tenant['email'] ?? '')) ?><?= !empty($tenant['admin_phone']) ? ' · ' . View::e((string) $tenant['admin_phone']) : '' ?></small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </fieldset>

            <fieldset class="communication-form-section">
                <legend>Entrega</legend>
                <div class="communication-channels communication-channels-v3">
                    <label class="is-fixed"><input type="checkbox" checked disabled><span><?= $icon('inbox') ?><span><strong>RS Connect</strong><small>Caixa de mensagens, contador e histórico.</small></span></span></label>
                    <label><input type="checkbox" name="channel_whatsapp" value="1"><span><?= $icon('mail') ?><span><strong>WhatsApp administrativo</strong><small>Aguardará configuração do provedor externo.</small></span></span></label>
                    <label><input type="checkbox" name="channel_email" value="1"><span><?= $icon('mail') ?><span><strong>E-mail</strong><small>Aguardará configuração do transportador.</small></span></span></label>
                </div>
                <div class="form-grid two communication-validity-row">
                    <label>Exibir até <span class="field-hint">opcional</span>
                        <input class="input" type="datetime-local" name="expires_at">
                    </label>
                    <div class="communication-field-note"><?= $icon('clock') ?><span><strong>Validade visual</strong><small>Ao expirar, sai da caixa flutuante e permanece no histórico.</small></span></div>
                </div>
            </fieldset>

            <div class="communication-submit-bar">
                <span><?= $icon('check') ?> O comunicado interno será entregue imediatamente.</span>
                <button class="btn btn-primary" type="submit"><?= $icon('send') ?> Enviar comunicado</button>
            </div>
        </form>
    </section>

    <aside class="communication-preview-column">
        <section class="card communication-preview-card communication-preview-card-v3">
            <span class="eyebrow">Pré-visualização</span>
            <h2>Experiência do cliente</h2>
            <p class="muted-text">Esta é a aparência aproximada da notificação interna. Fechar a caixa não registra leitura.</p>
            <div class="communication-preview-stage">
                <div class="communication-preview" data-communication-preview data-priority="normal">
                    <div class="communication-preview-head">
                        <span class="communication-preview-icon"><?= $icon('mail') ?></span>
                        <div><small data-preview-type>Informação · Normal</small><strong data-preview-title>Seu comunicado aparecerá aqui</strong></div>
                    </div>
                    <p data-preview-message>Preencha título e mensagem para visualizar a experiência do cliente.</p>
                    <div class="communication-preview-actions"><span data-preview-action>Abrir mensagem</span></div>
                </div>
                <div class="communication-preview-bubble"><?= $icon('mail') ?><span>1</span></div>
            </div>
        </section>
        <section class="card communication-guidance-card">
            <span class="eyebrow">Boas práticas</span>
            <div class="communication-guidance-list">
                <div><?= $icon('alert') ?><span><strong>Fale sobre impacto</strong><small>Evite tokens, nomes de serviços internos e mensagens técnicas.</small></span></div>
                <div><?= $icon('eye') ?><span><strong>Leitura é explícita</strong><small>Minimizar a caixa não transforma o comunicado em lido.</small></span></div>
                <div><?= $icon('reply') ?><span><strong>Resposta é opcional</strong><small>Use conversa apenas quando realmente precisar de retorno do cliente.</small></span></div>
            </div>
        </section>
    </aside>
</div>

<?php elseif ($tab === 'history'): ?>
<section class="card communication-section-card">
    <div class="communication-section-toolbar">
        <div><span class="eyebrow">Histórico</span><h2>Comunicados enviados</h2><p>Acompanhe destinatários, leitura, confirmação e canais externos em uma única visão.</p></div>
        <a class="btn btn-primary" href="<?= View::e(Router::url('/comunicados?tab=compose')) ?>"><?= $icon('send') ?> Novo comunicado</a>
    </div>
    <div class="communication-history-list-v3">
        <?php foreach ($history as $row): ?>
            <?php $recipients = max(0, (int) ($row['recipients'] ?? 0)); $read = max(0, (int) ($row['read_count'] ?? 0)); ?>
            <article class="communication-history-item-v3 is-<?= View::e((string) ($row['priority'] ?? 'normal')) ?>">
                <div class="communication-history-main-v3">
                    <span class="communication-type-icon"><?= $icon(($row['communication_type'] ?? '') === 'incident' ? 'alert' : (($row['communication_type'] ?? '') === 'resolved' ? 'check' : 'mail')) ?></span>
                    <div><div class="communication-history-meta-v3"><span><?= View::e($typeLabel((string) ($row['communication_type'] ?? ''))) ?></span><span><?= View::e($priorityLabel((string) ($row['priority'] ?? 'normal'))) ?></span><span><?= View::e((string) ($row['sent_at'] ?? $row['created_at'] ?? '')) ?></span></div><h3><?= View::e((string) ($row['title'] ?? '')) ?></h3><p><?= View::e(mb_strimwidth((string) ($row['message'] ?? ''), 0, 180, '…')) ?></p></div>
                </div>
                <div class="communication-history-metrics-v3">
                    <span><small>Empresas</small><strong><?= $recipients ?></strong></span>
                    <span><small>Lidas</small><strong><?= $read ?>/<?= $recipients ?></strong></span>
                    <span><small>Respostas</small><strong><?= (int) ($row['reply_count'] ?? 0) ?></strong></span>
                    <?php if (($row['response_mode'] ?? '') === 'acknowledge'): ?><span><small>Confirmadas</small><strong><?= (int) ($row['acknowledged_count'] ?? 0) ?></strong></span><?php endif; ?>
                </div>
                <div class="communication-history-footer-v3">
                    <span><?= (int) ($row['unread_count'] ?? 0) > 0 ? (int) $row['unread_count'] . ' pendente(s) de leitura' : 'Leitura interna em dia' ?></span>
                    <span><?= (int) ($row['whatsapp_pending'] ?? 0) > 0 ? 'WhatsApp aguardando configuração' : 'WhatsApp não solicitado' ?></span>
                    <span><?= (int) ($row['email_pending'] ?? 0) > 0 ? 'E-mail aguardando configuração' : 'E-mail não solicitado' ?></span>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$history): ?><div class="empty-state-inline">Nenhum comunicado enviado.</div><?php endif; ?>
    </div>
</section>

<?php else: ?>
<section class="card communication-section-card">
    <div class="communication-section-toolbar"><div><span class="eyebrow">Respostas</span><h2>Conversas com empresas</h2><p>Este canal é administrativo e permanece separado das conversas de atendimento dos clientes finais.</p></div></div>
    <div class="communication-admin-thread-list communication-admin-thread-list-v3">
        <?php foreach ($replies as $reply): ?>
            <article class="communication-admin-reply is-<?= View::e((string) ($reply['direction'] ?? 'tenant_to_rs')) ?>">
                <div class="communication-admin-reply-head">
                    <div class="communication-company-identity"><span><?= $icon('users') ?></span><div><strong><?= View::e((string) ($reply['tenant_name'] ?? 'Empresa')) ?></strong><small><?= View::e((string) ($reply['title'] ?? 'Comunicado')) ?></small></div></div>
                    <div class="communication-reply-meta"><span class="badge"><?= ($reply['direction'] ?? '') === 'tenant_to_rs' ? 'Empresa para RS' : 'RS para empresa' ?></span><small><?= View::e((string) ($reply['created_at'] ?? '')) ?></small></div>
                </div>
                <p><?= nl2br(View::e((string) ($reply['message'] ?? ''))) ?></p>
                <?php if (($reply['direction'] ?? '') === 'tenant_to_rs'): ?>
                    <details class="communication-admin-reply-form">
                        <summary><?= $icon('reply') ?> Responder</summary>
                        <form method="post" action="<?= View::e(Router::url('/comunicados/reply')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="communication_id" value="<?= (int) ($reply['communication_id'] ?? 0) ?>">
                            <input type="hidden" name="tenant_id" value="<?= (int) ($reply['tenant_id'] ?? 0) ?>">
                            <textarea class="input" name="message" rows="3" required maxlength="3000" placeholder="Escreva a resposta para a empresa."></textarea>
                            <button class="btn btn-small btn-primary" type="submit"><?= $icon('reply') ?> Enviar resposta</button>
                        </form>
                    </details>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$replies): ?><div class="empty-state-inline">Nenhuma resposta recebida até o momento.</div><?php endif; ?>
    </div>
</section>
<?php endif; ?>
