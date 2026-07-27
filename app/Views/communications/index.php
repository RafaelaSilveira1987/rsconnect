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
$icon = static function (string $name): string {
    $paths = [
        'send' => '<path d="m4 4 16 8-16 8 3-8-3-8Z"/><path d="M7 12h13"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'reply' => '<path d="m9 17-5-5 5-5"/><path d="M4 12h10a6 6 0 0 1 6 6"/>',
        'alert' => '<path d="M12 3 2.5 20h19L12 3Z"/><path d="M12 9v4M12 17h.01"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
    ];
    $path = $paths[$name] ?? $paths['mail'];
    return '<svg class="ui-line-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
};
?>
<section class="admin-module-hero communications-hero">
    <div>
        <span class="eyebrow">Operação RS</span>
        <h2>Central de comunicação</h2>
        <p>Envie avisos claros para empresas clientes, acompanhe leitura e centralize respostas dentro do próprio RS Connect.</p>
    </div>
    <div class="communication-summary-strip">
        <span><strong><?= (int) ($summary['sent'] ?? 0) ?></strong><small>enviados</small></span>
        <span><strong><?= (int) ($summary['unread'] ?? 0) ?></strong><small>não lidos</small></span>
        <span><strong><?= (int) ($summary['replies'] ?? 0) ?></strong><small>respostas</small></span>
        <span><strong><?= (int) ($summary['active_incidents'] ?? 0) ?></strong><small>incidentes ativos</small></span>
    </div>
</section>

<div class="communications-layout communications-layout-v2">
    <section class="card communication-compose">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Novo comunicado</span>
                <h2>Mensagem para clientes</h2>
                <p class="muted-text">A entrega dentro da plataforma é imediata. WhatsApp e e-mail permanecem no mesmo histórico quando os provedores externos forem ativados.</p>
            </div>
        </div>

        <form method="post" action="<?= View::e(Router::url('/comunicados/send')) ?>" data-communication-compose>
            <?= Csrf::input() ?>
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

            <div class="form-grid two">
                <label>Público
                    <select class="input" name="audience_type">
                        <option value="selected">Empresas selecionadas</option>
                        <option value="all">Todas as empresas</option>
                        <?php if ($prefillIncident > 0): ?>
                            <option value="incident" selected>Empresa afetada pelo incidente</option>
                        <?php endif; ?>
                    </select>
                </label>
                <label>Resposta do cliente
                    <select class="input" name="response_mode" data-communication-field="response_mode">
                        <option value="none">Não permitir resposta</option>
                        <option value="acknowledge">Solicitar confirmação de leitura</option>
                        <option value="reply">Permitir resposta</option>
                    </select>
                </label>
            </div>

            <input type="hidden" name="incident_id" value="<?= $prefillIncident ?>">
            <label>Título
                <input class="input" name="title" maxlength="180" required placeholder="Ex.: Instabilidade temporária na agenda" data-communication-field="title">
            </label>
            <label>Mensagem
                <textarea class="input" name="message" rows="6" required placeholder="Explique impacto e orientação de forma clara, sem expor detalhes técnicos internos." data-communication-field="message"></textarea>
            </label>

            <div class="form-grid two">
                <label>Exibir até <span class="field-hint">opcional</span>
                    <input class="input" type="datetime-local" name="expires_at">
                </label>
                <div class="communication-field-note">
                    <?= $icon('clock') ?>
                    <span><strong>Validade</strong><small>Após esse horário a caixa flutuante deixa de exibir o aviso, mas o histórico permanece disponível.</small></span>
                </div>
            </div>

            <div class="communication-tenant-picker">
                <div class="communication-picker-heading"><strong>Destinatários</strong><small>Selecione uma ou mais empresas quando o público for “Empresas selecionadas”.</small></div>
                <div class="communication-tenant-list">
                    <?php foreach ($tenants as $tenant): ?>
                        <label>
                            <input type="checkbox" name="tenant_ids[]" value="<?= (int) $tenant['id'] ?>" <?= $prefillTenant === (int) $tenant['id'] ? 'checked' : '' ?>>
                            <span><strong><?= View::e((string) $tenant['name']) ?></strong><small><?= View::e((string) ($tenant['email'] ?? '')) ?><?= !empty($tenant['admin_phone']) ? ' · ' . View::e((string) $tenant['admin_phone']) : '' ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="communication-channels">
                <label class="is-fixed"><input type="checkbox" checked disabled><span><strong>RS Connect</strong><small>Caixa de mensagens + sininho + histórico.</small></span></label>
                <label><input type="checkbox" name="channel_whatsapp" value="1"><span><strong>WhatsApp administrativo</strong><small>Fica preparado até o provedor administrativo ser configurado.</small></span></label>
                <label><input type="checkbox" name="channel_email" value="1"><span><strong>E-mail</strong><small>Fica preparado até o transportador da RS ser configurado.</small></span></label>
            </div>

            <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $icon('send') ?> Enviar comunicado</button></div>
        </form>
    </section>

    <aside class="card communication-preview-card">
        <span class="eyebrow">Pré-visualização</span>
        <h2>Como o cliente receberá</h2>
        <p class="muted-text">A caixa aparece somente enquanto existir mensagem não lida. Fechar a caixa não marca leitura.</p>
        <div class="communication-preview" data-communication-preview data-priority="normal">
            <div class="communication-preview-head">
                <span class="communication-preview-icon"><?= $icon('mail') ?></span>
                <div><small data-preview-type>Informação</small><strong data-preview-title>Seu comunicado aparecerá aqui</strong></div>
            </div>
            <p data-preview-message>Preencha título e mensagem para visualizar a experiência do cliente.</p>
            <div class="communication-preview-actions"><span data-preview-action>Abrir mensagem</span></div>
        </div>
        <div class="communication-guidance-list">
            <div><?= $icon('alert') ?><span><strong>Sem detalhes internos</strong><small>Traduza falhas técnicas para impacto e ação compreensíveis.</small></span></div>
            <div><?= $icon('eye') ?><span><strong>Leitura real</strong><small>Fechar a caixa flutuante não será contabilizado como leitura.</small></span></div>
            <div><?= $icon('reply') ?><span><strong>Resposta controlada</strong><small>Escolha entre aviso sem resposta, confirmação ou conversa com a RS.</small></span></div>
        </div>
    </aside>
</div>

<section class="card communication-history-card">
    <div class="section-heading"><div><span class="eyebrow">Histórico</span><h2>Envios, leitura e retorno</h2><p class="muted-text">Acompanhe o resultado do canal interno e mantenha os externos no mesmo registro.</p></div></div>
    <div class="table-responsive">
        <table>
            <thead><tr><th>Data</th><th>Comunicado</th><th>Público</th><th>Leitura</th><th>Respostas</th><th>Canais externos</th></tr></thead>
            <tbody>
            <?php foreach ($history as $row): ?>
                <tr>
                    <td><?= View::e((string) ($row['sent_at'] ?? $row['created_at'] ?? '')) ?></td>
                    <td><div class="communication-history-title"><span class="communication-type-mark is-<?= View::e((string) ($row['priority'] ?? 'normal')) ?>"></span><span><strong><?= View::e((string) ($row['title'] ?? '')) ?></strong><small><?= View::e(ucfirst((string) ($row['communication_type'] ?? 'informação'))) ?> · <?= View::e(ucfirst((string) ($row['priority'] ?? 'normal'))) ?></small></span></div></td>
                    <td><?= (int) ($row['recipients'] ?? 0) ?> empresa(s)</td>
                    <td><strong><?= (int) ($row['read_count'] ?? 0) ?>/<?= (int) ($row['recipients'] ?? 0) ?></strong><?php if ((int) ($row['unread_count'] ?? 0) > 0): ?><small class="table-subtext"><?= (int) $row['unread_count'] ?> pendente(s)</small><?php endif; ?></td>
                    <td><?= (int) ($row['reply_count'] ?? 0) ?><?php if (($row['response_mode'] ?? '') === 'acknowledge'): ?><small class="table-subtext"><?= (int) ($row['acknowledged_count'] ?? 0) ?> confirmado(s)</small><?php endif; ?></td>
                    <td><small><?= (int) ($row['whatsapp_pending'] ?? 0) > 0 ? 'WhatsApp aguardando configuração' : 'WhatsApp —' ?></small><small class="table-subtext"><?= (int) ($row['email_pending'] ?? 0) > 0 ? 'E-mail aguardando configuração' : 'E-mail —' ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?><tr><td colspan="6">Nenhum comunicado enviado.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card communication-replies-card">
    <div class="section-heading"><div><span class="eyebrow">Respostas</span><h2>Conversas com empresas</h2><p class="muted-text">As respostas administrativas ficam separadas das conversas de WhatsApp dos clientes finais.</p></div></div>
    <div class="communication-admin-thread-list">
        <?php foreach ($replies as $reply): ?>
            <article class="communication-admin-reply is-<?= View::e((string) ($reply['direction'] ?? 'tenant_to_rs')) ?>">
                <div class="communication-admin-reply-head">
                    <div><strong><?= View::e((string) ($reply['tenant_name'] ?? 'Empresa')) ?></strong><small><?= View::e((string) ($reply['title'] ?? 'Comunicado')) ?> · <?= View::e((string) ($reply['created_at'] ?? '')) ?></small></div>
                    <span class="badge"><?= ($reply['direction'] ?? '') === 'tenant_to_rs' ? 'Cliente para RS' : 'RS para cliente' ?></span>
                </div>
                <p><?= nl2br(View::e((string) ($reply['message'] ?? ''))) ?></p>
                <?php if (($reply['direction'] ?? '') === 'tenant_to_rs'): ?>
                    <details class="communication-admin-reply-form">
                        <summary>Responder</summary>
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
