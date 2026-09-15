<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;

$summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
$team = is_array($data['team'] ?? null) ? $data['team'] : [];
$conversations = is_array($data['conversations'] ?? null) ? $data['conversations'] : [];
$instances = is_array($data['instances'] ?? null) ? $data['instances'] : [];
$users = is_array($data['users'] ?? null) ? $data['users'] : [];
$queryBase = static function (array $override = []) use ($filters): string {
    $query = array_merge($filters, $override);
    $query = array_filter($query, static fn ($value): bool => $value !== '' && $value !== 0 && $value !== '0' && $value !== null);
    return http_build_query($query);
};
?>

<section class="operational-load-hero">
    <div>
        <span class="eyebrow">SUPERVISÃO</span>
        <h1>Carga operacional</h1>
        <p>Visão de atendimento atual sem depender de filas: responsáveis, conversas ativas e risco de SLA em um só lugar.</p>
    </div>
    <div class="operational-load-live" data-operational-load-live data-fingerprint="<?= View::e((string) ($data['fingerprint'] ?? '')) ?>">
        <span class="status-dot"></span>
        <strong>Atualização automática</strong>
        <small>a cada 30 segundos</small>
    </div>
</section>

<?php if (empty($data['tenant_live']) && (int) ($filters['tenant_id'] ?? 0) > 0): ?>
    <div class="notice warning">A empresa não está em <strong>LIVE</strong>. A carga atual continua visível, mas os estados oficiais de SLA ficam desativados até o Go-Live.</div>
<?php endif; ?>

<form class="card operational-load-filters" method="get" action="<?= View::e(Router::url('/carga-operacional')) ?>">
    <?php if (Auth::isSuperAdmin()): ?>
        <label class="field"><span>Empresa</span><select name="tenant_id" onchange="this.form.submit()"><option value="">Selecione</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e((string) $tenant['name']) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <label class="field"><span>Conexão</span><select name="instance_id"><option value="">Todas</option><?php foreach ($instances as $instance): ?><option value="<?= (int) $instance['id'] ?>" <?= (int) ($filters['instance_id'] ?? 0) === (int) $instance['id'] ? 'selected' : '' ?>><?= View::e((string) ($instance['name'] ?: $instance['instance_name'])) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Responsável</span><select name="assigned_user_id"><option value="">Todos</option><option value="unassigned" <?= ($filters['assigned_user_id'] ?? '') === 'unassigned' ? 'selected' : '' ?>>Sem responsável</option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>" <?= (string) ($filters['assigned_user_id'] ?? '') === (string) $user['id'] ? 'selected' : '' ?>><?= View::e((string) $user['name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Status</span><select name="status"><option value="">Abertas + pendentes</option><option value="open" <?= ($filters['status'] ?? '') === 'open' ? 'selected' : '' ?>>Abertas</option><option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pendentes</option></select></label>
    <label class="field"><span>Modo</span><select name="mode"><option value="">Todos</option><option value="human" <?= ($filters['mode'] ?? '') === 'human' ? 'selected' : '' ?>>Humano</option><option value="ai" <?= ($filters['mode'] ?? '') === 'ai' ? 'selected' : '' ?>>IA</option><option value="paused" <?= ($filters['mode'] ?? '') === 'paused' ? 'selected' : '' ?>>IA pausada</option></select></label>
    <label class="field"><span>SLA atual</span><select name="sla"><option value="">Todos</option><option value="pending" <?= ($filters['sla'] ?? '') === 'pending' ? 'selected' : '' ?>>Aguardando 1ª resposta</option><option value="normal" <?= ($filters['sla'] ?? '') === 'normal' ? 'selected' : '' ?>>Dentro do prazo</option><option value="warning" <?= ($filters['sla'] ?? '') === 'warning' ? 'selected' : '' ?>>Em risco</option><option value="breached" <?= ($filters['sla'] ?? '') === 'breached' ? 'selected' : '' ?>>Violado</option></select></label>
    <div class="operational-load-filter-actions"><button class="btn btn-primary" type="submit">Aplicar filtros</button><a class="btn btn-secondary" href="<?= View::e(Router::url('/carga-operacional' . (Auth::isSuperAdmin() && !empty($filters['tenant_id']) ? '?tenant_id=' . (int) $filters['tenant_id'] : ''))) ?>">Limpar</a></div>
</form>

<div class="operational-load-kpis">
    <?php
    $cards = [
        ['active_total', 'Total ativo', 'Conversas abertas ou pendentes', 'neutral'],
        ['unassigned', 'Sem responsável', 'Ainda sem pessoa definida', 'attention'],
        ['human_active', 'Em atendimento humano', 'Atribuídas e no modo humano', 'human'],
        ['awaiting_first_response', 'Aguardando 1ª resposta', 'Relógio humano ainda aberto', 'clock'],
        ['sla_warning', 'SLA em risco', 'Atingiu o alerta preventivo', 'warning'],
        ['sla_breached', 'SLA violado', 'Prazo da 1ª resposta excedido', 'danger'],
    ];
    ?>
    <?php foreach ($cards as [$key, $label, $description, $class]): ?>
        <article class="card operational-load-kpi is-<?= View::e($class) ?>">
            <span><?= View::e($label) ?></span>
            <strong data-operational-kpi="<?= View::e($key) ?>"><?= (int) ($summary[$key] ?? 0) ?></strong>
            <small><?= View::e($description) ?></small>
        </article>
    <?php endforeach; ?>
</div>

<div class="operational-load-grid">
    <section class="card operational-load-team">
        <div class="section-heading"><div><span class="eyebrow">EQUIPE</span><h2>Carga por responsável</h2></div><small>Ordenado por criticidade</small></div>
        <div class="table-wrap">
            <table class="operational-load-table">
                <thead><tr><th>Responsável</th><th>Ativos</th><th>Humano</th><th>Aguardando 1ª</th><th>Em risco</th><th>Violados</th><th>Não lidas</th></tr></thead>
                <tbody>
                <?php if ($team === []): ?><tr><td colspan="7" class="empty-cell">Nenhum atendimento ativo nos filtros atuais.</td></tr><?php endif; ?>
                <?php foreach ($team as $member): ?>
                    <?php $assigneeFilter = (int) ($member['user_id'] ?? 0) > 0 ? (string) (int) $member['user_id'] : 'unassigned'; ?>
                    <tr>
                        <td><a href="<?= View::e(Router::url('/carga-operacional?' . $queryBase(['assigned_user_id' => $assigneeFilter]))) ?>"><strong><?= View::e((string) $member['name']) ?></strong></a></td>
                        <td><?= (int) $member['active'] ?></td><td><?= (int) $member['human_active'] ?></td><td><?= (int) $member['awaiting_first_response'] ?></td>
                        <td class="metric-warning"><?= (int) $member['sla_warning'] ?></td><td class="metric-danger"><?= (int) $member['sla_breached'] ?></td><td><?= (int) $member['unread'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card operational-load-priority">
        <div class="section-heading"><div><span class="eyebrow">AÇÃO</span><h2>Conversas que pedem atenção</h2></div><small>Até 100 conversas ativas</small></div>
        <div class="operational-load-conversation-list">
            <?php if ($conversations === []): ?><div class="empty-state"><strong>Nenhuma conversa ativa.</strong><span>Os filtros atuais não retornaram atendimentos.</span></div><?php endif; ?>
            <?php foreach ($conversations as $conversation): ?>
                <a class="operational-load-conversation is-<?= View::e((string) ($conversation['sla_class'] ?? 'resolved')) ?>" href="<?= View::e(Router::url('/conversations?' . http_build_query(array_filter(['tenant_id' => (int) ($filters['tenant_id'] ?? 0), 'conversation_id' => (int) $conversation['id']], static fn ($v): bool => (int) $v > 0)))) ?>">
                    <div class="operational-load-conversation-main">
                        <strong><?= View::e((string) (($conversation['contact_name'] ?? '') ?: ($conversation['phone'] ?? 'Contato'))) ?></strong>
                        <small><?= View::e((string) (($conversation['assigned_user_name'] ?? '') ?: 'Sem responsável')) ?> · <?= View::e((string) ($conversation['instance_label'] ?? $conversation['instance_name'] ?? 'WhatsApp')) ?></small>
                        <span><?= View::e((string) (($conversation['last_message_preview'] ?? '') ?: 'Sem prévia de mensagem')) ?></span>
                    </div>
                    <div class="operational-load-conversation-side">
                        <span class="sla-chip is-<?= View::e((string) ($conversation['sla_class'] ?? 'resolved')) ?>"><?= View::e((string) ($conversation['sla_label'] ?? '')) ?></span>
                        <?php if (!empty($conversation['awaiting_first_response']) && is_array($conversation['sla'] ?? null)): ?>
                            <small><?= View::e((string) ($conversation['sla_elapsed_label'] ?? '')) ?> decorridos · <?= number_format((float) ($conversation['sla']['percent'] ?? 0), 0, ',', '.') ?>%</small>
                        <?php endif; ?>
                        <?php if ((int) ($conversation['unread_count'] ?? 0) > 0): ?><b><?= (int) $conversation['unread_count'] ?> não lida(s)</b><?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<script>
(() => {
    const live = document.querySelector('[data-operational-load-live]');
    if (!live) return;
    let fingerprint = live.dataset.fingerprint || '';
    const params = new URLSearchParams(window.location.search);
    const endpoint = <?= json_encode(Router::url('/carga-operacional/snapshot'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?> + (params.toString() ? '?' + params.toString() : '');
    const check = async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(endpoint, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok) return;
            const payload = await response.json();
            if (!payload || !payload.ok) return;
            if (fingerprint && payload.fingerprint && payload.fingerprint !== fingerprint) {
                window.location.reload();
                return;
            }
            fingerprint = payload.fingerprint || fingerprint;
        } catch (_) {}
    };
    setInterval(check, 30000);
})();
</script>
