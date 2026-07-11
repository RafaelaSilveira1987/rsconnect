<?php

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;

$money = static fn (float|int $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$number = static fn (int|float $value): string => number_format((float) $value, 0, ',', '.');
$maxDay = max(1, ...array_map(static fn ($row) => (int) ($row['total'] ?? 0), $byDay ?: [['total' => 1]]));
$maxTenant = max(1, ...array_map(static fn ($row) => (int) ($row['total'] ?? 0), $byTenant ?: [['total' => 1]]));
$queryBase = array_filter([
    'start' => $filters['start'] ?? '',
    'end' => $filters['end'] ?? '',
    'tenant_id' => $filters['tenant_id'] ?? 0,
], static fn ($value) => $value !== '' && $value !== 0);
?>

<section class="hero-card hero-admin report-hero">
    <div>
        <span class="eyebrow light">Inteligência operacional</span>
        <h2>Relatórios e métricas do RS Connect.</h2>
        <p>Acompanhe atendimento, IA, CRM, agenda e cobrança por período. Use os dados para vender melhor, priorizar clientes e identificar gargalos.</p>
    </div>
    <div class="hero-actions">
        <a class="btn btn-light" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'conversations']))) ?>">Exportar conversas</a>
        <a class="btn btn-outline-light" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'leads']))) ?>">Exportar leads</a>
    </div>
</section>

<form class="card report-toolbar" method="get" action="<?= View::e(Router::url('/reports')) ?>">
    <label class="field"><span>Data inicial</span><input type="date" name="start" value="<?= View::e($filters['start']) ?>"></label>
    <label class="field"><span>Data final</span><input type="date" name="end" value="<?= View::e($filters['end']) ?>"></label>
    <?php if (Auth::isSuperAdmin()): ?>
        <label class="field"><span>Empresa</span>
            <select name="tenant_id">
                <option value="">Todas as empresas</option>
                <?php foreach ($tenants as $tenant): ?>
                    <option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
    <button class="btn btn-primary" type="submit">Aplicar filtros</button>
    <a class="btn btn-outline" href="<?= View::e(Router::url('/reports')) ?>">Limpar</a>
</form>

<div class="report-kpi-grid">
    <article class="card report-kpi"><span>Conversas no período</span><strong><?= $number((int) $metrics['conversations']) ?></strong><small><?= $number((int) $metrics['open_conversations']) ?> aberta(s) agora</small></article>
    <article class="card report-kpi"><span>Mensagens recebidas</span><strong><?= $number((int) $metrics['incoming_messages']) ?></strong><small><?= $number((int) $metrics['unread']) ?> mensagem(ns) não lida(s)</small></article>
    <article class="card report-kpi"><span>Respostas enviadas</span><strong><?= $number((int) $metrics['outgoing_messages']) ?></strong><small><?= $number((int) $metrics['ai_replies']) ?> resposta(s) da IA</small></article>
    <article class="card report-kpi"><span>Novos contatos</span><strong><?= $number((int) $metrics['contacts']) ?></strong><small>Leads capturados no período</small></article>
    <article class="card report-kpi"><span>Oportunidades</span><strong><?= $number((int) $metrics['crm_leads']) ?></strong><small><?= $number((int) $metrics['crm_won']) ?> ganho(s)</small></article>
    <article class="card report-kpi"><span>Agendamentos</span><strong><?= $number((int) $metrics['appointments']) ?></strong><small>Compromissos no período</small></article>
    <article class="card report-kpi"><span>Recebido</span><strong><?= $money((float) $metrics['received_amount']) ?></strong><small>Pagamentos confirmados</small></article>
    <article class="card report-kpi"><span>A receber</span><strong><?= $money((float) $metrics['expected_amount']) ?></strong><small><?= $number((int) $metrics['overdue_invoices']) ?> cobrança(s) em atraso</small></article>
</div>

<div class="report-grid">
    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Volume diário</span><h2>Mensagens por dia</h2></div>
            <span class="badge"><?= View::e(date('d/m/Y', strtotime($filters['start']))) ?> a <?= View::e(date('d/m/Y', strtotime($filters['end']))) ?></span>
        </div>
        <div class="report-bars">
            <?php foreach ($byDay as $row): ?>
                <?php $pct = min(100, ((int) $row['total'] / $maxDay) * 100); ?>
                <div class="report-bar-row">
                    <strong><?= View::e(date('d/m', strtotime($row['label']))) ?></strong>
                    <span class="report-bar-track"><i style="width: <?= (float) $pct ?>%"></i></span>
                    <span><?= (int) $row['total'] ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (!$byDay): ?><div class="empty-state">Nenhuma mensagem no período.</div><?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Atenção</span><h2>Conversas prioritárias</h2></div></div>
        <div class="report-alert-list">
            <?php foreach ($attention as $item): ?>
                <a class="report-alert-item" href="<?= View::e(Router::url('/conversations?conversation_id=' . (int) $item['id'])) ?>">
                    <strong><span><?= View::e($item['contact_name'] ?: $item['phone']) ?></span><b><?= (int) $item['unread_count'] ?></b></strong>
                    <small><?= View::e($item['tenant_name'] ?? '') ?> · <?= View::e($item['attendance_mode']) ?> · <?= View::e($item['last_message_at'] ?? '') ?></small>
                </a>
            <?php endforeach; ?>
            <?php if (!$attention): ?><div class="empty-state">Nenhuma conversa pendente no momento.</div><?php endif; ?>
        </div>
    </section>
</div>

<div class="report-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Empresas</span><h2>Conversas por empresa</h2></div></div>
        <div class="report-bars">
            <?php foreach ($byTenant as $row): ?>
                <?php $pct = min(100, ((int) $row['total'] / $maxTenant) * 100); ?>
                <div class="report-bar-row">
                    <strong><?= View::e($row['label']) ?></strong>
                    <span class="report-bar-track"><i style="width: <?= (float) $pct ?>%"></i></span>
                    <span><?= (int) $row['total'] ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (!$byTenant): ?><div class="empty-state">Nenhuma empresa com conversa no período.</div><?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Financeiro</span><h2>Últimas cobranças</h2></div>
            <a class="btn btn-small btn-outline" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'billing']))) ?>">Exportar</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Cobrança</th><th>Empresa</th><th>Valor</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($recentInvoices as $invoice): ?>
                    <tr><td><strong><?= View::e($invoice['invoice_number']) ?></strong><small><?= View::e($invoice['due_date']) ?></small></td><td><?= View::e($invoice['tenant_name']) ?></td><td><?= $money((float) $invoice['amount']) ?></td><td><span class="badge badge-<?= View::e($invoice['status']) ?>"><?= View::e($invoice['status']) ?></span></td></tr>
                <?php endforeach; ?>
                <?php if (!$recentInvoices): ?><tr><td colspan="4"><div class="empty-state">Nenhuma cobrança encontrada.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
