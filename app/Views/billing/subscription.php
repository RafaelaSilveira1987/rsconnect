<?php

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;

if (Auth::isSuperAdmin()) {
    require __DIR__ . '/subscription_admin.php';
    return;
}

$money = static fn (mixed $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$date = static function (?string $value): string {
    if (!$value) return 'Não informado';
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y', $timestamp) : $value;
};
$cycleLabel = ['monthly' => 'por mês', 'yearly' => 'por ano', 'custom' => 'período personalizado'];
$statusLabel = [
    'trialing' => 'Período de teste',
    'active' => 'Plano ativo',
    'overdue' => 'Pagamento em atraso',
    'suspended' => 'Plano suspenso',
    'canceled' => 'Plano cancelado',
    'open' => 'Aguardando pagamento',
    'paid' => 'Pago',
    'cancelled' => 'Cancelado',
];
$nextInvoice = $invoices[0] ?? null;
$nextPaymentLink = $nextInvoice['external_checkout_url'] ?? $nextInvoice['external_invoice_url'] ?? '';
$billingStatus = (string) ($plan['billing_status'] ?? 'active');
$trialEndsAt = (string) ($plan['trial_ends_at'] ?? '');
$trialBehavior = (string) ($plan['trial_end_behavior'] ?? 'await_payment');
$trialGraceDays = max(0, (int) ($plan['trial_grace_days'] ?? 0));
$trialDaysRemaining = null;
if ($billingStatus === 'trialing' && $trialEndsAt !== '') {
    $today = new DateTimeImmutable('today');
    $trialEndDate = new DateTimeImmutable($trialEndsAt);
    $trialDaysRemaining = max(0, ((int) $today->diff($trialEndDate)->format('%r%a')) + 1);
}
$trialBehaviorLabel = [
    'await_payment' => 'Aguardar contratação ou pagamento',
    'activate' => 'Converter para assinatura ativa',
    'suspend' => 'Suspender o acesso',
];
$whatsappNumber = '5532987073537';
$whatsappMessage = rawurlencode('Olá! Gostaria de conhecer as opções para melhorar meu plano no RS Connect. Minha empresa é ' . (string) ($tenant['name'] ?? '') . '.');
$whatsappUrl = 'https://wa.me/' . $whatsappNumber . '?text=' . $whatsappMessage;
?>
<?php $accountSection = 'subscription'; require __DIR__ . '/../companies/_account_tabs.php'; ?>

<section class="client-subscription-hero card">
    <div class="client-subscription-hero-main">
        <div class="client-subscription-plan-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2v20M17 7.5c0-2-2-3-5-3s-5 1-5 3 2 3 5 3 5 1 5 3-2 3-5 3-5-1-5-3"/></svg>
        </div>
        <div>
            <span class="eyebrow">Minha assinatura</span>
            <div class="client-subscription-title-row">
                <h2><?= View::e($plan['name'] ?? 'Plano atual') ?></h2>
                <span class="badge badge-<?= View::e($billingStatus) ?>"><?= View::e($statusLabel[$billingStatus] ?? ucfirst($billingStatus)) ?></span>
            </div>
            <p>Veja o que está incluído, acompanhe o uso e consulte suas cobranças em um só lugar.</p>
        </div>
    </div>

    <div class="client-subscription-price">
        <span>Valor contratado</span>
        <strong><?= View::e($money($plan['monthly_price'] ?? 0)) ?></strong>
        <small><?= View::e($cycleLabel[$plan['billing_cycle'] ?? 'monthly'] ?? 'por período') ?></small>
    </div>
</section>

<?php if ($billingStatus === 'trialing'): ?>
<section class="client-trial-card">
    <div class="client-trial-card-head">
        <div>
            <span class="eyebrow">Teste gratuito</span>
            <h3><?= $trialDaysRemaining === 1 ? 'Último dia do teste' : View::e((string) ($trialDaysRemaining ?? 0)) . ' dias restantes' ?></h3>
            <p>Os recursos do plano estão liberados durante a avaliação. Nenhuma cobrança é criada antes do término do teste.</p>
        </div>
        <span class="badge badge-trialing">Em avaliação</span>
    </div>
    <div class="client-trial-card-grid">
        <div><span>Último dia gratuito</span><strong><?= View::e($date($trialEndsAt ?: null)) ?></strong><small>Acesso integral até o final desta data.</small></div>
        <div><span>Primeira cobrança prevista</span><strong><?= View::e($date($plan['next_billing_at'] ?? null)) ?></strong><small>Somente após o término do teste.</small></div>
        <div><span>Após o teste</span><strong><?= View::e($trialBehaviorLabel[$trialBehavior] ?? 'Aguardar definição') ?></strong><small><?= $trialBehavior === 'await_payment' && $trialGraceDays > 0 ? View::e((string) $trialGraceDays) . ' dia(s) de tolerância.' : 'A regra foi definida pela equipe RS.' ?></small></div>
    </div>
</section>
<?php endif; ?>

<div class="client-subscription-summary-grid">
    <article class="client-subscription-summary-card">
        <span class="client-summary-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 3v4M17 3v4M4 9h16M5 5h14v16H5z"/></svg></span>
        <div><span>Período atual</span><strong><?= View::e($date($plan['current_period_starts_at'] ?? null)) ?></strong><small>até <?= View::e($date($plan['current_period_ends_at'] ?? null)) ?></small></div>
    </article>
    <article class="client-subscription-summary-card">
        <span class="client-summary-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg></span>
        <div><span>Próxima cobrança</span><strong><?= View::e($date($plan['next_billing_at'] ?? null)) ?></strong><small><?= $billingStatus === 'trialing' ? 'Fim do teste: ' . View::e($date($plan['trial_ends_at'] ?? null)) : 'Você será avisado antes do vencimento' ?></small></div>
    </article>
    <article class="client-subscription-summary-card">
        <span class="client-summary-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 6 9 17l-5-5"/></svg></span>
        <div><span>Situação do plano</span><strong><?= View::e($statusLabel[$billingStatus] ?? ucfirst($billingStatus)) ?></strong><small><?= $billingStatus === 'active' ? 'Todos os recursos contratados estão disponíveis' : 'Consulte os detalhes abaixo' ?></small></div>
    </article>
</div>

<div class="client-subscription-layout">
    <section class="card client-subscription-content-card">
        <div class="section-heading client-section-heading">
            <div><span class="eyebrow">Seu plano</span><h2>Recursos incluídos</h2><p>Estes são os principais benefícios disponíveis para sua empresa.</p></div>
        </div>
        <?php if (!empty($plan['features'])): ?>
            <div class="client-plan-feature-grid">
                <?php foreach ($plan['features'] as $feature): ?>
                    <div class="client-plan-feature"><span aria-hidden="true">✓</span><strong><?= View::e($feature) ?></strong></div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">Os recursos do plano ainda não foram detalhados.</div>
        <?php endif; ?>
    </section>

    <aside class="card client-upgrade-card">
        <span class="client-upgrade-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v18M3 12h18"/><circle cx="12" cy="12" r="9"/></svg>
        </span>
        <span class="eyebrow">Quer mais recursos?</span>
        <h2>Encontre o plano ideal para sua operação.</h2>
        <p>Fale com a equipe RS Connect para aumentar limites, liberar recursos ou revisar sua assinatura.</p>
        <a class="btn client-whatsapp-button" href="<?= View::e($whatsappUrl) ?>" target="_blank" rel="noopener noreferrer">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2a9.84 9.84 0 0 0-8.42 14.93L2 22l5.2-1.58A9.94 9.94 0 1 0 12.04 2Zm0 17.86a8 8 0 0 1-4.08-1.12l-.3-.18-3.08.94.97-3-.2-.31A7.83 7.83 0 1 1 12.04 19.86Zm4.4-5.87c-.24-.12-1.43-.7-1.65-.78-.22-.08-.38-.12-.54.12-.16.24-.62.78-.76.94-.14.16-.28.18-.52.06-.24-.12-1.01-.37-1.93-1.19-.71-.63-1.2-1.42-1.34-1.66-.14-.24-.01-.37.11-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.54-1.3-.74-1.78-.2-.47-.4-.4-.54-.41h-.46c-.16 0-.42.06-.64.3-.22.24-.84.82-.84 2s.86 2.32.98 2.48c.12.16 1.69 2.58 4.1 3.62.57.25 1.02.4 1.37.51.58.18 1.1.16 1.51.1.46-.07 1.43-.58 1.63-1.15.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28Z"/></svg>
            Solicitar melhoria do plano
        </a>
        <small>Atendimento pelo WhatsApp: (32) 98707-3537</small>
    </aside>
</div>

<section class="card client-subscription-usage">
    <div class="section-heading client-section-heading">
        <div><span class="eyebrow">Uso do plano</span><h2>Uso da plataforma e da IA</h2><p>Mensagens mostram o movimento real do atendimento. A franquia comercial é separada: somente respostas automáticas entregues com IA custeada pela RS Connect reduzem o limite do plano.</p></div>
    </div>
    <div class="ai-usage-origin-summary">
        <div><span>Mensagens movimentadas</span><strong><?= number_format((int) ($aiUsage['messages']['total'] ?? 0), 0, ',', '.') ?></strong><small>mensagens recebidas e enviadas no WhatsApp; esta métrica não consome franquia de IA</small></div>
        <div><span>Interações automáticas de IA</span><strong><?= number_format((int) ($aiUsage['total'] ?? 0), 0, ',', '.') ?></strong><small>respostas automáticas de IA efetivamente entregues aos clientes, usando qualquer origem de credencial</small></div>
        <div><span>Franquia de IA RS</span><strong><?= number_format((int) ($aiUsage['rs_connect'] ?? 0), 0, ',', '.') ?><?= ($aiUsage['billable_limit'] ?? null) !== null ? ' / ' . number_format((int) $aiUsage['billable_limit'], 0, ',', '.') : '' ?></strong><small>somente interações cuja IA é custeada pela RS Connect reduzem este limite</small></div>
        <div><span>IA com credencial própria</span><strong><?= number_format((int) ($aiUsage['tenant'] ?? 0), 0, ',', '.') ?></strong><small>é contabilizada para acompanhamento de uso, mas não reduz a franquia fornecida pela RS</small></div>
    </div>
    <div class="ai-usage-explainer">
        <span><strong>Recebidas:</strong> <?= number_format((int) ($aiUsage['messages']['incoming'] ?? 0), 0, ',', '.') ?></span>
        <span><strong>Enviadas pela equipe:</strong> <?= number_format((int) ($aiUsage['messages']['human_outgoing'] ?? 0), 0, ',', '.') ?></span>
        <span><strong>Saídas automáticas:</strong> <?= number_format((int) ($aiUsage['messages']['automatic_outgoing'] ?? 0), 0, ',', '.') ?></span>
        <span><strong>Período:</strong> <?= View::e($date($aiUsage['period']['start_date'] ?? null)) ?> a <?= View::e($date($aiUsage['period']['end_date'] ?? null)) ?></span>
    </div>
    <div class="client-usage-grid">
        <?php foreach ($limitRows as $row): ?>
            <?php
                $percent = max(0, min(100, (int) ($row['percent'] ?? 0)));
                $blocked = !empty($row['blocked']);
                $attention = !$blocked && $percent >= 80;
                $statusText = $blocked ? 'Limite atingido' : ($attention ? 'Próximo do limite' : 'Disponível');
                $statusClass = $blocked ? 'is-blocked' : ($attention ? 'is-attention' : 'is-ok');
            ?>
            <?php $remaining = $row['limit'] === null ? null : max(0, (int) $row['limit'] - (int) $row['used']); ?>
            <article class="client-usage-card <?= $statusClass ?>" data-usage-key="<?= View::e((string) ($row['key'] ?? '')) ?>">
                <div class="client-usage-card-head">
                    <div><strong><?= View::e($row['label']) ?></strong><small><?= View::e((string) ($row['description'] ?? '')) ?></small></div>
                    <span><?= View::e($statusText) ?></span>
                </div>
                <div class="client-usage-value-line">
                    <strong><?= number_format((int) $row['used'], 0, ',', '.') ?></strong>
                    <span><?= $row['limit'] === null ? 'uso sem limite definido' : 'de ' . number_format((int) $row['limit'], 0, ',', '.') ?></span>
                </div>
                <div class="client-usage-progress" aria-label="<?= $percent ?>% utilizado"><span style="width: <?= $percent ?>%"></span></div>
                <div class="client-usage-card-foot">
                    <small><?= $row['limit'] === null ? 'Acompanhamento de volume' : $percent . '% utilizado' ?></small>
                    <small><?= $remaining === null ? 'Sem bloqueio por quantidade' : number_format($remaining, 0, ',', '.') . ' restante(s)' ?></small>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$limitRows): ?><div class="empty-state">Nenhum limite de uso foi configurado para este plano.</div><?php endif; ?>
    </div>

    <details class="client-ai-telemetry-details">
        <summary>
            <span><strong>Como a IA está sendo contabilizada</strong><small>Interação comercial, chamadas ao provedor e tokens são métricas diferentes.</small></span>
            <span class="drawer-chevron" aria-hidden="true"></span>
        </summary>
        <div class="client-ai-telemetry-grid">
            <div><span>Interações entregues</span><strong><?= number_format((int) ($aiUsage['total'] ?? 0), 0, ',', '.') ?></strong><small>respostas automáticas efetivamente entregues</small></div>
            <div><span>Chamadas ao provedor</span><strong><?= number_format((int) ($aiUsage['technical']['provider_calls'] ?? 0), 0, ',', '.') ?></strong><small>inclui chamadas técnicas, falhas e outros usos de IA</small></div>
            <div><span>Chamadas evitadas</span><strong><?= number_format((int) ($aiUsage['technical']['provider_calls_avoided'] ?? 0), 0, ',', '.') ?></strong><small>respostas locais ou reutilizadas sem acessar o provedor</small></div>
            <div><span>Tokens de entrada</span><strong><?= number_format((int) ($aiUsage['technical']['input_tokens'] ?? 0), 0, ',', '.') ?></strong><small>contexto e instruções enviados ao modelo</small></div>
            <div><span>Tokens de saída</span><strong><?= number_format((int) ($aiUsage['technical']['output_tokens'] ?? 0), 0, ',', '.') ?></strong><small>conteúdo produzido pelo modelo</small></div>
            <div><span>Total de tokens</span><strong><?= number_format((int) ($aiUsage['technical']['total_tokens'] ?? 0), 0, ',', '.') ?></strong><small>telemetria registrada pela RS Connect no período</small></div>
            <div><span>Entrada evitada</span><strong><?= number_format((int) ($aiUsage['technical']['estimated_input_tokens_avoided'] ?? 0), 0, ',', '.') ?></strong><small>estimativa de tokens que deixaram de ser reenviados pelo contexto enxuto</small></div>
            <div><span>Falhas técnicas</span><strong><?= number_format((int) ($aiUsage['technical']['failed_events'] ?? 0), 0, ',', '.') ?></strong><small>não reduzem franquia quando não há entrega</small></div>
        </div>
        <p class="client-ai-telemetry-note">A franquia considera somente respostas automáticas entregues com IA custeada pela RS Connect. “Entrada evitada” é uma estimativa baseada no histórico e na base que o roteador deixou de reenviar. O número de requisições exibido pelo provedor pode ser maior porque inclui chamadas técnicas, testes, falhas e outros usos.</p>
    </details>
</section>

<section class="card client-invoice-section">
    <div class="section-heading client-section-heading">
        <div><span class="eyebrow">Cobranças</span><h2>Histórico financeiro</h2><p>Consulte vencimentos, pagamentos e links disponíveis.</p></div>
        <span class="badge"><?= count($invoices) ?> registro(s)</span>
    </div>

    <?php if ($nextInvoice && $nextPaymentLink && ($nextInvoice['status'] ?? '') !== 'paid'): ?>
        <div class="client-next-invoice">
            <div><span>Próxima cobrança</span><strong><?= View::e($money($nextInvoice['amount'] ?? 0)) ?></strong><small>Vencimento em <?= View::e($date($nextInvoice['due_date'] ?? null)) ?></small></div>
            <a class="btn btn-primary" href="<?= View::e($nextPaymentLink) ?>" target="_blank" rel="noopener">Pagar agora</a>
        </div>
    <?php endif; ?>

    <div class="client-invoice-list">
        <?php foreach ($invoices as $invoice): ?>
            <?php $paymentLink = $invoice['external_checkout_url'] ?? $invoice['external_invoice_url'] ?? ''; ?>
            <article class="client-invoice-card">
                <div class="client-invoice-main">
                    <span class="client-invoice-number"><?= View::e($invoice['invoice_number'] ?? 'Cobrança') ?></span>
                    <strong><?= View::e($money($invoice['amount'] ?? 0)) ?></strong>
                    <small><?= View::e($date($invoice['period_start'] ?? null)) ?> a <?= View::e($date($invoice['period_end'] ?? null)) ?></small>
                </div>
                <div class="client-invoice-meta"><span>Vencimento</span><strong><?= View::e($date($invoice['due_date'] ?? null)) ?></strong></div>
                <div class="client-invoice-meta"><span>Status</span><strong><span class="badge badge-<?= View::e($invoice['status'] ?? 'open') ?>"><?= View::e($statusLabel[$invoice['status'] ?? 'open'] ?? ucfirst((string) ($invoice['status'] ?? 'open'))) ?></span></strong></div>
                <div class="client-invoice-actions">
                    <?php if ($paymentLink && ($invoice['status'] ?? '') !== 'paid'): ?><a class="btn btn-small btn-primary" href="<?= View::e($paymentLink) ?>" target="_blank" rel="noopener">Pagar</a><?php elseif ($paymentLink): ?><a class="btn btn-small btn-outline" href="<?= View::e($paymentLink) ?>" target="_blank" rel="noopener">Ver comprovante</a><?php else: ?><small>Link não disponível</small><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$invoices): ?><div class="empty-state">Nenhuma cobrança encontrada para sua empresa.</div><?php endif; ?>
    </div>
</section>
