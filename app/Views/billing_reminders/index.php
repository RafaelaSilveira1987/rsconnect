<?php
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$money = static fn (mixed $v): string => 'R$ ' . number_format((float) $v, 2, ',', '.');
$date = static function (?string $v, bool $withTime = true): string {
    if (!$v) return '—';
    $t = strtotime($v);
    return $t ? date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $t) : $v;
};
$statusLabel = [
    'active' => 'Ativa', 'inactive' => 'Inativa', 'open' => 'Aberta', 'paid' => 'Paga',
    'overdue' => 'Em atraso', 'cancelled' => 'Cancelada', 'success' => 'Enviado',
    'sent' => 'Enviado', 'error' => 'Erro', 'ignored' => 'Ignorado', 'skipped' => 'Ignorado',
    'logged' => 'Registrado', 'queued' => 'Na fila', 'pending' => 'Pendente',
];
$active = count(array_filter($rules, static fn (array $r): bool => ($r['status'] ?? '') === 'active'));
$lifecycleActive = count(array_filter($lifecycleRules, static fn (array $r): bool => ($r['status'] ?? '') === 'active'));
$errors = count(array_filter(array_merge($logs, $lifecycleLogs), static fn (array $l): bool => ($l['status'] ?? '') === 'error'));
$upcoming = count($preview);
$externalRules = (int) ($automationState['external_rules'] ?? 0)
    + count(array_filter($lifecycleRules, static fn (array $r): bool => ($r['status'] ?? '') === 'active' && ($r['channel'] ?? '') === 'n8n'));
$activeN8nFlows = (int) ($automationState['active_n8n_flows'] ?? 0);
$platformInstanceId = (int) ($platformNotificationSettings['whatsapp_instance_id'] ?? 0);
$nativeWhatsappActive = count(array_filter($rules, static fn (array $r): bool => ($r['status'] ?? '') === 'active' && in_array(($r['channel'] ?? ''), ['native_whatsapp','native_both'], true)))
    + count(array_filter($lifecycleRules, static fn (array $r): bool => ($r['status'] ?? '') === 'active' && in_array(($r['channel'] ?? ''), ['native_whatsapp','native_both'], true)));
?>

<section class="admin-module-hero">
    <div>
        <span class="eyebrow">Comunicação financeira nativa</span>
        <h2>Lembretes financeiros e de vigência</h2>
        <p>Configure cobrança, vigência e período de teste sem depender do n8n. O canal externo continua disponível apenas quando você escolher usá-lo.</p>
    </div>
    <div class="admin-module-hero-actions">
        <form method="post" action="<?= View::e(Router::url('/billing-reminders/run')) ?>">
            <?= Csrf::input() ?>
            <button class="btn btn-outline" type="submit">Processar agora</button>
        </form>
        <button class="btn btn-primary" type="button" data-reminder-open="new" data-toggle-panel="reminder-drawer">Nova regra de cobrança</button>
    </div>
</section>

<section class="admin-module-summary">
    <article><span>Regras de cobrança</span><strong><?= count($rules) ?></strong><small><?= $active ?> ativa(s)</small></article>
    <article class="is-success"><span>Vigência e teste</span><strong><?= count($lifecycleRules) ?></strong><small><?= $lifecycleActive ?> ativa(s)</small></article>
    <article class="is-blue"><span>Próximas cobranças</span><strong><?= $upcoming ?></strong><small>na prévia operacional</small></article>
    <article class="is-warning"><span>Falhas recentes</span><strong><?= $errors ?></strong><small>nos dois históricos</small></article>
</section>

<section class="card admin-module-panel">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Canal da plataforma</span>
            <h2>WhatsApp usado pelo RS Connect</h2>
            <p>Selecione a conexão central que enviará cobranças e avisos de vigência para as empresas. O número da própria empresa cliente nunca é usado como remetente desses avisos.</p>
        </div>
        <span class="badge badge-<?= $platformInstanceId > 0 ? 'active' : 'inactive' ?>"><?= $platformInstanceId > 0 ? 'Configurado' : 'Não configurado' ?></span>
    </div>
    <form method="post" action="<?= View::e(Router::url('/billing-reminders/platform-settings/save')) ?>" class="drawer-form">
        <?= Csrf::input() ?>
        <div class="drawer-form-grid">
            <label class="field drawer-span">
                <span>Conexão WhatsApp da plataforma</span>
                <select name="whatsapp_instance_id">
                    <option value="0">Não usar WhatsApp nativo</option>
                    <?php foreach ($platformWhatsappInstances as $instance): ?>
                        <?php $instanceId = (int) ($instance['id'] ?? 0); ?>
                        <option value="<?= $instanceId ?>" <?= $instanceId === $platformInstanceId ? 'selected' : '' ?>>
                            <?= View::e(($instance['tenant_name'] ?? 'Empresa') . ' · ' . ($instance['name'] ?? $instance['instance_name'] ?? ('Instância #' . $instanceId)) . ' · ' . (($instance['status'] ?? '') === 'connected' ? 'Conectada' : 'Desconectada')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="field-hint">Para regras com “WhatsApp nativo”, a instância precisa estar conectada. O destinatário vem do WhatsApp comercial/telefone cadastrado da empresa cliente.</small>
            </label>
        </div>
        <div class="admin-record-actions"><button class="btn btn-primary" type="submit">Salvar canal da plataforma</button></div>
    </form>
</section>

<?php if ($nativeWhatsappActive > 0 && $platformInstanceId < 1): ?>
    <div class="operations-alert is-warning" style="margin-bottom:16px">
        <strong>Há regras de WhatsApp nativo sem um remetente configurado</strong>
        <p><?= $nativeWhatsappActive ?> regra(s) ativa(s) pedem envio por WhatsApp, mas nenhuma conexão central foi selecionada acima. Os avisos internos continuam funcionando; o WhatsApp permanece na fila de tentativas e se recupera automaticamente quando a conexão central for configurada/conectada.</p>
    </div>
<?php endif; ?>

<?php if ($externalRules > 0 && $activeN8nFlows < 1): ?>
    <div class="operations-alert is-warning" style="margin-bottom:16px">
        <strong>Existem regras que ainda usam integração externa</strong>
        <p><?= $externalRules ?> regra(s) ativa(s) ainda usam n8n/legado, mas não existe fluxo n8n ativo. Edite-as e escolha “RS Connect (interno)”, “WhatsApp nativo da plataforma” ou “RS Connect + WhatsApp nativo” para operar sem n8n.</p>
    </div>
<?php endif; ?>

<section class="card admin-module-panel">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Cobrança</span>
            <h2>Avisos programados</h2>
            <p>Os dias negativos representam antes do vencimento; zero é o dia; positivos são após. O momento e a mensagem vêm de cada regra.</p>
        </div>
        <span class="badge"><?= count($rules) ?> regra(s)</span>
    </div>
    <div class="admin-module-card-list compact-cards">
        <?php foreach ($rules as $rule): ?>
            <article class="admin-record-card">
                <div class="admin-record-main">
                    <span class="admin-record-mark is-reminder">D<?= (int) $rule['days_from_due'] ?></span>
                    <div class="admin-record-copy">
                        <div class="admin-record-title-row">
                            <div><h3><?= View::e($rule['label']) ?></h3><p><?= View::e($eventLabels[$rule['event_key']] ?? $rule['event_key']) ?></p></div>
                            <span class="badge badge-<?= View::e($rule['status']) ?>"><?= View::e($statusLabel[$rule['status']] ?? $rule['status']) ?></span>
                        </div>
                        <small class="admin-record-muted"><?= View::e($channelLabels[$rule['channel']] ?? $rule['channel']) ?> · <?= (int) $rule['days_from_due'] === 0 ? 'no vencimento' : ((int) $rule['days_from_due'] < 0 ? abs((int) $rule['days_from_due']) . ' dia(s) antes' : (int) $rule['days_from_due'] . ' dia(s) depois') ?></small>
                    </div>
                </div>
                <div class="admin-feature-tags">
                    <?php if ((int) ($rule['auto_mark_overdue'] ?? 0) === 1): ?><span>Marca cobrança em atraso</span><?php endif; ?>
                    <?php if ((int) ($rule['auto_suspend'] ?? 0) === 1): ?><span class="is-danger">Pode suspender assinatura</span><?php endif; ?>
                </div>
                <details class="admin-inline-details"><summary>Ver mensagem</summary><p><?= nl2br(View::e($rule['message_template'] ?? '')) ?></p></details>
                <div class="admin-record-actions">
                    <button class="btn btn-small btn-outline" type="button" data-toggle-panel="reminder-drawer" data-reminder-open="edit"
                        data-id="<?= (int) $rule['id'] ?>" data-label="<?= View::e($rule['label']) ?>" data-days="<?= (int) $rule['days_from_due'] ?>"
                        data-status="<?= View::e($rule['status']) ?>" data-event-key="<?= View::e($rule['event_key']) ?>" data-channel="<?= View::e($rule['channel']) ?>"
                        data-auto-overdue="<?= (int) ($rule['auto_mark_overdue'] ?? 0) ?>" data-auto-suspend="<?= (int) ($rule['auto_suspend'] ?? 0) ?>"
                        data-message="<?= View::e(rawurlencode((string) ($rule['message_template'] ?? ''))) ?>">Editar regra</button>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$rules): ?><div class="empty-state">Nenhuma regra de cobrança cadastrada.</div><?php endif; ?>
    </div>
</section>

<section class="card admin-module-panel">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Vigência, teste e tolerância</span>
            <h2>Avisos do ciclo da assinatura</h2>
            <p>Crie avisos independentes para o fim da vigência, fim do trial e fim da tolerância. As regras são globais da plataforma e não possuem prazos fixos no código.</p>
        </div>
        <div class="admin-module-hero-actions">
            <span class="badge"><?= count($lifecycleRules) ?> regra(s)</span>
            <button class="btn btn-outline" type="button" data-lifecycle-open="new" data-toggle-panel="lifecycle-reminder-drawer">Nova regra de vigência</button>
        </div>
    </div>
    <div class="admin-module-card-list compact-cards">
        <?php foreach ($lifecycleRules as $rule): ?>
            <article class="admin-record-card">
                <div class="admin-record-main">
                    <span class="admin-record-mark is-reminder">D<?= (int) $rule['days_from_target'] ?></span>
                    <div class="admin-record-copy">
                        <div class="admin-record-title-row">
                            <div><h3><?= View::e($rule['label']) ?></h3><p><?= View::e($lifecycleTargetLabels[$rule['target_date']] ?? $rule['target_date']) ?></p></div>
                            <span class="badge badge-<?= View::e($rule['status']) ?>"><?= View::e($statusLabel[$rule['status']] ?? $rule['status']) ?></span>
                        </div>
                        <small class="admin-record-muted"><?= View::e($lifecycleChannelLabels[$rule['channel']] ?? $rule['channel']) ?> · <?= (int) $rule['days_from_target'] === 0 ? 'na data-alvo' : ((int) $rule['days_from_target'] < 0 ? abs((int) $rule['days_from_target']) . ' dia(s) antes' : (int) $rule['days_from_target'] . ' dia(s) depois') ?></small>
                    </div>
                </div>
                <details class="admin-inline-details"><summary>Ver mensagem</summary><p><?= nl2br(View::e($rule['message_template'] ?? '')) ?></p></details>
                <div class="admin-record-actions">
                    <button class="btn btn-small btn-outline" type="button" data-toggle-panel="lifecycle-reminder-drawer" data-lifecycle-open="edit"
                        data-id="<?= (int) $rule['id'] ?>" data-label="<?= View::e($rule['label']) ?>" data-days="<?= (int) $rule['days_from_target'] ?>"
                        data-status="<?= View::e($rule['status']) ?>" data-target-date="<?= View::e($rule['target_date']) ?>" data-channel="<?= View::e($rule['channel']) ?>"
                        data-message="<?= View::e(rawurlencode((string) ($rule['message_template'] ?? ''))) ?>">Editar regra</button>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$lifecycleRules): ?><div class="empty-state">Execute a migration 125 para habilitar regras de vigência e teste.</div><?php endif; ?>
    </div>
</section>

<div class="admin-dashboard-grid">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Prévia operacional</span><h2>Próximas cobranças</h2></div><span class="badge"><?= count($preview) ?> cobrança(s)</span></div>
        <div class="admin-log-list">
            <?php foreach ($preview as $invoice): ?>
                <article class="admin-log-item"><div><strong><?= View::e($invoice['tenant_name']) ?> · <?= View::e($invoice['invoice_number']) ?></strong><span><?= View::e($money($invoice['amount'])) ?> · vence <?= View::e($date($invoice['due_date'], false)) ?></span><small><?= (int) $invoice['days_from_due'] === 0 ? 'Hoje' : ((int) $invoice['days_from_due'] < 0 ? abs((int) $invoice['days_from_due']) . ' dia(s) antes' : (int) $invoice['days_from_due'] . ' dia(s) após') ?></small></div><div><span class="badge badge-<?= View::e($invoice['status']) ?>"><?= View::e($statusLabel[$invoice['status']] ?? $invoice['status']) ?></span><?php $link = $invoice['external_checkout_url'] ?: $invoice['external_invoice_url']; if ($link): ?><a class="btn btn-small btn-outline" target="_blank" rel="noopener" href="<?= View::e($link) ?>">Abrir link</a><?php endif; ?></div></article>
            <?php endforeach; ?>
            <?php if (!$preview): ?><div class="empty-state">Nenhuma cobrança no período.</div><?php endif; ?>
        </div>
    </section>
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Histórico financeiro</span><h2>Últimos avisos de cobrança</h2></div><span class="badge"><?= count($logs) ?> registro(s)</span></div>
        <div class="admin-log-list">
            <?php foreach ($logs as $log): ?>
                <article class="admin-log-item is-<?= View::e($log['status']) ?>"><div><strong><?= View::e($log['tenant_name'] ?? '—') ?> · <?= View::e($log['rule_label'] ?? 'Regra') ?></strong><span><?= View::e($log['invoice_number'] ?? '—') ?> · <?= View::e($money($log['amount'] ?? 0)) ?></span><small><?= View::e($date($log['processed_at'] ?? $log['created_at'] ?? null)) ?></small></div><span class="badge badge-<?= View::e($log['status']) ?>"><?= View::e($statusLabel[$log['status']] ?? $log['status']) ?></span></article>
            <?php endforeach; ?>
            <?php if (!$logs): ?><div class="empty-state">Nenhum aviso de cobrança processado.</div><?php endif; ?>
        </div>
    </section>
</div>

<section class="card admin-module-panel">
    <div class="section-heading"><div><span class="eyebrow">Histórico de ciclo</span><h2>Últimos avisos de vigência e teste</h2></div><span class="badge"><?= count($lifecycleLogs) ?> registro(s)</span></div>
    <div class="admin-log-list">
        <?php foreach ($lifecycleLogs as $log): ?>
            <article class="admin-log-item is-<?= View::e($log['status']) ?>">
                <div><strong><?= View::e($log['tenant_name'] ?? '—') ?> · <?= View::e($log['rule_label'] ?? 'Regra') ?></strong><span><?= View::e($log['plan_name'] ?? 'Plano') ?> · <?= View::e($lifecycleTargetLabels[$log['target_date'] ?? ''] ?? ($log['target_date'] ?? '')) ?></span><small><?= View::e($date($log['processed_at'] ?? $log['created_at'] ?? null)) ?></small></div>
                <span class="badge badge-<?= View::e($log['status']) ?>"><?= View::e($statusLabel[$log['status']] ?? $log['status']) ?></span>
            </article>
        <?php endforeach; ?>
        <?php if (!$lifecycleLogs): ?><div class="empty-state">Nenhum aviso de vigência/teste processado.</div><?php endif; ?>
    </div>
</section>

<aside class="conversation-details conversation-drawer admin-form-drawer" id="reminder-drawer" aria-label="Configurar regra de cobrança" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header"><div><span class="eyebrow" data-reminder-eyebrow>Nova regra</span><h2 data-reminder-title>Criar aviso de cobrança</h2><p>O prazo, o canal e a mensagem ficam nesta configuração; o worker apenas executa o que estiver salvo.</p></div><button class="icon-button drawer-close" type="button" data-close-panel="reminder-drawer">×</button></div>
    <div class="conversation-drawer-body">
        <form class="drawer-form" method="post" action="<?= View::e(Router::url('/billing-reminders/rules/save')) ?>" data-reminder-form>
            <?= Csrf::input() ?><input type="hidden" name="id" value="0" data-reminder-field="id">
            <section class="drawer-section"><div class="drawer-form-grid">
                <label class="field drawer-span"><span>Nome da regra</span><input name="label" data-reminder-field="label" placeholder="3 dias antes do vencimento" required></label>
                <label class="field"><span>Dias em relação ao vencimento</span><input type="number" name="days_from_due" value="-3" data-reminder-field="days_from_due"></label>
                <label class="field"><span>Situação</span><select name="status" data-reminder-field="status"><option value="active">Ativa</option><option value="inactive">Inativa</option></select></label>
                <label class="field drawer-span"><span>Evento</span><select name="event_key" data-reminder-field="event_key"><?php foreach ($eventLabels as $key => $label): ?><option value="<?= View::e($key) ?>"><?= View::e($label) ?></option><?php endforeach; ?></select></label>
                <label class="field drawer-span"><span>Canal</span><select name="channel" data-reminder-field="channel"><?php foreach ($channelLabels as $key => $label): ?><option value="<?= View::e($key) ?>"><?= View::e($label) ?></option><?php endforeach; ?></select><small class="field-hint">Prefira os canais nativos. n8n permanece apenas como integração opcional.</small></label>
                <label class="check-field drawer-span"><input type="checkbox" name="auto_mark_overdue" value="1" data-reminder-field="auto_mark_overdue"><span>Marcar a cobrança como em atraso quando aplicável</span></label>
                <label class="check-field drawer-span"><input type="checkbox" name="auto_suspend" value="1" data-reminder-field="auto_suspend"><span>Suspender a assinatura quando aplicável</span></label>
                <label class="field drawer-span"><span>Mensagem enviada</span><textarea name="message_template" rows="7" data-reminder-field="message_template">Olá, {{empresa}}. Sua cobrança {{invoice_number}} no valor de {{valor}} vence em {{vencimento}}. Link: {{link_pagamento}}</textarea><small class="field-hint">Variáveis: {{empresa}}, {{invoice_number}}, {{valor}}, {{vencimento}}, {{link_pagamento}}, {{dias}}</small></label>
            </div></section>
            <div class="drawer-savebar"><button class="btn btn-quiet" type="button" data-close-panel="reminder-drawer">Cancelar</button><button class="btn btn-primary" type="submit" data-reminder-submit>Salvar regra</button></div>
        </form>
    </div>
</aside>

<aside class="conversation-details conversation-drawer admin-form-drawer" id="lifecycle-reminder-drawer" aria-label="Configurar regra de vigência e teste" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header"><div><span class="eyebrow" data-lifecycle-eyebrow>Nova regra</span><h2 data-lifecycle-title>Criar aviso de vigência</h2><p>Escolha a data que será usada como referência e quantos dias antes ou depois o aviso deve sair.</p></div><button class="icon-button drawer-close" type="button" data-close-panel="lifecycle-reminder-drawer">×</button></div>
    <div class="conversation-drawer-body">
        <form class="drawer-form" method="post" action="<?= View::e(Router::url('/billing-reminders/lifecycle-rules/save')) ?>" data-lifecycle-form>
            <?= Csrf::input() ?><input type="hidden" name="id" value="0" data-lifecycle-field="id">
            <section class="drawer-section"><div class="drawer-form-grid">
                <label class="field drawer-span"><span>Nome da regra</span><input name="label" data-lifecycle-field="label" placeholder="7 dias antes do fim da vigência" required></label>
                <label class="field drawer-span"><span>Data de referência</span><select name="target_date" data-lifecycle-field="target_date"><?php foreach ($lifecycleTargetLabels as $key => $label): ?><option value="<?= View::e($key) ?>"><?= View::e($label) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Dias em relação à data</span><input type="number" min="-365" max="365" name="days_from_target" value="-7" data-lifecycle-field="days_from_target"></label>
                <label class="field"><span>Situação</span><select name="status" data-lifecycle-field="status"><option value="active">Ativa</option><option value="inactive">Inativa</option></select></label>
                <label class="field drawer-span"><span>Canal</span><select name="channel" data-lifecycle-field="channel"><?php foreach ($lifecycleChannelLabels as $key => $label): ?><option value="<?= View::e($key) ?>"><?= View::e($label) ?></option><?php endforeach; ?></select></label>
                <label class="field drawer-span"><span>Mensagem enviada</span><textarea name="message_template" rows="7" data-lifecycle-field="message_template">Olá, {{empresa}}. A vigência atual do plano {{plano}} termina em {{fim_vigencia}}. Faltam {{dias}} dia(s).</textarea><small class="field-hint">Variáveis: {{empresa}}, {{plano}}, {{fim_vigencia}}, {{fim_teste}}, {{fim_tolerancia}}, {{dias}}, {{status}}, {{valor}}</small></label>
            </div></section>
            <div class="drawer-savebar"><button class="btn btn-quiet" type="button" data-close-panel="lifecycle-reminder-drawer">Cancelar</button><button class="btn btn-primary" type="submit" data-lifecycle-submit>Salvar regra</button></div>
        </form>
    </div>
</aside>
