<?php

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;

$money = static fn (mixed $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$date = static function (?string $value): string {
    if (!$value) return '—';
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y', $timestamp) : $value;
};
$cycleLabel = ['monthly' => 'Mensal', 'yearly' => 'Anual', 'custom' => 'Personalizado'];
$statusLabel = ['trialing' => 'Em teste', 'active' => 'Ativa', 'overdue' => 'Em atraso', 'suspended' => 'Suspensa', 'canceled' => 'Cancelada', 'open' => 'Aberta', 'paid' => 'Paga', 'cancelled' => 'Cancelada'];
$count = static fn (mixed $value): string => number_format((int) $value, 0, ',', '.');
$costLabel = static function (string $currency, float $value): string {
    $currency = strtoupper(trim($currency));
    if ($currency === 'BRL') {
        return 'R$ ' . number_format($value, 6, ',', '.');
    }
    return ($currency !== '' ? $currency . ' ' : '') . number_format($value, 6, ',', '.');
};
$nextInvoice = $invoices[0] ?? null;
$nextPaymentLink = $nextInvoice['external_checkout_url'] ?? $nextInvoice['external_invoice_url'] ?? '';
?>

<section class="subscription-hero card">
    <div class="subscription-main">
        <div>
            <span class="eyebrow">Minha assinatura</span>
            <h2><?= View::e($tenant['name']) ?></h2>
            <p>Resumo do plano contratado, limites de uso e próximas cobranças.</p>
        </div>
        <span class="badge badge-<?= View::e($plan['billing_status']) ?>"><?= View::e($statusLabel[$plan['billing_status']] ?? $plan['billing_status']) ?></span>
    </div>

    <div class="subscription-summary-grid">
        <article class="subscription-summary-card"><span>Plano atual</span><strong><?= View::e($plan['name']) ?></strong><small><?= View::e(ucfirst((string) $plan['key'])) ?></small></article>
        <article class="subscription-summary-card"><span>Valor do plano</span><strong><?= View::e($money($plan['monthly_price'])) ?></strong><small><?= View::e($cycleLabel[$plan['billing_cycle']] ?? $plan['billing_cycle']) ?></small></article>
        <article class="subscription-summary-card"><span>Período atual</span><strong><?= View::e($date($plan['current_period_starts_at'])) ?></strong><small>até <?= View::e($date($plan['current_period_ends_at'])) ?></small></article>
        <article class="subscription-summary-card"><span>Próxima cobrança</span><strong><?= View::e($date($plan['next_billing_at'])) ?></strong><small>Fim do teste: <?= View::e($date($plan['trial_ends_at'])) ?></small></article>
    </div>

    <?php if (!empty($plan['features'])): ?>
        <div class="pill-list subscription-features"><?php foreach ($plan['features'] as $feature): ?><span class="tag-pill"><?= View::e($feature) ?></span><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="subscription-actions">
        <?php if ($nextInvoice && $nextPaymentLink && ($nextInvoice['status'] ?? '') !== 'paid'): ?>
            <a class="btn btn-primary" href="<?= View::e($nextPaymentLink) ?>" target="_blank" rel="noopener">Pagar próxima cobrança</a>
        <?php endif; ?>
        <?php if (Auth::isSuperAdmin()): ?><a class="btn btn-outline" href="<?= View::e(Router::url('/billing')) ?>">Voltar para cobrança</a><?php endif; ?>
    </div>
</section>

<section class="card table-card subscription-usage-card">
    <div class="section-heading"><div><span class="eyebrow">Uso do ciclo</span><h2>Uso do sistema e limite da IA</h2><p>Mensagens, respostas entregues e chamadas ao serviço de IA são números diferentes. O limite do plano considera apenas respostas pagas pela RS Connect.</p></div></div>
    <div class="ai-usage-origin-summary">
        <div><span>Mensagens movimentadas</span><strong><?= $count($aiUsage['messages']['total'] ?? 0) ?></strong><small>recebidas + enviadas; mede volume operacional e não reduz o limite de IA</small></div>
        <div><span>Interações automáticas</span><strong><?= $count($aiUsage['total'] ?? 0) ?></strong><small>respostas automáticas de IA efetivamente entregues aos clientes</small></div>
        <div><span>Limite de IA pago pela RS</span><strong><?= $count($aiUsage['rs_connect'] ?? 0) ?><?= ($aiUsage['billable_limit'] ?? null) !== null ? ' / ' . $count($aiUsage['billable_limit']) : '' ?></strong><small>somente interações custeadas pela RS Connect consomem o limite comercial</small></div>
        <div><span>IA paga pelo cliente</span><strong><?= $count($aiUsage['tenant'] ?? 0) ?></strong><small>uso medido normalmente, sem reduzir a limite de IA pago pela RS</small></div>
    </div>
    <div class="ai-usage-explainer">
        <span><strong>Recebidas:</strong> <?= $count($aiUsage['messages']['incoming'] ?? 0) ?></span>
        <span><strong>Equipe:</strong> <?= $count($aiUsage['messages']['human_outgoing'] ?? 0) ?></span>
        <span><strong>Saídas automáticas:</strong> <?= $count($aiUsage['messages']['automatic_outgoing'] ?? 0) ?></span>
        <span><strong>Regra comercial:</strong> 1 resposta automática entregue = 1 interação</span>
    </div>
    <div class="usage-grid">
    <?php foreach ($limitRows as $row): ?>
        <article class="usage-tile <?= $row['blocked'] ? 'is-blocked' : '' ?>">
            <div><strong><?= View::e($row['label']) ?></strong><small><?= View::e((string) ($row['description'] ?? '')) ?></small></div>
            <div class="usage-values"><span><?= (int) $row['used'] ?></span><small>de <?= $row['limit'] === null ? 'ilimitado' : (int) $row['limit'] ?></small></div>
            <div><div class="usage-bar"><span style="width: <?= (int) $row['percent'] ?>%"></span></div><small><?= (int) $row['percent'] ?>% utilizado</small></div>
            <span class="badge <?= $row['blocked'] ? 'badge-overdue' : 'badge-active' ?>"><?= $row['blocked'] ? 'Limite atingido' : 'OK' ?></span>
        </article>
    <?php endforeach; ?>
    </div>
</section>

<section class="card table-card ai-technical-usage-card">
    <div class="section-heading">
        <div><span class="eyebrow">Operação RS</span><h2>Dados detalhados de uso da IA</h2><p>Visão administrativa das chamadas, do uso, dos problemas e do custo estimado. Esses números são diferentes do limite comercial do plano.</p></div>
        <span class="badge"><?= $count($aiUsage['technical']['provider_calls'] ?? 0) ?> chamada(s)</span>
    </div>
    <div class="ai-technical-summary">
        <div><span>Chamadas ao serviço de IA</span><strong><?= $count($aiUsage['technical']['provider_calls'] ?? 0) ?></strong><small>inclui respostas, sugestões e tentativas enviadas ao serviço de IA</small></div>
        <div><span>Chamadas economizadas</span><strong><?= $count($aiUsage['technical']['provider_calls_avoided'] ?? 0) ?></strong><small>respostas prontas e reaproveitadas sem nova cobrança de IA</small></div>
        <div><span>Informações processadas</span><strong><?= $count($aiUsage['technical']['input_tokens'] ?? 0) ?></strong><small>instruções e conteúdo enviados para a IA</small></div>
        <div><span>Respostas geradas</span><strong><?= $count($aiUsage['technical']['output_tokens'] ?? 0) ?></strong><small>conteúdo produzido pela IA</small></div>
        <div><span>Informações reaproveitadas</span><strong><?= $count($aiUsage['technical']['cached_tokens'] ?? 0) ?></strong><small>informadas pelo serviço de IA quando disponíveis</small></div>
        <div><span>Uso total da IA</span><strong><?= $count($aiUsage['technical']['total_tokens'] ?? 0) ?></strong><small>soma do uso registrado no período</small></div>
        <div><span>Uso economizado</span><strong><?= $count($aiUsage['technical']['estimated_input_tokens_avoided'] ?? 0) ?></strong><small>estimativa de informações que não precisaram ser enviadas novamente</small></div>
        <div><span>Falhas técnicas</span><strong><?= $count($aiUsage['technical']['failed_events'] ?? 0) ?></strong><small>não consomem interação comercial se a resposta não foi entregue</small></div>
    </div>

    <div class="ai-cost-summary">
        <strong>Custo estimado do serviço de IA</strong>
        <?php if (!empty($aiUsage['costs']['rs_connect']) || !empty($aiUsage['costs']['tenant'])): ?>
            <div class="ai-cost-groups">
                <div><span>Pago pela RS Connect</span><div class="pill-list"><?php if (!empty($aiUsage['costs']['rs_connect'])): ?><?php foreach ($aiUsage['costs']['rs_connect'] as $currency => $value): ?><span class="tag-pill"><?= View::e($costLabel((string) $currency, (float) $value)) ?></span><?php endforeach; ?><?php else: ?><small>Sem custo estimado registrado.</small><?php endif; ?></div></div>
                <div><span>IA paga pelo cliente</span><div class="pill-list"><?php if (!empty($aiUsage['costs']['tenant'])): ?><?php foreach ($aiUsage['costs']['tenant'] as $currency => $value): ?><span class="tag-pill"><?= View::e($costLabel((string) $currency, (float) $value)) ?></span><?php endforeach; ?><?php else: ?><small>Sem custo estimado registrado.</small><?php endif; ?></div></div>
            </div>
            <small>O primeiro grupo mostra o custo que pode ser pago pela RS. O segundo serve apenas para acompanhamento, pois a conta pertence ao cliente. Valores dependem das tarifas configuradas em <code>AI_COST_RATES_JSON</code>.</small>
        <?php else: ?>
            <small>Sem estimativa de custo configurada. O uso e as chamadas continuam sendo registrados.</small>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="clean-table">
            <thead><tr><th>Assistente</th><th>Origem</th><th>Serviço / modelo</th><th>Interações</th><th>Chamadas</th><th>Evitadas</th><th>Uso da IA</th><th>Uso economizado</th><th>Falhas</th><th>Custo estimado</th></tr></thead>
            <tbody>
            <?php foreach (($aiUsage['agents'] ?? []) as $agentUsage): ?>
                <tr>
                    <td><strong><?= View::e((string) ($agentUsage['agent_name'] ?? 'Assistente')) ?></strong></td>
                    <td><?= ($agentUsage['credential_owner'] ?? '') === 'rs_connect' ? 'RS Connect' : 'Cliente' ?></td>
                    <td><strong><?= View::e(strtoupper((string) ($agentUsage['provider'] ?? ''))) ?></strong><br><small><?= View::e((string) ($agentUsage['model'] ?? '—')) ?></small></td>
                    <td><?= $count($agentUsage['interactions'] ?? 0) ?></td>
                    <td><?= $count($agentUsage['provider_calls'] ?? 0) ?></td>
                    <td><?= $count($agentUsage['provider_calls_avoided'] ?? 0) ?></td>
                    <td><?= $count($agentUsage['total_tokens'] ?? 0) ?><br><small>entrada <?= $count($agentUsage['input_tokens'] ?? 0) ?> · saída <?= $count($agentUsage['output_tokens'] ?? 0) ?></small></td>
                    <td><?= $count($agentUsage['estimated_input_tokens_avoided'] ?? 0) ?><br><small>estimativa das informações reduzidas</small></td>
                    <td><?= $count($agentUsage['failed_events'] ?? 0) ?></td>
                    <td><?php if (!empty($agentUsage['costs'])): ?><?php foreach ($agentUsage['costs'] as $currency => $value): ?><span class="usage-cost-line"><?= View::e($costLabel((string) $currency, (float) $value)) ?></span><?php endforeach; ?><?php else: ?>—<?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($aiUsage['agents'])): ?><tr><td colspan="10"><div class="empty-state">Ainda não há dados detalhados de uso neste período.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card table-card">
    <div class="section-heading"><div><span class="eyebrow">Financeiro</span><h2>Cobranças</h2></div><span class="badge"><?= count($invoices) ?> registro(s)</span></div>
    <div class="table-wrap"><table class="clean-table"><thead><tr><th>Número</th><th>Período</th><th>Valor</th><th>Vencimento</th><th>Status</th><th>Pagamento</th><th>Pago em</th></tr></thead><tbody>
    <?php foreach ($invoices as $invoice): ?>
        <?php $paymentLink = $invoice['external_checkout_url'] ?? $invoice['external_invoice_url'] ?? ''; ?>
        <tr>
            <td><strong><?= View::e($invoice['invoice_number']) ?></strong></td>
            <td><?= View::e($date($invoice['period_start'])) ?> a <?= View::e($date($invoice['period_end'])) ?></td>
            <td><?= View::e($money($invoice['amount'])) ?></td>
            <td><?= View::e($date($invoice['due_date'])) ?></td>
            <td><span class="badge badge-<?= View::e($invoice['status']) ?>"><?= View::e($statusLabel[$invoice['status']] ?? $invoice['status']) ?></span></td>
            <td><?php if ($paymentLink && $invoice['status'] !== 'paid'): ?><a class="btn btn-small btn-primary" href="<?= View::e($paymentLink) ?>" target="_blank" rel="noopener">Pagar agora</a><?php elseif ($paymentLink): ?><a class="btn btn-small btn-outline" href="<?= View::e($paymentLink) ?>" target="_blank" rel="noopener">Ver link</a><?php else: ?><small>Aguardando link</small><?php endif; ?></td>
            <td><?= View::e($date($invoice['paid_at'] ?? null)) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$invoices): ?><tr><td colspan="7"><div class="empty-state">Nenhuma cobrança encontrada.</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
