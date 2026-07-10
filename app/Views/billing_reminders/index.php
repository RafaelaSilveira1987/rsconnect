<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$money = static fn (mixed $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$date = static function (?string $value): string {
    if (!$value) return '—';
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y', $timestamp) : $value;
};
$statusLabel = [
    'active' => 'Ativa', 'inactive' => 'Inativa',
    'pending' => 'Pendente', 'sent' => 'Enviado', 'error' => 'Erro', 'logged' => 'Registrado',
    'open' => 'Aberta', 'overdue' => 'Em atraso', 'paid' => 'Paga', 'cancelled' => 'Cancelada',
];
?>

<div class="content-grid management-layout billing-reminders-page">
    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Financeiro SaaS</span><h2>Régua de cobrança</h2></div>
            <form method="post" action="<?= View::e(Router::url('/billing-reminders/run')) ?>">
                <?= Csrf::input() ?>
                <button class="btn btn-primary" type="submit">Processar agora</button>
            </form>
        </div>
        <p class="muted">As regras abaixo verificam cobranças abertas/em atraso e disparam eventos para os fluxos n8n da própria empresa. Use eventos como <code>billing.*</code> nos Fluxos n8n para capturar os avisos.</p>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Regra</th><th>Quando</th><th>Evento</th><th>Canal</th><th>Ações automáticas</th><th>Status</th><th>Editar</th></tr></thead>
                <tbody>
                <?php foreach ($rules as $rule): ?>
                    <tr>
                        <td><strong><?= View::e($rule['label']) ?></strong><br><small><?= View::e(mb_substr((string) ($rule['message_template'] ?? ''), 0, 90)) ?></small></td>
                        <td>
                            <?php if ((int) $rule['days_from_due'] < 0): ?>
                                <?= abs((int) $rule['days_from_due']) ?> dia(s) antes
                            <?php elseif ((int) $rule['days_from_due'] === 0): ?>
                                No vencimento
                            <?php else: ?>
                                <?= (int) $rule['days_from_due'] ?> dia(s) após
                            <?php endif; ?>
                        </td>
                        <td><code><?= View::e($rule['event_key']) ?></code></td>
                        <td><?= View::e($channelLabels[$rule['channel']] ?? $rule['channel']) ?></td>
                        <td>
                            <?= (int) ($rule['auto_mark_overdue'] ?? 0) === 1 ? '<span class="badge badge-overdue">Marca atraso</span>' : '<small>—</small>' ?>
                            <?= (int) ($rule['auto_suspend'] ?? 0) === 1 ? '<span class="badge badge-error">Suspende</span>' : '' ?>
                        </td>
                        <td><span class="badge badge-<?= View::e($rule['status']) ?>"><?= View::e($statusLabel[$rule['status']] ?? $rule['status']) ?></span></td>
                        <td>
                            <details>
                                <summary class="btn btn-small btn-outline">Editar</summary>
                                <form class="stack compact-form" method="post" action="<?= View::e(Router::url('/billing-reminders/rules/save')) ?>">
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                                    <label class="field compact-field"><span>Nome</span><input name="label" value="<?= View::e($rule['label']) ?>" required></label>
                                    <div class="form-grid two">
                                        <label class="field compact-field"><span>Dias do vencimento</span><input type="number" name="days_from_due" value="<?= (int) $rule['days_from_due'] ?>"></label>
                                        <label class="field compact-field"><span>Status</span><select name="status"><option value="active" <?= $rule['status'] === 'active' ? 'selected' : '' ?>>Ativa</option><option value="inactive" <?= $rule['status'] === 'inactive' ? 'selected' : '' ?>>Inativa</option></select></label>
                                    </div>
                                    <label class="field compact-field"><span>Evento</span><select name="event_key"><?php foreach ($eventLabels as $key => $label): ?><option value="<?= View::e($key) ?>" <?= $rule['event_key'] === $key ? 'selected' : '' ?>><?= View::e($label) ?> — <?= View::e($key) ?></option><?php endforeach; ?></select></label>
                                    <label class="field compact-field"><span>Canal</span><select name="channel"><?php foreach ($channelLabels as $key => $label): ?><option value="<?= View::e($key) ?>" <?= $rule['channel'] === $key ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select></label>
                                    <label class="check-row"><input type="checkbox" name="auto_mark_overdue" value="1" <?= (int) ($rule['auto_mark_overdue'] ?? 0) === 1 ? 'checked' : '' ?>> Marcar cobrança como em atraso quando aplicável</label>
                                    <label class="check-row"><input type="checkbox" name="auto_suspend" value="1" <?= (int) ($rule['auto_suspend'] ?? 0) === 1 ? 'checked' : '' ?>> Suspender assinatura quando aplicável</label>
                                    <label class="field compact-field"><span>Mensagem</span><textarea name="message_template" rows="4"><?= View::e($rule['message_template'] ?? '') ?></textarea></label>
                                    <button class="btn btn-primary btn-block" type="submit">Salvar regra</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rules): ?><tr><td colspan="7"><div class="empty-state">Nenhuma regra cadastrada.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="stack">
        <form class="card sticky-card" method="post" action="<?= View::e(Router::url('/billing-reminders/rules/save')) ?>">
            <?= Csrf::input() ?>
            <div class="section-heading"><div><span class="eyebrow">Nova regra</span><h2>Criar aviso</h2></div></div>
            <label class="field"><span>Nome</span><input name="label" placeholder="3 dias antes do vencimento" required></label>
            <div class="form-grid two">
                <label class="field"><span>Dias do vencimento</span><input type="number" name="days_from_due" value="-3"><small>Use negativo antes, zero no dia, positivo depois.</small></label>
                <label class="field"><span>Status</span><select name="status"><option value="active">Ativa</option><option value="inactive">Inativa</option></select></label>
            </div>
            <label class="field"><span>Evento</span><select name="event_key"><?php foreach ($eventLabels as $key => $label): ?><option value="<?= View::e($key) ?>"><?= View::e($label) ?> — <?= View::e($key) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Canal</span><select name="channel"><?php foreach ($channelLabels as $key => $label): ?><option value="<?= View::e($key) ?>"><?= View::e($label) ?></option><?php endforeach; ?></select></label>
            <label class="check-row"><input type="checkbox" name="auto_mark_overdue" value="1"> Marcar atraso quando aplicável</label>
            <label class="check-row"><input type="checkbox" name="auto_suspend" value="1"> Suspender assinatura quando aplicável</label>
            <label class="field"><span>Mensagem</span><textarea name="message_template" rows="5">Olá, {{empresa}}. Sua cobrança {{invoice_number}} no valor de {{valor}} vence em {{vencimento}}. Link: {{link_pagamento}}</textarea></label>
            <button class="btn btn-primary btn-block" type="submit">Criar regra</button>
        </form>

        <section class="card">
            <div class="section-heading"><div><span class="eyebrow">Variáveis</span><h2>Modelo de mensagem</h2></div></div>
            <p class="muted">Você pode usar:</p>
            <ul class="compact-list">
                <li><code>{{empresa}}</code></li>
                <li><code>{{invoice_number}}</code></li>
                <li><code>{{valor}}</code></li>
                <li><code>{{vencimento}}</code></li>
                <li><code>{{link_pagamento}}</code></li>
                <li><code>{{dias}}</code></li>
            </ul>
        </section>
    </aside>
</div>

<section class="card table-card">
    <div class="section-heading"><div><span class="eyebrow">Próximas cobranças</span><h2>Prévia operacional</h2></div><span class="badge"><?= count($preview) ?> cobrança(s)</span></div>
    <div class="table-wrap"><table class="clean-table"><thead><tr><th>Cobrança</th><th>Empresa</th><th>Valor</th><th>Vencimento</th><th>Dias</th><th>Status</th><th>Link</th></tr></thead><tbody>
    <?php foreach ($preview as $invoice): ?>
        <tr>
            <td><strong><?= View::e($invoice['invoice_number']) ?></strong></td>
            <td><?= View::e($invoice['tenant_name']) ?><br><small><?= View::e($invoice['tenant_email'] ?? '') ?></small></td>
            <td><?= View::e($money($invoice['amount'])) ?></td>
            <td><?= View::e($date($invoice['due_date'])) ?></td>
            <td><?= (int) $invoice['days_from_due'] === 0 ? 'Hoje' : ((int) $invoice['days_from_due'] < 0 ? abs((int) $invoice['days_from_due']) . ' antes' : (int) $invoice['days_from_due'] . ' após') ?></td>
            <td><span class="badge badge-<?= View::e($invoice['status']) ?>"><?= View::e($statusLabel[$invoice['status']] ?? $invoice['status']) ?></span></td>
            <td><?php $link = $invoice['external_checkout_url'] ?: $invoice['external_invoice_url']; ?><?= $link ? '<a class="btn btn-small btn-outline" target="_blank" rel="noopener" href="' . View::e($link) . '">Abrir</a>' : '<small>Sem link</small>' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$preview): ?><tr><td colspan="7"><div class="empty-state">Nenhuma cobrança no período de prévia.</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section class="card table-card">
    <div class="section-heading"><div><span class="eyebrow">Histórico</span><h2>Envios da régua</h2></div><span class="badge"><?= count($logs) ?> registro(s)</span></div>
    <div class="table-wrap"><table class="clean-table"><thead><tr><th>Data</th><th>Empresa</th><th>Cobrança</th><th>Regra</th><th>Status</th><th>Processado</th></tr></thead><tbody>
    <?php foreach ($logs as $log): ?>
        <tr>
            <td><?= View::e($date($log['created_at'])) ?></td>
            <td><?= View::e($log['tenant_name'] ?? '—') ?></td>
            <td><?= View::e($log['invoice_number'] ?? '—') ?><br><small><?= View::e($money($log['amount'] ?? 0)) ?> · vence <?= View::e($date($log['due_date'] ?? null)) ?></small></td>
            <td><?= View::e($log['rule_label'] ?? '—') ?></td>
            <td><span class="badge badge-<?= View::e($log['status']) ?>"><?= View::e($statusLabel[$log['status']] ?? $log['status']) ?></span></td>
            <td><?= View::e($date($log['processed_at'] ?? null)) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$logs): ?><tr><td colspan="6"><div class="empty-state">Nenhum aviso processado ainda.</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
