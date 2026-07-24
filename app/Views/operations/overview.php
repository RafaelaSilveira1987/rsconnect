<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$verification = $data['verification'] ?? [];
$summary = $data['summary'] ?? [];
$issues = $data['issues'] ?? [];
$services = $data['services'] ?? [];
$routines = $data['routines'] ?? [];
$companies = $data['companies'] ?? [];
$history = $data['history'] ?? [];

$statusLabel = static fn (string $status): string => match ($status) {
    'operational' => 'Operando',
    'critical' => 'Crítico',
    'attention' => 'Atenção',
    'blocked' => 'Bloqueio externo',
    'unknown' => 'Sem evidência',
    'neutral' => 'Não configurado',
    default => 'Informação',
};
$companyStatusLabel = static fn (string $status): string => match ($status) {
    'operational' => 'Operando',
    'critical' => 'Crítico',
    'attention' => 'Revisar',
    'blocked' => 'Aguardando',
    'unknown' => 'Sem evidência',
    default => 'Sem configuração',
};
$summaryState = (string) ($summary['state'] ?? 'unknown');
$verificationState = (string) ($verification['state'] ?? 'unverified');
$historySummary = $history['summary'] ?? ['ok' => 0, 'warning' => 0, 'down' => 0];
?>

<section class="health-command-center is-<?= View::e($summaryState) ?>">
    <div class="health-command-copy">
        <span class="eyebrow">Operação RS · saúde do sistema</span>
        <div class="health-command-title-row">
            <span class="health-command-state" aria-hidden="true"></span>
            <div>
                <h1><?= View::e((string) ($summary['label'] ?? 'Verificação necessária')) ?></h1>
                <p><?= View::e((string) ($summary['message'] ?? 'Atualize as evidências para avaliar a saúde do RS Connect.')) ?></p>
            </div>
        </div>
        <div class="health-verification-line is-<?= View::e($verificationState) ?>">
            <strong><?= View::e((string) ($verification['label'] ?? 'Ainda não verificado')) ?></strong>
            <span><?= View::e((string) ($verification['message'] ?? '')) ?></span>
            <?php if (!empty($verification['last_checked_at'])): ?>
                <small>Última evidência: <?= View::e((string) $verification['last_checked_at']) ?> · <?= View::e((string) ($verification['last_checked_label'] ?? '')) ?></small>
            <?php endif; ?>
        </div>
    </div>
    <form method="post" action="<?= View::e(Router::url('/operations/checks/run')) ?>" class="health-command-action">
        <?= Csrf::input() ?>
        <input type="hidden" name="return_to" value="/painel-operacional">
        <button class="btn btn-primary" type="submit">Verificar sistema agora</button>
        <small>A verificação atualiza integrações, rotinas e infraestrutura.</small>
    </form>
</section>

<section class="health-kpi-strip" aria-label="Resumo da saúde">
    <div class="health-kpi is-operational"><span>Disponíveis</span><strong><?= (int) ($summary['available'] ?? 0) ?></strong><small>de <?= (int) ($summary['services_total'] ?? 0) ?> serviços</small></div>
    <div class="health-kpi is-critical"><span>Críticos</span><strong><?= (int) ($summary['critical'] ?? 0) ?></strong><small>ação imediata</small></div>
    <div class="health-kpi is-attention"><span>Atenções</span><strong><?= (int) ($summary['attention'] ?? 0) ?></strong><small>revisar</small></div>
    <div class="health-kpi is-blocked"><span>Bloqueios externos</span><strong><?= (int) ($summary['blocked'] ?? 0) ?></strong><small>dependências</small></div>
    <div class="health-kpi is-unknown"><span>Sem evidência</span><strong><?= (int) ($summary['unknown'] ?? 0) ?></strong><small>precisam validar</small></div>
    <div class="health-kpi is-companies"><span>Empresas afetadas</span><strong><?= (int) ($summary['affected_companies'] ?? 0) ?></strong><small>com impacto atual</small></div>
</section>

<section class="card health-panel health-active-panel">
    <div class="health-panel-heading">
        <div>
            <span class="eyebrow">Situação atual</span>
            <h2>Problemas ativos</h2>
            <p>O que aconteceu, qual o impacto e qual ação deve ser tomada. Falhas antigas ficam fora desta leitura principal.</p>
        </div>
        <span class="health-count-badge"><?= count($issues) ?> ativo(s)</span>
    </div>

    <?php if ($issues): ?>
        <div class="health-issue-list" data-collapsible-list="3">
            <?php foreach ($issues as $issue): ?>
                <?php $issueStatus = (string) ($issue['status'] ?? 'unknown'); ?>
                <article class="health-issue is-<?= View::e($issueStatus) ?>">
                    <span class="health-issue-dot" aria-hidden="true"></span>
                    <div class="health-issue-body">
                        <div class="health-issue-topline">
                            <span class="health-status-pill is-<?= View::e($issueStatus) ?>"><?= View::e($statusLabel($issueStatus)) ?></span>
                            <?php if (!empty($issue['tenant_name'])): ?><span class="health-issue-tenant"><?= View::e((string) $issue['tenant_name']) ?></span><?php endif; ?>
                        </div>
                        <h3><?= View::e((string) ($issue['title'] ?? 'Ponto de atenção')) ?></h3>
                        <p><?= View::e((string) ($issue['summary'] ?? '')) ?></p>
                        <div class="health-issue-facts">
                            <?php if (!empty($issue['impact'])): ?><div><strong>Impacto</strong><span><?= View::e((string) $issue['impact']) ?></span></div><?php endif; ?>
                            <?php if (!empty($issue['recommended_action'])): ?><div><strong>Ação recomendada</strong><span><?= View::e((string) $issue['recommended_action']) ?></span></div><?php endif; ?>
                        </div>
                        <?php if (!empty($issue['meta'])): ?><small class="health-evidence-meta"><?= View::e((string) $issue['meta']) ?></small><?php endif; ?>
                        <?php if (!empty($issue['technical_details']) && trim((string) $issue['technical_details']) !== trim((string) ($issue['summary'] ?? ''))): ?>
                            <details class="health-technical-details">
                                <summary>Ver detalhes técnicos</summary>
                                <pre><?= View::e((string) $issue['technical_details']) ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>
                    <div class="health-issue-actions">
                        <?php if (!empty($issue['action_url'])): ?><a class="btn btn-small btn-primary" href="<?= View::e(Router::url((string) $issue['action_url'])) ?>"><?= View::e((string) ($issue['action_label'] ?? 'Abrir')) ?></a><?php endif; ?>
                        <?php if (!empty($issue['secondary_url'])): ?><a class="btn btn-small btn-quiet" href="<?= View::e(Router::url((string) $issue['secondary_url'])) ?>"><?= View::e((string) ($issue['secondary_label'] ?? 'Detalhes')) ?></a><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php elseif ($verificationState === 'complete'): ?>
        <div class="health-all-clear">
            <span aria-hidden="true">✓</span>
            <div><strong>Nenhum problema operacional ativo.</strong><p>A última verificação completa não encontrou situações que exijam ação.</p></div>
        </div>
    <?php else: ?>
        <div class="health-unverified-message">
            <span aria-hidden="true">?</span>
            <div><strong>Ainda não é possível afirmar que está tudo bem.</strong><p>Há evidências ausentes ou antigas. Use “Verificar sistema agora” para concluir a leitura.</p></div>
        </div>
    <?php endif; ?>
</section>

<section class="card health-panel">
    <div class="health-panel-heading">
        <div>
            <span class="eyebrow">Serviços essenciais</span>
            <h2>Saúde dos serviços</h2>
            <p>Um serviço só fica verde quando existe evidência positiva dentro da janela esperada para aquele tipo de validação.</p>
        </div>
        <a class="btn btn-small btn-quiet" href="<?= View::e(Router::url('/central-operacao')) ?>">Central técnica</a>
    </div>

    <div class="health-services-table-wrap">
        <table class="health-services-table">
            <thead><tr><th>Serviço</th><th>Estado</th><th>Evidência</th><th>Última validação</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($services as $service): ?>
                <?php $serviceStatus = (string) ($service['status'] ?? 'unknown'); ?>
                <tr class="is-<?= View::e($serviceStatus) ?>">
                    <td><strong><?= View::e((string) ($service['label'] ?? 'Serviço')) ?></strong><small><?= View::e((string) ($service['category'] ?? '')) ?></small></td>
                    <td><span class="health-status-pill is-<?= View::e($serviceStatus) ?>"><?= View::e($statusLabel($serviceStatus)) ?></span></td>
                    <td>
                        <span class="health-service-evidence"><?= View::e((string) ($service['evidence'] ?? 'Sem evidência.')) ?></span>
                        <?php if (($service['latency_ms'] ?? null) !== null): ?><small><?= (int) $service['latency_ms'] ?> ms</small><?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($service['checked_at'])): ?>
                            <strong><?= View::e((string) ($service['age_label'] ?? '')) ?></strong>
                            <small><?= View::e((string) $service['checked_at']) ?></small>
                        <?php else: ?>
                            <strong>Nunca</strong><small>Sem registro</small>
                        <?php endif; ?>
                    </td>
                    <td class="health-service-actions">
                        <a class="btn btn-small btn-quiet" href="<?= View::e(Router::url((string) ($service['route'] ?? '/central-operacao'))) ?>">Abrir</a>
                        <?php if (!empty($service['technical_details']) && trim((string) $service['technical_details']) !== trim((string) ($service['evidence'] ?? ''))): ?>
                            <details class="health-service-detail">
                                <summary aria-label="Ver detalhe técnico">Detalhes</summary>
                                <div><strong>Ação recomendada</strong><p><?= View::e((string) ($service['recommended_action'] ?? 'Revisar a ferramenta.')) ?></p><pre><?= View::e((string) $service['technical_details']) ?></pre></div>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="health-two-column">
    <section class="card health-panel">
        <div class="health-panel-heading compact">
            <div>
                <span class="eyebrow">Rotinas automáticas</span>
                <h2>Execuções essenciais</h2>
                <p>Última execução, resultado observado e próxima execução esperada.</p>
            </div>
        </div>
        <div class="health-routine-list">
            <?php foreach ($routines as $routine): ?>
                <?php $routineStatus = (string) ($routine['status'] ?? 'unknown'); ?>
                <a class="health-routine" href="<?= View::e(Router::url((string) ($routine['route'] ?? '/central-operacao'))) ?>">
                    <span class="health-routine-dot is-<?= View::e($routineStatus) ?>"></span>
                    <div class="health-routine-name"><strong><?= View::e((string) ($routine['label'] ?? 'Rotina')) ?></strong><span class="health-status-pill is-<?= View::e($routineStatus) ?>"><?= View::e($statusLabel($routineStatus)) ?></span></div>
                    <div><small>Última execução</small><strong><?= View::e((string) ($routine['last_execution'] ?? 'Sem registro')) ?></strong></div>
                    <div><small>Próxima esperada</small><strong><?= View::e((string) ($routine['next_expected'] ?? '—')) ?></strong></div>
                    <p><?= View::e((string) ($routine['result'] ?? '')) ?></p>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card health-panel">
        <div class="health-panel-heading compact">
            <div>
                <span class="eyebrow">Evidências nas últimas 24h</span>
                <h2>Histórico recente</h2>
                <p>Ajuda a diferenciar uma ocorrência isolada de uma instabilidade recorrente.</p>
            </div>
        </div>
        <div class="health-history-kpis">
            <div class="is-operational"><strong><?= (int) ($historySummary['ok'] ?? 0) ?></strong><span>OK</span></div>
            <div class="is-attention"><strong><?= (int) ($historySummary['warning'] ?? 0) ?></strong><span>Atenções</span></div>
            <div class="is-critical"><strong><?= (int) ($historySummary['down'] ?? 0) ?></strong><span>Críticos</span></div>
        </div>
        <div class="health-history-list" data-collapsible-list="3">
            <?php foreach (($history['events'] ?? []) as $event): ?>
                <?php $eventStatus = match ((string) ($event['status'] ?? 'warning')) { 'ok' => 'operational', 'down' => 'critical', default => 'attention' }; ?>
                <div class="health-history-event">
                    <span class="health-routine-dot is-<?= View::e($eventStatus) ?>"></span>
                    <div><strong><?= View::e((string) ($event['label'] ?? $event['check_key'] ?? 'Verificação')) ?></strong><small><?= View::e((string) ($event['checked_at'] ?? '')) ?></small></div>
                    <span class="health-status-pill is-<?= View::e($eventStatus) ?>"><?= View::e($statusLabel($eventStatus)) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (empty($history['events'])): ?><div class="empty-state">Nenhuma evidência registrada nas últimas 24 horas.</div><?php endif; ?>
        </div>
    </section>
</div>

<section class="card health-panel health-company-panel">
    <div class="health-panel-heading">
        <div>
            <span class="eyebrow">Saúde por empresa</span>
            <h2>Quem precisa de atenção</h2>
            <p>Conexão, IA, agenda e financeiro com evidência por cliente. Estados antigos deixam de aparecer como “operando”.</p>
        </div>
        <a class="btn btn-small btn-quiet" href="<?= View::e(Router::url('/companies/health')) ?>">Diagnóstico completo</a>
    </div>
    <div class="health-company-table-wrap">
        <table class="health-company-table">
            <thead><tr><th>Empresa</th><th>WhatsApp</th><th>IA</th><th>Agenda</th><th>Financeiro</th><th>Situação</th></tr></thead>
            <tbody>
            <?php foreach ($companies as $company): ?>
                <tr>
                    <td><strong><?= View::e((string) ($company['name'] ?? 'Empresa')) ?></strong><?php if ((int) ($company['pending'] ?? 0) > 0): ?><small><?= (int) $company['pending'] ?> conversa(s) pendente(s)</small><?php endif; ?></td>
                    <?php foreach (['whatsapp', 'ia', 'agenda', 'finance'] as $field): ?>
                        <?php $cell = $company[$field] ?? ['status' => 'neutral', 'label' => '—', 'evidence' => '']; ?>
                        <td><span class="health-company-cell is-<?= View::e((string) ($cell['status'] ?? 'neutral')) ?>"><i></i><strong><?= View::e((string) ($cell['label'] ?? '—')) ?></strong><?php if (!empty($cell['evidence'])): ?><small><?= View::e((string) $cell['evidence']) ?></small><?php endif; ?></span></td>
                    <?php endforeach; ?>
                    <td><span class="health-company-overall is-<?= View::e((string) ($company['status'] ?? 'neutral')) ?>"><?= View::e($companyStatusLabel((string) ($company['status'] ?? 'neutral'))) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$companies): ?><tr><td colspan="6"><div class="empty-state">Nenhuma empresa disponível para leitura operacional.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="health-technical-footer">
    <div><span class="eyebrow">Investigação técnica</span><strong>A Central de operação original continua intacta.</strong><p>Use o Painel operacional para decidir rapidamente onde agir e a Central para logs, históricos e configurações detalhadas.</p></div>
    <a class="btn btn-outline" href="<?= View::e(Router::url('/central-operacao')) ?>">Abrir Central de operação</a>
</section>
