<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$status = $data['status'] ?? [];
$attention = $data['attention'] ?? [];
$healthy = $data['healthy'] ?? [];
$routines = $data['routines'] ?? [];
$companies = $data['companies'] ?? [];
$technical = $data['technical'] ?? [];

$levelLabel = static fn (string $level): string => match ($level) {
    'critical' => 'Crítico',
    'blocked' => 'Bloqueado externamente',
    'warning' => 'Atenção',
    'unknown' => 'Sem evidência',
    'ok' => 'Operando',
    default => 'Informação',
};
$cellLabel = static fn (string $statusKey): string => match ($statusKey) {
    'ok' => 'OK',
    'warning' => 'Atenção',
    'blocked' => 'Aguardando',
    'neutral' => 'Neutro',
    default => '—',
};
$lastCheckedAt = trim((string) ($status['last_checked_at'] ?? ''));
?>

<section class="operational-overview-hero is-<?= View::e((string) ($status['key'] ?? 'operational')) ?>">
    <div class="operational-overview-hero-main">
        <div>
            <span class="eyebrow">Operação RS · nova visão</span>
            <h2><?= View::e((string) ($status['label'] ?? 'Painel operacional')) ?></h2>
            <p><?= View::e((string) ($status['message'] ?? 'Leitura simplificada da saúde do SaaS.')) ?></p>
        </div>
        <form method="post" action="<?= View::e(Router::url('/operations/checks/run')) ?>" class="operational-overview-refresh">
            <?= Csrf::input() ?>
            <input type="hidden" name="return_to" value="/painel-operacional">
            <button class="btn btn-primary" type="submit">Verificar agora</button>
            <small><?= $lastCheckedAt !== '' ? 'Última verificação: ' . View::e($lastCheckedAt) : 'Ainda sem verificação completa.' ?></small>
        </form>
    </div>

    <div class="operational-overview-summary">
        <div><span>Críticos</span><strong><?= (int) ($status['critical'] ?? 0) ?></strong></div>
        <div><span>Atenções</span><strong><?= (int) ($status['warning'] ?? 0) ?></strong></div>
        <div><span>Bloqueios externos</span><strong><?= (int) ($status['blocked'] ?? 0) ?></strong></div>
        <div><span>Empresas afetadas</span><strong><?= (int) ($status['affected_companies'] ?? 0) ?></strong></div>
    </div>
</section>

<section class="card operational-overview-section operational-attention-section">
    <div class="operational-section-heading">
        <div>
            <span class="eyebrow">Precisa da sua atenção</span>
            <h2>Problemas e ações recomendadas</h2>
            <p>Somente situações que exigem ação, acompanhamento ou confirmação aparecem aqui.</p>
        </div>
        <span class="badge"><?= count($attention) ?> item(ns)</span>
    </div>

    <?php if ($attention): ?>
        <div class="operational-attention-list" data-collapsible-list="3">
            <?php foreach ($attention as $item): ?>
                <?php $level = (string) ($item['level'] ?? 'warning'); ?>
                <article class="operational-attention-card is-<?= View::e($level) ?>">
                    <span class="operational-attention-icon" aria-hidden="true"></span>
                    <div class="operational-attention-copy">
                        <span class="operational-level-label"><?= View::e($levelLabel($level)) ?></span>
                        <h3><?= View::e((string) ($item['title'] ?? 'Ponto de atenção')) ?></h3>
                        <p><?= View::e((string) ($item['summary'] ?? '')) ?></p>
                        <?php if (!empty($item['impact'])): ?>
                            <div class="operational-impact"><strong>Impacto</strong><span><?= View::e((string) $item['impact']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($item['meta'])): ?><small><?= View::e((string) $item['meta']) ?></small><?php endif; ?>
                    </div>
                    <div class="operational-attention-actions">
                        <?php if (!empty($item['action_url'])): ?>
                            <a class="btn btn-small btn-primary" href="<?= View::e(Router::url((string) $item['action_url'])) ?>"><?= View::e((string) ($item['action_label'] ?? 'Abrir')) ?></a>
                        <?php endif; ?>
                        <?php if (!empty($item['secondary_url'])): ?>
                            <a class="btn btn-small btn-quiet" href="<?= View::e(Router::url((string) $item['secondary_url'])) ?>"><?= View::e((string) ($item['secondary_label'] ?? 'Detalhes')) ?></a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="operational-all-clear">
            <span aria-hidden="true">✓</span>
            <div><strong>Nenhum problema operacional exige ação agora.</strong><p>Integrações e rotinas monitoradas não apresentaram pendências relevantes na última verificação.</p></div>
        </div>
    <?php endif; ?>
</section>

<div class="operational-overview-grid">
    <section class="card operational-overview-section">
        <div class="operational-section-heading compact">
            <div><span class="eyebrow">Operando normalmente</span><h2>Saúde confirmada</h2><p>Itens saudáveis ficam compactos para não competir com os problemas.</p></div>
            <span class="badge badge-success"><?= count($healthy) ?> OK</span>
        </div>
        <div class="operational-healthy-list" data-collapsible-list="6">
            <?php foreach ($healthy as $item): ?>
                <a class="operational-healthy-row" href="<?= View::e(Router::url((string) ($item['route'] ?? '/central-operacao'))) ?>">
                    <span class="operational-health-check">✓</span>
                    <strong><?= View::e((string) ($item['label'] ?? 'Serviço')) ?></strong>
                    <small><?= View::e((string) ($item['message'] ?? 'Operando.')) ?></small>
                </a>
            <?php endforeach; ?>
            <?php if (!$healthy): ?><div class="empty-state">Nenhum item com evidência saudável nesta verificação.</div><?php endif; ?>
        </div>
    </section>

    <section class="card operational-overview-section">
        <div class="operational-section-heading compact">
            <div><span class="eyebrow">Rotinas automáticas</span><h2>Execuções essenciais</h2><p>Resumo do que precisa funcionar sozinho no dia a dia.</p></div>
        </div>
        <div class="operational-routine-list">
            <?php foreach ($routines as $routine): ?>
                <?php $routineStatus = (string) ($routine['status'] ?? 'unknown'); ?>
                <a class="operational-routine-row is-<?= View::e($routineStatus) ?>" href="<?= View::e(Router::url((string) ($routine['route'] ?? '/central-operacao'))) ?>">
                    <span class="operational-routine-dot"></span>
                    <div><strong><?= View::e((string) ($routine['label'] ?? 'Rotina')) ?></strong><small><?= View::e((string) ($routine['message'] ?? '')) ?></small></div>
                    <span class="operational-routine-state"><?= View::e($routineStatus === 'blocked' ? 'Aguardando' : $levelLabel($routineStatus === 'down' ? 'critical' : ($routineStatus === 'ok' ? 'ok' : ($routineStatus === 'unknown' ? 'unknown' : 'warning')))) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<section class="card operational-overview-section operational-company-section">
    <div class="operational-section-heading">
        <div>
            <span class="eyebrow">Situação por empresa</span>
            <h2>Quem precisa de atenção</h2>
            <p>Leitura rápida de WhatsApp, IA, agenda e cobrança. Empresas com atenção aparecem primeiro.</p>
        </div>
        <a class="btn btn-small btn-quiet" href="<?= View::e(Router::url('/companies/health')) ?>">Diagnóstico completo</a>
    </div>

    <div class="operational-company-table-wrap">
        <table class="operational-company-table">
            <thead><tr><th>Empresa</th><th>WhatsApp</th><th>IA</th><th>Agenda</th><th>Cobrança</th><th>Situação</th></tr></thead>
            <tbody>
            <?php foreach ($companies as $company): ?>
                <tr>
                    <td><strong><?= View::e((string) ($company['name'] ?? 'Empresa')) ?></strong><?php if ((int) ($company['pending'] ?? 0) > 0): ?><small><?= (int) $company['pending'] ?> conversa(s) pendente(s)</small><?php endif; ?></td>
                    <?php foreach (['whatsapp', 'ia', 'agenda', 'finance'] as $field): ?>
                        <?php $cell = $company[$field] ?? ['status' => 'neutral', 'label' => '—']; ?>
                        <td><span class="operational-company-status is-<?= View::e((string) ($cell['status'] ?? 'neutral')) ?>"><i></i><?= View::e((string) ($cell['label'] ?? '—')) ?></span></td>
                    <?php endforeach; ?>
                    <td><span class="operational-company-overall is-<?= View::e((string) ($company['status'] ?? 'neutral')) ?>"><?= View::e(match ((string) ($company['status'] ?? 'neutral')) { 'warning' => 'Revisar', 'blocked' => 'Aguardando', 'ok' => 'Operando', default => 'Sem configuração' }) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$companies): ?><tr><td colspan="6"><div class="empty-state">Nenhuma empresa disponível para leitura operacional.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="operational-technical-footer">
    <div>
        <span class="eyebrow">Precisa investigar?</span>
        <strong>A Central de operação original continua intacta.</strong>
        <p>Use este painel para leitura rápida e abra a Central quando precisar de históricos, logs, configurações ou detalhes técnicos.</p>
    </div>
    <a class="btn btn-outline" href="<?= View::e(Router::url('/central-operacao')) ?>">Abrir Central de operação</a>
</section>
