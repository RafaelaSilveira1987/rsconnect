<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$money = static fn (mixed $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$date = static function (?string $value): string {
    if (!$value) return '—';
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : $value;
};
$statusLabel = [
    'active' => 'Ativo', 'inactive' => 'Inativo', 'sandbox' => 'Sandbox', 'production' => 'Produção',
    'open' => 'Aberta', 'paid' => 'Paga', 'overdue' => 'Em atraso', 'cancelled' => 'Cancelada',
    'success' => 'Sucesso', 'error' => 'Erro', 'ignored' => 'Ignorado',
];
?>

<div class="content-grid management-layout payment-gateway-page">
    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Financeiro SaaS</span><h2>Gateways cadastrados</h2></div>
            <span class="badge"><?= count($gateways) ?> gateway(s)</span>
        </div>
        <p class="muted">Cadastre uma conta da RS Automação Digital para gerar links de pagamento das cobranças do SaaS. As chaves ficam criptografadas usando o APP_KEY atual.</p>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Gateway</th><th>Ambiente</th><th>Método padrão</th><th>Webhooks</th><th>Status</th><th>Ações</th></tr></thead>
                <tbody>
                <?php foreach ($gateways as $gateway): ?>
                    <tr>
                        <td><strong><?= View::e($gateway['label']) ?></strong><?= (int) $gateway['is_default'] === 1 ? ' <span class="badge badge-active">Padrão</span>' : '' ?><br><small><?= View::e($providerLabels[$gateway['provider']] ?? $gateway['provider']) ?></small></td>
                        <td><span class="badge badge-<?= View::e($gateway['environment']) ?>"><?= View::e($statusLabel[$gateway['environment']] ?? $gateway['environment']) ?></span><br><small><?= View::e($gateway['api_base_url'] ?: 'URL padrão do provedor') ?></small></td>
                        <td><?= View::e($methodLabels[$gateway['default_payment_method']] ?? $gateway['default_payment_method']) ?></td>
                        <td>
                            <?php if ($gateway['provider'] === 'asaas'): ?>
                                <code><?= View::e(Router::url('/webhooks/payments/asaas')) ?></code>
                            <?php elseif ($gateway['provider'] === 'mercadopago'): ?>
                                <code><?= View::e(Router::url('/webhooks/payments/mercadopago')) ?></code>
                            <?php elseif ($gateway['provider'] === 'stripe'): ?>
                                <code><?= View::e(Router::url('/webhooks/payments/stripe')) ?></code>
                            <?php else: ?>
                                <small>Manual</small>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-<?= View::e($gateway['status']) ?>"><?= View::e($statusLabel[$gateway['status']] ?? $gateway['status']) ?></span></td>
                        <td>
                            <details>
                                <summary class="btn btn-small btn-outline">Editar</summary>
                                <form class="stack compact-form" method="post" action="<?= View::e(Router::url('/payment-gateways/save')) ?>">
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="id" value="<?= (int) $gateway['id'] ?>">
                                    <div class="form-grid two">
                                        <label class="field compact-field"><span>Nome</span><input name="label" value="<?= View::e($gateway['label']) ?>" required></label>
                                        <label class="field compact-field"><span>Provedor</span><select name="provider"><?php foreach ($providerLabels as $key => $label): ?><option value="<?= View::e($key) ?>" <?= $gateway['provider'] === $key ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select></label>
                                    </div>
                                    <div class="form-grid two">
                                        <label class="field compact-field"><span>Ambiente</span><select name="environment"><option value="production" <?= $gateway['environment'] === 'production' ? 'selected' : '' ?>>Produção</option><option value="sandbox" <?= $gateway['environment'] === 'sandbox' ? 'selected' : '' ?>>Sandbox</option></select></label>
                                        <label class="field compact-field"><span>Status</span><select name="status"><option value="active" <?= $gateway['status'] === 'active' ? 'selected' : '' ?>>Ativo</option><option value="inactive" <?= $gateway['status'] === 'inactive' ? 'selected' : '' ?>>Inativo</option></select></label>
                                    </div>
                                    <label class="field compact-field"><span>URL base da API</span><input name="api_base_url" value="<?= View::e($gateway['api_base_url'] ?? '') ?>" placeholder="Deixe vazio para usar o padrão"></label>
                                    <label class="field compact-field"><span>API Key / Access Token</span><input name="api_key" type="password" placeholder="Deixe vazio para manter a atual"></label>
                                    <label class="field compact-field"><span>Public key / identificador público</span><input name="public_key" value="<?= View::e($gateway['public_key'] ?? '') ?>"></label>
                                    <label class="field compact-field"><span>Token interno para webhook</span><input name="webhook_secret" type="password" placeholder="Opcional. Deixe vazio para manter atual"></label>
                                    <label class="field compact-field"><span>Método padrão</span><select name="default_payment_method"><?php foreach ($methodLabels as $key => $label): ?><option value="<?= View::e($key) ?>" <?= $gateway['default_payment_method'] === $key ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select></label>
                                    <label class="check-row"><input type="checkbox" name="is_default" value="1" <?= (int) $gateway['is_default'] === 1 ? 'checked' : '' ?>> Usar como gateway padrão</label>
                                    <label class="field compact-field"><span>Observações</span><textarea name="notes" rows="3"><?= View::e($gateway['notes'] ?? '') ?></textarea></label>
                                    <button class="btn btn-primary btn-block" type="submit">Salvar gateway</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$gateways): ?><tr><td colspan="6"><div class="empty-state">Nenhum gateway cadastrado ainda.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="stack">
        <form class="card sticky-card" method="post" action="<?= View::e(Router::url('/payment-gateways/save')) ?>">
            <?= Csrf::input() ?>
            <div class="section-heading"><div><span class="eyebrow">Novo gateway</span><h2>Configurar pagamento</h2></div></div>
            <label class="field"><span>Nome interno</span><input name="label" placeholder="Asaas RS Produção" required></label>
            <label class="field"><span>Provedor</span><select name="provider"><?php foreach ($providerLabels as $key => $label): ?><option value="<?= View::e($key) ?>"><?= View::e($label) ?></option><?php endforeach; ?></select></label>
            <div class="form-grid two"><label class="field"><span>Ambiente</span><select name="environment"><option value="production">Produção</option><option value="sandbox">Sandbox</option></select></label><label class="field"><span>Método</span><select name="default_payment_method"><?php foreach ($methodLabels as $key => $label): ?><option value="<?= View::e($key) ?>"><?= View::e($label) ?></option><?php endforeach; ?></select></label></div>
            <label class="field"><span>URL base da API</span><input name="api_base_url" placeholder="Opcional"></label>
            <label class="field"><span>API Key / Access Token</span><input name="api_key" type="password" autocomplete="off"></label>
            <label class="field"><span>Public key</span><input name="public_key" autocomplete="off"></label>
            <label class="field"><span>Token interno para webhook</span><input name="webhook_secret" type="password" autocomplete="off"></label>
            <label class="check-row"><input type="checkbox" name="is_default" value="1" checked> Usar como gateway padrão</label>
            <input type="hidden" name="status" value="active">
            <button class="btn btn-primary btn-block" type="submit">Salvar gateway</button>
        </form>

        <section class="card">
            <div class="section-heading"><div><span class="eyebrow">URLs de webhook</span><h2>Configurar no provedor</h2></div></div>
            <p class="muted">Use a URL correspondente ao gateway. O campo token é opcional e serve para proteção interna do RS Connect.</p>
            <div class="stack compact-form">
                <label class="field compact-field"><span>Asaas</span><input readonly value="<?= View::e(Router::url('/webhooks/payments/asaas')) ?>"></label>
                <label class="field compact-field"><span>Mercado Pago</span><input readonly value="<?= View::e(Router::url('/webhooks/payments/mercadopago')) ?>"></label>
                <label class="field compact-field"><span>Stripe</span><input readonly value="<?= View::e(Router::url('/webhooks/payments/stripe')) ?>"></label>
            </div>
        </section>
    </aside>
</div>

<section class="card table-card">
    <div class="section-heading"><div><span class="eyebrow">Cobranças</span><h2>Gerar links de pagamento</h2></div><span class="badge"><?= count($invoices) ?> cobrança(s)</span></div>
    <div class="table-wrap"><table class="clean-table"><thead><tr><th>Cobrança</th><th>Cliente</th><th>Valor</th><th>Status</th><th>Gateway</th><th>Link</th><th>Ações</th></tr></thead><tbody>
    <?php foreach ($invoices as $invoice): ?>
        <tr>
            <td><strong><?= View::e($invoice['invoice_number']) ?></strong><br><small><?= View::e(date('d/m/Y', strtotime($invoice['period_start']))) ?> a <?= View::e(date('d/m/Y', strtotime($invoice['period_end']))) ?></small></td>
            <td><?= View::e($invoice['tenant_name']) ?><br><small><?= View::e($invoice['tenant_email'] ?? '') ?></small></td>
            <td><strong><?= View::e($money($invoice['amount'])) ?></strong><br><small>Vence <?= View::e(date('d/m/Y', strtotime($invoice['due_date']))) ?></small></td>
            <td><span class="badge badge-<?= View::e($invoice['status']) ?>"><?= View::e($statusLabel[$invoice['status']] ?? $invoice['status']) ?></span><br><small><?= View::e($invoice['external_status'] ?? '') ?></small></td>
            <td><?= View::e($invoice['gateway_label'] ?? $invoice['gateway_provider'] ?? '—') ?></td>
            <td>
                <?php $link = $invoice['external_checkout_url'] ?: $invoice['external_invoice_url']; ?>
                <?php if ($link): ?><a class="btn btn-small btn-outline" href="<?= View::e($link) ?>" target="_blank" rel="noopener">Abrir link</a><?php else: ?><small>Sem link</small><?php endif; ?>
            </td>
            <td>
                <?php if ($invoice['status'] !== 'paid'): ?>
                <form class="inline-form" method="post" action="<?= View::e(Router::url('/payment-gateways/invoices/create-link')) ?>">
                    <?= Csrf::input() ?>
                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                    <select name="gateway_id" aria-label="Gateway"><option value="">Gateway padrão</option><?php foreach ($gateways as $gateway): ?><option value="<?= (int) $gateway['id'] ?>"><?= View::e($gateway['label']) ?></option><?php endforeach; ?></select>
                    <select name="payment_method" aria-label="Método"><?php foreach ($methodLabels as $key => $label): ?><option value="<?= View::e($key) ?>"><?= View::e($label) ?></option><?php endforeach; ?></select>
                    <button class="btn btn-small btn-primary" type="submit">Gerar link</button>
                </form>
                <?php else: ?>
                    <small>Pago</small>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$invoices): ?><tr><td colspan="7"><div class="empty-state">Nenhuma cobrança criada.</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section class="card table-card">
    <div class="section-heading"><div><span class="eyebrow">Auditoria</span><h2>Eventos dos gateways</h2></div><span class="badge"><?= count($events) ?> evento(s)</span></div>
    <div class="table-wrap"><table class="clean-table"><thead><tr><th>Data</th><th>Gateway</th><th>Empresa</th><th>Evento</th><th>Status</th><th>Externo</th></tr></thead><tbody>
    <?php foreach ($events as $event): ?>
        <tr><td><?= View::e($date($event['created_at'])) ?></td><td><?= View::e($event['gateway_label'] ?? $providerLabels[$event['provider'] ?? ''] ?? '—') ?></td><td><?= View::e($event['tenant_name'] ?? '—') ?></td><td><code><?= View::e($event['event']) ?></code></td><td><span class="badge badge-<?= View::e($event['status']) ?>"><?= View::e($statusLabel[$event['status']] ?? $event['status']) ?></span></td><td><?= View::e($event['external_id'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$events): ?><tr><td colspan="6"><div class="empty-state">Nenhum evento de pagamento ainda.</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
