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
    'active' => 'Ativa', 'inactive' => 'Inativa', 'suspended' => 'Suspensa',
    'trialing' => 'Teste', 'overdue' => 'Em atraso', 'canceled' => 'Cancelada',
    'open' => 'Aberta', 'paid' => 'Paga', 'cancelled' => 'Cancelada',
    'monthly' => 'Mensal', 'quarterly' => 'Trimestral', 'semiannual' => 'Semestral', 'annual' => 'Anual',
];
?>

<div class="content-grid management-layout billing-page">
    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Comercial SaaS</span><h2>Planos cadastrados</h2></div>
            <div class="inline-actions"><a class="btn btn-small btn-outline" href="<?= View::e(Router::url('/payment-gateways')) ?>">Gateways de pagamento</a><span class="badge"><?= count($plans) ?> plano(s)</span></div>
        </div>
        <p class="muted">Configure limites e recursos por plano. Esses limites aparecem para o cliente e servem como controle comercial da assinatura.</p>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Plano</th><th>Valor</th><th>Limites principais</th><th>Recursos</th><th>Status</th><th>Ações</th></tr></thead>
                <tbody>
                <?php foreach ($plans as $plan): ?>
                    <tr>
                        <td><strong><?= View::e($plan['name']) ?></strong><br><small><?= View::e($plan['plan_key']) ?></small><?php if (!empty($plan['description'])): ?><br><small><?= View::e($plan['description']) ?></small><?php endif; ?></td>
                        <td><strong><?= View::e($money($plan['monthly_price'])) ?></strong><br><small>mensal</small></td>
                        <td>
                            <small>Usuários: <?= View::e((string) ($plan['limits']['users'] ?? '∞')) ?></small><br>
                            <small>Instâncias: <?= View::e((string) ($plan['limits']['instances'] ?? '∞')) ?></small><br>
                            <small>Agentes: <?= View::e((string) ($plan['limits']['agents'] ?? '∞')) ?></small><br>
                            <small>Mensagens/mês: <?= View::e((string) ($plan['limits']['messages_month'] ?? '∞')) ?></small>
                        </td>
                        <td><small><?= View::e(implode(' · ', array_slice($plan['features'] ?? [], 0, 4)) ?: '—') ?></small></td>
                        <td><span class="badge badge-<?= View::e($plan['status']) ?>"><?= View::e($statusLabel[$plan['status']] ?? $plan['status']) ?></span></td>
                        <td>
                            <details>
                                <summary class="btn btn-small btn-outline">Editar</summary>
                                <form class="stack compact-form" method="post" action="<?= View::e(Router::url('/billing/plans/save')) ?>">
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="id" value="<?= (int) $plan['id'] ?>">
                                    <div class="form-grid two">
                                        <label class="field compact-field"><span>Identificador</span><input name="plan_key" value="<?= View::e($plan['plan_key']) ?>" required></label>
                                        <label class="field compact-field"><span>Status</span><select name="status"><option value="active" <?= $plan['status'] === 'active' ? 'selected' : '' ?>>Ativo</option><option value="inactive" <?= $plan['status'] === 'inactive' ? 'selected' : '' ?>>Inativo</option></select></label>
                                    </div>
                                    <label class="field compact-field"><span>Nome</span><input name="name" value="<?= View::e($plan['name']) ?>" required></label>
                                    <label class="field compact-field"><span>Descrição</span><input name="description" value="<?= View::e($plan['description'] ?? '') ?>"></label>
                                    <div class="form-grid two"><label class="field compact-field"><span>Valor mensal</span><input name="monthly_price" value="<?= View::e(number_format((float) $plan['monthly_price'], 2, ',', '.')) ?>"></label><label class="field compact-field"><span>Ordem</span><input name="sort_order" type="number" value="<?= (int) $plan['sort_order'] ?>"></label></div>
                                    <div class="form-grid two">
                                        <?php foreach ($limitLabels as $key => $label): ?>
                                            <label class="field compact-field"><span><?= View::e($label) ?></span><input name="limits[<?= View::e($key) ?>]" type="number" min="0" placeholder="Ilimitado" value="<?= View::e((string) ($plan['limits'][$key] ?? '')) ?>"></label>
                                        <?php endforeach; ?>
                                    </div>
                                    <label class="field compact-field"><span>Recursos inclusos, um por linha</span><textarea name="features" rows="5"><?= View::e(implode("\n", $plan['features'] ?? [])) ?></textarea></label>
                                    <button class="btn btn-primary btn-block" type="submit">Salvar plano</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$plans): ?><tr><td colspan="6"><div class="empty-state">Nenhum plano cadastrado.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="stack">
        <form class="card sticky-card" method="post" action="<?= View::e(Router::url('/billing/subscriptions/save')) ?>">
            <?= Csrf::input() ?>
            <div class="section-heading"><div><span class="eyebrow">Assinatura</span><h2>Vincular plano</h2></div></div>
            <label class="field"><span>Empresa</span><select name="tenant_id" required><option value="">Selecione</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>"><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Plano</span><select name="plan_id" required><option value="">Selecione</option><?php foreach ($plans as $plan): ?><option value="<?= (int) $plan['id'] ?>"><?= View::e($plan['name']) ?> — <?= View::e($money($plan['monthly_price'])) ?></option><?php endforeach; ?></select></label>
            <div class="form-grid two"><label class="field"><span>Status</span><select name="billing_status"><option value="trialing">Teste</option><option value="active" selected>Ativa</option><option value="overdue">Em atraso</option><option value="suspended">Suspensa</option><option value="canceled">Cancelada</option></select></label><label class="field"><span>Ciclo</span><select name="billing_cycle"><option value="monthly">Mensal</option><option value="quarterly">Trimestral</option><option value="semiannual">Semestral</option><option value="annual">Anual</option></select></label></div>
            <label class="field"><span>Valor negociado</span><input name="amount" placeholder="Ex.: 297,00"></label>
            <div class="form-grid two"><label class="field"><span>Início período</span><input type="date" name="current_period_starts_at" value="<?= date('Y-m-01') ?>"></label><label class="field"><span>Fim período</span><input type="date" name="current_period_ends_at" value="<?= date('Y-m-t') ?>"></label></div>
            <div class="form-grid two"><label class="field"><span>Próxima cobrança</span><input type="date" name="next_billing_at"></label><label class="field"><span>Fim do teste</span><input type="date" name="trial_ends_at"></label></div>
            <label class="field"><span>Observações</span><textarea name="notes" rows="3" placeholder="Condição comercial, desconto, contrato..."></textarea></label>
            <button class="btn btn-primary btn-block" type="submit">Atualizar assinatura</button>
        </form>

        <form class="card" method="post" action="<?= View::e(Router::url('/billing/plans/save')) ?>">
            <?= Csrf::input() ?>
            <div class="section-heading"><div><span class="eyebrow">Novo plano</span><h2>Criar pacote</h2></div></div>
            <div class="form-grid two"><label class="field"><span>Identificador</span><input name="plan_key" placeholder="custom-clinica" required></label><label class="field"><span>Ordem</span><input name="sort_order" type="number" value="50"></label></div>
            <label class="field"><span>Nome</span><input name="name" placeholder="Plano Clínica" required></label>
            <label class="field"><span>Valor mensal</span><input name="monthly_price" placeholder="497,00"></label>
            <label class="field"><span>Descrição</span><input name="description" placeholder="Pacote específico para clínicas"></label>
            <label class="field"><span>Recursos inclusos</span><textarea name="features" rows="4" placeholder="CRM\nAgenda\nIA\nFluxos n8n"></textarea></label>
            <input type="hidden" name="status" value="active">
            <button class="btn btn-outline btn-block" type="submit">Criar plano</button>
        </form>
    </aside>
</div>

<section class="card table-card">
    <div class="section-heading"><div><span class="eyebrow">Clientes</span><h2>Assinaturas por empresa</h2></div><span class="badge"><?= count($tenants) ?> empresa(s)</span></div>
    <div class="table-wrap"><table class="clean-table"><thead><tr><th>Empresa</th><th>Plano</th><th>Status</th><th>Próxima cobrança</th><th>Valor</th><th>Ações</th></tr></thead><tbody>
    <?php foreach ($tenants as $tenant): ?>
        <tr>
            <td><strong><?= View::e($tenant['name']) ?></strong><br><small><?= View::e($tenant['status']) ?></small></td>
            <td><?= View::e($tenant['plan_name'] ?? $tenant['plan'] ?? '—') ?></td>
            <td><span class="badge badge-<?= View::e($tenant['billing_status'] ?? 'inactive') ?>"><?= View::e($statusLabel[$tenant['billing_status'] ?? 'inactive'] ?? ($tenant['billing_status'] ?? 'Sem assinatura')) ?></span></td>
            <td><?= View::e($date($tenant['next_billing_at'] ?? null)) ?><br><small>Período até <?= View::e($date($tenant['current_period_ends_at'] ?? null)) ?></small></td>
            <td><?= View::e($money($tenant['amount'] ?? 0)) ?></td>
            <td class="actions-cell">
                <a class="btn btn-small btn-outline" href="<?= View::e(Router::url('/subscription?tenant_id=' . (int) $tenant['id'])) ?>">Ver uso</a>
                <?php if (!empty($tenant['subscription_id'])): ?>
                    <details><summary class="btn btn-small btn-quiet">Cobrar</summary>
                        <form class="stack compact-form" method="post" action="<?= View::e(Router::url('/billing/invoices/create')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="tenant_id" value="<?= (int) $tenant['id'] ?>"><input type="hidden" name="subscription_id" value="<?= (int) $tenant['subscription_id'] ?>">
                            <label class="field compact-field"><span>Valor</span><input name="amount" value="<?= View::e(number_format((float) ($tenant['amount'] ?? 0), 2, ',', '.')) ?>" required></label>
                            <div class="form-grid two"><label class="field compact-field"><span>Vencimento</span><input type="date" name="due_date" value="<?= date('Y-m-d') ?>"></label><label class="field compact-field"><span>Período início</span><input type="date" name="period_start" value="<?= date('Y-m-01') ?>"></label></div>
                            <label class="field compact-field"><span>Período fim</span><input type="date" name="period_end" value="<?= date('Y-m-t') ?>"></label>
                            <label class="field compact-field"><span>Observação</span><input name="notes" placeholder="Cobrança mensal"></label>
                            <button class="btn btn-primary btn-block" type="submit">Criar cobrança</button>
                        </form>
                    </details>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$tenants): ?><tr><td colspan="6"><div class="empty-state">Nenhuma empresa cadastrada.</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section class="card table-card">
    <div class="section-heading"><div><span class="eyebrow">Financeiro</span><h2>Últimas cobranças</h2></div><span class="badge"><?= count($invoices) ?> cobrança(s)</span></div>
    <div class="table-wrap"><table class="clean-table"><thead><tr><th>Número</th><th>Empresa</th><th>Período</th><th>Valor</th><th>Vencimento</th><th>Status</th><th>Ação</th></tr></thead><tbody>
    <?php foreach ($invoices as $invoice): ?>
        <tr>
            <td><strong><?= View::e($invoice['invoice_number']) ?></strong><br><small><?= View::e($invoice['plan_name'] ?? '—') ?></small></td>
            <td><?= View::e($invoice['tenant_name']) ?></td>
            <td><?= View::e($date($invoice['period_start'])) ?> a <?= View::e($date($invoice['period_end'])) ?></td>
            <td><?= View::e($money($invoice['amount'])) ?></td>
            <td><?= View::e($date($invoice['due_date'])) ?></td>
            <td><span class="badge badge-<?= View::e($invoice['status']) ?>"><?= View::e($statusLabel[$invoice['status']] ?? $invoice['status']) ?></span></td>
            <td><form method="post" action="<?= View::e(Router::url('/billing/invoices/status')) ?>"><?= Csrf::input() ?><input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>"><select name="status"><option value="open" <?= $invoice['status'] === 'open' ? 'selected' : '' ?>>Aberta</option><option value="paid" <?= $invoice['status'] === 'paid' ? 'selected' : '' ?>>Paga</option><option value="overdue" <?= $invoice['status'] === 'overdue' ? 'selected' : '' ?>>Atrasada</option><option value="cancelled" <?= $invoice['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelada</option></select><button class="btn btn-small btn-outline" type="submit">Salvar</button></form></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$invoices): ?><tr><td colspan="7"><div class="empty-state">Nenhuma cobrança criada ainda.</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
