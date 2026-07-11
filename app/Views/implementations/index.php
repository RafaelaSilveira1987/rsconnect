<?php
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$fmtDate = static function (?string $date): string {
    if (!$date) {
        return 'Nunca atualizado';
    }
    $ts = strtotime($date);
    return $ts ? date('d/m/Y H:i', $ts) : $date;
};
$initial = static function (string $name): string {
    $name = trim($name);
    return mb_strtoupper(mb_substr($name !== '' ? $name : 'E', 0, 1));
};
?>

<section class="hero-card compact-hero implementations-hero">
    <div>
        <span class="eyebrow light">Implantação assistida</span>
        <h2>Checklist de clientes RS Connect</h2>
        <p>Centralize o que falta configurar em cada empresa: WhatsApp, IA, n8n, cobrança e validação final. A tela mistura sinais automáticos do sistema com marcações manuais da equipe RS.</p>
    </div>
    <span class="status-pill"><i></i><?= (int) $summary['in_progress'] ?> em acompanhamento</span>
</section>

<section class="metric-grid implementation-metrics">
    <article class="metric-card"><span>Empresas</span><strong><?= (int) $summary['total'] ?></strong><small>Total em carteira</small></article>
    <article class="metric-card"><span>Concluídas</span><strong><?= (int) $summary['completed'] ?></strong><small>Implantação finalizada</small></article>
    <article class="metric-card"><span>Com pendência crítica</span><strong><?= (int) $summary['critical'] ?></strong><small>Exigem ação da RS</small></article>
    <article class="metric-card"><span>Progresso médio</span><strong><?= (int) $summary['avg_progress'] ?>%</strong><small>Média dos checklists</small></article>
</section>

<div class="implementation-list">
    <?php foreach ($cards as $card): ?>
        <?php $tenant = $card['tenant']; ?>
        <article class="card implementation-card <?= $card['is_completed'] ? 'is-complete' : '' ?>">
            <div class="implementation-head">
                <div class="implementation-company">
                    <span class="company-avatar"><?= View::e($initial((string) $tenant['name'])) ?></span>
                    <div>
                        <span class="eyebrow">Empresa</span>
                        <h2><?= View::e($tenant['name']) ?></h2>
                        <p><?= View::e($tenant['email'] ?: 'E-mail não informado') ?><?= $tenant['segment'] ? ' · ' . View::e($tenant['segment']) : '' ?></p>
                    </div>
                </div>
                <div class="implementation-score">
                    <span class="badge badge-<?= View::e($card['status']) ?>"><?= View::e($card['status_label']) ?></span>
                    <strong><?= (int) $card['progress'] ?>%</strong>
                    <small><?= (int) $card['done_count'] ?> de <?= (int) $card['total_count'] ?> itens concluídos</small>
                </div>
            </div>

            <div class="implementation-progress" aria-label="Progresso de implantação">
                <i style="width: <?= (int) $card['progress'] ?>%"></i>
            </div>

            <?php if ($card['critical_count'] > 0 || $card['warning_count'] > 0): ?>
                <div class="implementation-alerts">
                    <?php if ($card['critical_count'] > 0): ?><span class="badge badge-critical"><?= (int) $card['critical_count'] ?> pendência(s) crítica(s)</span><?php endif; ?>
                    <?php if ($card['warning_count'] > 0): ?><span class="badge badge-warning-soft"><?= (int) $card['warning_count'] ?> atenção</span><?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="implementation-sections">
                <?php foreach ($card['sections'] as $sectionName => $items): ?>
                    <section class="implementation-section">
                        <h3><?= View::e($sectionName) ?></h3>
                        <div class="implementation-items">
                            <?php foreach ($items as $item): ?>
                                <div class="implementation-item <?= $item['done'] ? 'is-done' : 'is-pending' ?> severity-<?= View::e($item['severity']) ?>">
                                    <span class="implementation-check" aria-hidden="true"><?= $item['done'] ? '✓' : '!' ?></span>
                                    <div>
                                        <strong><?= View::e($item['label']) ?></strong>
                                        <small><?= $item['done'] ? 'Concluído' : View::e($item['hint']) ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <details class="implementation-details">
                <summary class="btn btn-outline">Atualizar checklist manual</summary>
                <form class="implementation-form" method="post" action="<?= View::e(Router::url('/implementations/save')) ?>">
                    <?= Csrf::input() ?>
                    <input type="hidden" name="tenant_id" value="<?= (int) $tenant['id'] ?>">
                    <div class="form-grid two">
                        <label class="field"><span>Status geral</span><select name="status"><?php foreach ($statusLabels as $key => $label): ?><option value="<?= View::e($key) ?>" <?= $card['status'] === $key ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Última atualização</span><input value="<?= View::e($fmtDate($card['updated_at'])) ?><?= $card['updated_by'] ? ' por ' . View::e($card['updated_by']) : '' ?>" readonly></label>
                    </div>

                    <div class="manual-check-grid">
                        <?php foreach ($card['sections'] as $sectionName => $items): ?>
                            <?php foreach ($items as $item): ?>
                                <?php if (!$item['manual'] || !$item['field']) { continue; } ?>
                                <label class="check-field manual-check">
                                    <input type="checkbox" name="<?= View::e($item['field']) ?>" value="1" <?= !empty($card['manual'][$item['field']]) ? 'checked' : '' ?>>
                                    <span><?= View::e($item['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>

                    <label class="field"><span>Observações internas</span><textarea name="notes" rows="4" placeholder="Ex.: cliente pediu suporte na conexão do WhatsApp; fluxo de cobrança ficará para segunda etapa."><?= View::e($card['notes']) ?></textarea></label>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">Salvar checklist</button>
                    </div>
                </form>
            </details>
        </article>
    <?php endforeach; ?>
    <?php if (!$cards): ?>
        <section class="card"><div class="empty-state">Nenhuma empresa cadastrada ainda.</div></section>
    <?php endif; ?>
</div>
