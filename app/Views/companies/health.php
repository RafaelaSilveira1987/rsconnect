<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$data = $healthData ?? [];
$tenant = $data['tenant'] ?? [];
$snapshot = $data['snapshot'] ?? [];
$groups = $data['groups'] ?? [];
$incidents = $data['incidents'] ?? [];
$events = $data['events'] ?? [];
$summary = $data['summary'] ?? [];
$tracking = $data['tracking'] ?? [];
$occurrences = $data['occurrences'] ?? [];
$occurrenceSummary = $data['occurrence_summary'] ?? [];
$configuration = $data['configuration'] ?? [];
$configGroups = $configuration['groups'] ?? [];
$tenantId = (int) ($tenant['id'] ?? 0);

$statusLabels = [
    'healthy' => 'Saudável',
    'attention' => 'Atenção',
    'critical' => 'Crítico',
    'idle' => 'Sem atividade recente',
    'blocked' => 'Bloqueado',
];
$checkLabels = ['ok' => 'Operacional', 'info' => 'Informação', 'warning' => 'Atenção', 'critical' => 'Crítico'];
$configToneLabels = ['ok' => 'Configurado', 'info' => 'Informação', 'warning' => 'Revisar', 'critical' => 'Crítico'];
$incidentLabels = ['open' => 'Aberto', 'acknowledged' => 'Visualizado', 'monitoring' => 'Em acompanhamento', 'resolved' => 'Resolvido'];
$eventLabels = [
    'opened' => 'Problema identificado',
    'reopened' => 'Problema reaberto',
    'acknowledged' => 'Problema visualizado',
    'monitoring' => 'Acompanhamento iniciado',
    'resolved' => 'Problema resolvido',
    'auto_resolved' => 'Normalização confirmada',
    'note' => 'Observação adicionada',
];
$formatDate = static function (?string $value): string {
    if (!$value) return 'Não disponível';
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : $value;
};
$relative = static function (?string $value): string {
    if (!$value || !($time = strtotime($value))) return 'sem registro';
    $seconds = time() - $time;
    if ($seconds < 60) return 'agora';
    if ($seconds < 3600) return 'há ' . max(1, (int) floor($seconds / 60)) . ' min';
    if ($seconds < 86400) return 'há ' . max(1, (int) floor($seconds / 3600)) . ' h';
    return 'há ' . max(1, (int) floor($seconds / 86400)) . ' dia(s)';
};
$overall = (string) ($snapshot['overall_status'] ?? 'attention');
$occurrenceFilter = (string) ($_GET['occurrence_filter'] ?? 'unreviewed');
if (!in_array($occurrenceFilter, ['unreviewed', 'all', 'ai', 'integration', 'reviewed'], true)) {
    $occurrenceFilter = 'unreviewed';
}
$visibleOccurrences = array_values(array_filter($occurrences, static function (array $item) use ($occurrenceFilter): bool {
    return match ($occurrenceFilter) {
        'all' => true,
        'ai' => ($item['source'] ?? '') === 'ai',
        'integration' => ($item['source'] ?? '') === 'integration',
        'reviewed' => !empty($item['reviewed']),
        default => empty($item['reviewed']),
    };
}));
$trackingPriority = (string) ($tracking['priority'] ?? 'attention');
if (!in_array($trackingPriority, ['attention', 'critical', 'implantation'], true)) {
    $trackingPriority = 'attention';
}
?>

<section class="tenant-health-hero is-<?= View::e($overall) ?>">
    <div>
        <span class="eyebrow">Saúde do cliente</span>
        <h2><?= View::e((string) ($tenant['name'] ?? 'Empresa')) ?></h2>
        <p>Veja se WhatsApp, assistentes, integrações, agenda, assinatura e acessos estão funcionando agora.</p>
        <?php if (!empty($snapshot)): ?>
            <small>Última verificação: <?= View::e($formatDate((string) ($snapshot['checked_at'] ?? ''))) ?> · <?= View::e((string) ($snapshot['checked_by_name'] ?? 'Rotina automática')) ?></small>
        <?php endif; ?>
    </div>
    <div class="tenant-health-hero-actions">
        <span class="tenant-health-status is-<?= View::e($overall) ?>"><?= View::e($statusLabels[$overall] ?? 'Atenção') ?></span>
        <strong><?= (int) ($snapshot['score'] ?? 0) ?>%</strong>
        <button class="btn btn-outline" type="button" data-toggle-panel="tenant-health-config-drawer">Ver todas as configurações</button>
        <form method="post" action="<?= View::e(Router::url('/companies/health/run')) ?>">
            <?= Csrf::input() ?>
            <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
            <button class="btn btn-primary" type="submit">Verificar agora</button>
        </form>
        <a class="btn btn-outline" href="<?= View::e(Router::url('/companies/overview?id=' . $tenantId)) ?>">Voltar à empresa</a>
    </div>
</section>

<section class="tenant-health-summary-grid" aria-label="Resumo da saúde">
    <article><span class="health-summary-icon is-ok">✓</span><div><small>Operacionais</small><strong><?= (int) ($snapshot['ok_count'] ?? 0) ?></strong><em>componentes sem falhas</em></div></article>
    <article><span class="health-summary-icon is-warning">!</span><div><small>Pontos de atenção</small><strong><?= (int) ($snapshot['warning_count'] ?? 0) ?></strong><em>precisam ser revisados</em></div></article>
    <article><span class="health-summary-icon is-critical">×</span><div><small>Problemas críticos</small><strong><?= (int) ($snapshot['critical_count'] ?? 0) ?></strong><em>podem interromper o atendimento</em></div></article>
    <article><span class="health-summary-icon is-monitoring">◎</span><div><small>Em acompanhamento</small><strong><?= (int) ($summary['monitoring'] ?? 0) ?></strong><em>vistos pela equipe RS</em></div></article>
</section>

<?php if (empty($snapshot)): ?>
<section class="card tenant-health-empty">
    <h2>Execute a primeira verificação</h2>
    <p>O diagnóstico ainda não possui dados desta empresa.</p>
</section>
<?php else: ?>
<section class="tenant-health-section-heading">
    <div><span class="eyebrow">Diagnóstico atual</span><h2>Componentes da operação</h2><p>Itens informativos não são tratados como falha. Uma empresa sem mensagens recentes pode apenas estar sem atividade.</p></div>
</section>

<div class="tenant-health-category-grid">
    <?php foreach ($groups as $category => $checks): ?>
        <?php
        $categoryWorst = 'ok';
        foreach ($checks as $item) {
            if (($item['status'] ?? '') === 'critical') { $categoryWorst = 'critical'; break; }
            if (($item['status'] ?? '') === 'warning') $categoryWorst = 'warning';
            elseif (($item['status'] ?? '') === 'info' && $categoryWorst === 'ok') $categoryWorst = 'info';
        }
        ?>
        <section class="card tenant-health-category is-<?= View::e($categoryWorst) ?>">
            <header>
                <div><span class="tenant-health-dot is-<?= View::e($categoryWorst) ?>"></span><h3><?= View::e((string) $category) ?></h3></div>
                <span class="badge health-badge is-<?= View::e($categoryWorst) ?>"><?= View::e($checkLabels[$categoryWorst] ?? 'Informação') ?></span>
            </header>
            <div class="tenant-health-check-list">
                <?php foreach ($checks as $check): ?>
                    <article class="tenant-health-check is-<?= View::e((string) ($check['status'] ?? 'info')) ?>">
                        <div class="tenant-health-check-main">
                            <span class="tenant-health-dot is-<?= View::e((string) ($check['status'] ?? 'info')) ?>"></span>
                            <div>
                                <strong><?= View::e((string) ($check['component_label'] ?? 'Verificação')) ?></strong>
                                <p><?= View::e((string) ($check['summary'] ?? '')) ?></p>
                                <small>Verificado <?= View::e($relative((string) ($check['checked_at'] ?? ''))) ?></small>
                            </div>
                            <span class="health-check-label is-<?= View::e((string) ($check['status'] ?? 'info')) ?>"><?= View::e($checkLabels[(string) ($check['status'] ?? 'info')] ?? 'Informação') ?></span>
                        </div>
                        <div class="tenant-health-check-actions">
                            <?php if (!empty($check['action_url'])): ?><a class="btn btn-quiet" href="<?= View::e(Router::url((string) $check['action_url'])) ?>">Abrir configuração</a><?php endif; ?>
                            <?php
                            $pendingConversationCount = (int) (($check['details']['Conversas aguardando resposta'] ?? 0));
                            $componentKey = (string) ($check['component_key'] ?? '');
                            $pendingAgentId = str_starts_with($componentKey, 'agent.') ? (int) substr($componentKey, 6) : 0;
                            ?>
                            <?php if ($pendingConversationCount > 0 && $pendingAgentId > 0): ?>
                                <form method="post" action="<?= View::e(Router::url('/companies/health/reprocess-ai')) ?>">
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                                    <input type="hidden" name="agent_id" value="<?= $pendingAgentId ?>">
                                    <button class="btn btn-primary" type="submit">Reprocessar agora</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!empty($check['details'])): ?>
                                <details>
                                    <summary>Ver detalhes</summary>
                                    <dl>
                                        <?php foreach ($check['details'] as $label => $value): ?>
                                            <div><dt><?= View::e((string) $label) ?></dt><dd><?= View::e((string) $value) ?></dd></div>
                                        <?php endforeach; ?>
                                    </dl>
                                </details>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<section class="card tenant-health-occurrences" id="occurrences">
    <div class="section-heading tenant-health-occurrence-heading">
        <div>
            <span class="eyebrow">Ocorrências registradas</span>
            <h2>Falhas de IA e integrações</h2>
            <p>Esta lista mostra exatamente os registros que geram as marcações “ainda não revisadas” na tela de empresas.</p>
        </div>
        <span class="badge badge-warning"><?= (int) ($occurrenceSummary['unreviewed'] ?? 0) ?> não revisada(s)</span>
    </div>

    <div class="tenant-health-occurrence-summary">
        <a class="<?= $occurrenceFilter === 'unreviewed' ? 'is-active' : '' ?>" href="<?= View::e(Router::url('/companies/health?tenant_id=' . $tenantId . '&occurrence_filter=unreviewed#occurrences')) ?>"><small>Não revisadas</small><strong><?= (int) ($occurrenceSummary['unreviewed'] ?? 0) ?></strong></a>
        <a class="<?= $occurrenceFilter === 'ai' ? 'is-active' : '' ?>" href="<?= View::e(Router::url('/companies/health?tenant_id=' . $tenantId . '&occurrence_filter=ai#occurrences')) ?>"><small>Falhas de IA</small><strong><?= (int) ($occurrenceSummary['ai'] ?? 0) ?></strong></a>
        <a class="<?= $occurrenceFilter === 'integration' ? 'is-active' : '' ?>" href="<?= View::e(Router::url('/companies/health?tenant_id=' . $tenantId . '&occurrence_filter=integration#occurrences')) ?>"><small>Integrações</small><strong><?= (int) ($occurrenceSummary['integration'] ?? 0) ?></strong></a>
        <a class="<?= $occurrenceFilter === 'reviewed' ? 'is-active' : '' ?>" href="<?= View::e(Router::url('/companies/health?tenant_id=' . $tenantId . '&occurrence_filter=reviewed#occurrences')) ?>"><small>Já revisadas</small><strong><?= (int) ($occurrenceSummary['reviewed'] ?? 0) ?></strong></a>
        <a class="<?= $occurrenceFilter === 'all' ? 'is-active' : '' ?>" href="<?= View::e(Router::url('/companies/health?tenant_id=' . $tenantId . '&occurrence_filter=all#occurrences')) ?>"><small>Todas</small><strong><?= (int) ($occurrenceSummary['total'] ?? 0) ?></strong></a>
    </div>

    <?php if ((int) ($occurrenceSummary['unreviewed'] ?? 0) > 0): ?>
        <form class="tenant-health-occurrence-reviewbar" method="post" action="<?= View::e(Router::url('/companies/tracking')) ?>">
            <?= Csrf::input() ?>
            <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
            <input type="hidden" name="priority" value="<?= View::e($trackingPriority) ?>">
            <input type="hidden" name="return_to" value="/companies/health?tenant_id=<?= $tenantId ?>&occurrence_filter=unreviewed#occurrences">
            <label class="field">
                <span>Observação da revisão</span>
                <input name="note" placeholder="Ex.: credencial corrigida e conversa testada.">
            </label>
            <div>
                <button class="btn btn-outline" name="tracking_status" value="reviewed" type="submit">Marcar todas como revisadas</button>
                <button class="btn btn-primary" name="tracking_status" value="resolved" type="submit">Marcar empresa como corrigida</button>
            </div>
            <small>Ao revisar, as marcações antigas desaparecem. Se surgir uma falha nova, a empresa volta automaticamente para Atenção.</small>
        </form>
    <?php endif; ?>

    <div class="tenant-health-occurrence-list">
        <?php foreach ($visibleOccurrences as $occurrence): ?>
            <article class="tenant-health-occurrence <?= !empty($occurrence['reviewed']) ? 'is-reviewed' : 'is-unreviewed' ?>">
                <div class="tenant-health-occurrence-icon is-<?= View::e((string) ($occurrence['source'] ?? 'integration')) ?>">
                    <?= ($occurrence['source'] ?? '') === 'ai' ? 'IA' : 'IN' ?>
                </div>
                <div class="tenant-health-occurrence-content">
                    <header>
                        <div>
                            <span class="eyebrow"><?= View::e((string) ($occurrence['source_label'] ?? 'Ocorrência')) ?></span>
                            <h3><?= View::e((string) ($occurrence['title'] ?? 'Falha registrada')) ?></h3>
                        </div>
                        <span class="health-incident-status is-<?= !empty($occurrence['reviewed']) ? 'acknowledged' : 'open' ?>"><?= !empty($occurrence['reviewed']) ? 'Revisada' : 'Não revisada' ?></span>
                    </header>
                    <p><?= View::e((string) ($occurrence['message'] ?? '')) ?></p>
                    <div class="tenant-health-occurrence-meta">
                        <span><?= View::e((string) ($occurrence['created_at_display'] ?? '')) ?></span>
                        <code><?= View::e((string) ($occurrence['event'] ?? '')) ?></code>
                    </div>
                    <div class="tenant-health-occurrence-actions">
                        <?php if (!empty($occurrence['related_url'])): ?><a class="btn btn-small btn-primary" href="<?= View::e(Router::url((string) $occurrence['related_url'])) ?>"><?= ($occurrence['source'] ?? '') === 'ai' ? 'Abrir conversa' : 'Abrir integração' ?></a><?php endif; ?>
                        <?php if (!empty($occurrence['secondary_url'])): ?><a class="btn btn-small btn-outline" href="<?= View::e(Router::url((string) $occurrence['secondary_url'])) ?>">Abrir assistente</a><?php endif; ?>
                        <?php if (!empty($occurrence['details'])): ?>
                            <details>
                                <summary>Ver detalhes técnicos</summary>
                                <dl>
                                    <?php foreach ($occurrence['details'] as $label => $value): ?>
                                        <div><dt><?= View::e((string) $label) ?></dt><dd><?= View::e((string) $value) ?></dd></div>
                                    <?php endforeach; ?>
                                </dl>
                            </details>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$visibleOccurrences): ?>
            <div class="empty-state">
                <?= $occurrenceFilter === 'unreviewed' ? 'Nenhuma falha aguarda revisão.' : 'Nenhuma ocorrência encontrada neste filtro.' ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="card tenant-health-incidents" id="incidents">
    <div class="section-heading">
        <div><span class="eyebrow">Acompanhamento</span><h2>Incidentes e correções</h2><p>O mesmo problema não gera alertas duplicados: ele permanece aberto até ser resolvido.</p></div>
        <span class="badge"><?= (int) ($summary['open'] ?? 0) ?> aberto(s)</span>
    </div>
    <div class="tenant-health-incident-list">
        <?php foreach ($incidents as $incident): ?>
            <article class="tenant-health-incident is-<?= View::e((string) ($incident['severity'] ?? 'warning')) ?> <?= ($incident['status'] ?? '') === 'resolved' ? 'is-resolved' : '' ?>">
                <header>
                    <div><span class="tenant-health-dot is-<?= View::e((string) ($incident['severity'] ?? 'warning')) ?>"></span><div><strong><?= View::e((string) ($incident['title'] ?? 'Incidente')) ?></strong><small><?= View::e((string) ($incident['category'] ?? '')) ?></small></div></div>
                    <span class="health-incident-status is-<?= View::e((string) ($incident['status'] ?? 'open')) ?>"><?= View::e($incidentLabels[(string) ($incident['status'] ?? 'open')] ?? 'Aberto') ?></span>
                </header>
                <p><?= View::e((string) ($incident['summary'] ?? '')) ?></p>
                <div class="tenant-health-incident-meta">
                    <span>Primeira ocorrência: <?= View::e($formatDate((string) ($incident['first_seen_at'] ?? ''))) ?></span>
                    <span>Última ocorrência: <?= View::e($relative((string) ($incident['last_seen_at'] ?? ''))) ?></span>
                    <span>Ocorrências: <?= (int) ($incident['occurrence_count'] ?? 1) ?></span>
                    <?php if (!empty($incident['assigned_user_name'])): ?><span>Responsável: <?= View::e((string) $incident['assigned_user_name']) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($incident['notes'])): ?><div class="tenant-health-note"><strong>Observação:</strong> <?= View::e((string) $incident['notes']) ?></div><?php endif; ?>
                <div class="tenant-health-incident-actions">
                    <?php if (!empty($incident['related_url'])): ?><a class="btn btn-outline" href="<?= View::e(Router::url((string) $incident['related_url'])) ?>">Abrir correção</a><?php endif; ?>
                    <form method="post" action="<?= View::e(Router::url('/companies/health/incident')) ?>">
                        <?= Csrf::input() ?>
                        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                        <input type="hidden" name="incident_id" value="<?= (int) $incident['id'] ?>">
                        <label class="field"><span>Observação interna</span><input name="note" placeholder="O que foi verificado ou corrigido?"></label>
                        <div class="tenant-health-inline-actions">
                            <?php if (($incident['status'] ?? '') !== 'resolved'): ?>
                                <button class="btn btn-quiet" name="incident_action" value="acknowledge" type="submit">Marcar visualizado</button>
                                <button class="btn btn-outline" name="incident_action" value="monitor" type="submit">Acompanhar</button>
                                <button class="btn btn-primary" name="incident_action" value="resolve" type="submit">Marcar resolvido</button>
                            <?php else: ?>
                                <button class="btn btn-outline" name="incident_action" value="reopen" type="submit">Reabrir incidente</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$incidents): ?><div class="empty-state">Nenhum incidente registrado. Execute uma verificação para validar a operação.</div><?php endif; ?>
    </div>
</section>

<section class="card tenant-health-history">
    <div class="section-heading"><div><span class="eyebrow">Histórico</span><h2>Linha do tempo de resolução</h2></div></div>
    <div class="tenant-health-timeline">
        <?php foreach ($events as $event): ?>
            <article>
                <span></span>
                <div><strong><?= View::e($eventLabels[(string) ($event['event_type'] ?? '')] ?? 'Atualização') ?> — <?= View::e((string) ($event['title'] ?? 'Incidente')) ?></strong><p><?= View::e((string) ($event['note'] ?? '')) ?></p><small><?= View::e((string) ($event['user_name'] ?? 'Sistema')) ?></small></div>
                <time><?= View::e($formatDate((string) ($event['created_at'] ?? ''))) ?></time>
            </article>
        <?php endforeach; ?>
        <?php if (!$events): ?><div class="empty-state">Ainda não há movimentações no histórico.</div><?php endif; ?>
    </div>
</section>

<aside class="conversation-details conversation-drawer tenant-health-config-drawer" id="tenant-health-config-drawer" aria-label="Configurações completas da empresa" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header">
        <div>
            <span class="eyebrow">Visão técnica completa</span>
            <h2>Configurações de <?= View::e((string) ($tenant['name'] ?? 'empresa')) ?></h2>
            <p>Consulte o que está configurado em cada módulo sem precisar abrir várias telas.</p>
        </div>
        <button class="icon-button drawer-close" type="button" data-close-panel="tenant-health-config-drawer" aria-label="Fechar painel">×</button>
    </div>
    <div class="conversation-drawer-body tenant-health-config-body">
        <div class="tenant-health-config-notice">
            <div>
                <strong><?= (int) ($configuration['record_count'] ?? 0) ?> configuração(ões) localizada(s)</strong>
                <span>Leitura gerada em <?= View::e($formatDate((string) ($configuration['generated_at'] ?? ''))) ?>.</span>
            </div>
            <p><?= View::e((string) ($configuration['secrets_notice'] ?? 'Chaves, tokens e senhas permanecem protegidos.')) ?></p>
        </div>

        <div class="tenant-health-config-toolbar">
            <label class="field tenant-health-config-search">
                <span>Buscar configuração</span>
                <input type="search" placeholder="Ex.: webhook, horário, modelo, vigência..." data-health-config-search>
            </label>
            <div class="tenant-health-config-toolbar-actions">
                <button class="btn btn-small btn-outline" type="button" data-health-config-expand>Expandir tudo</button>
                <button class="btn btn-small btn-quiet" type="button" data-health-config-collapse>Recolher</button>
                <button class="btn btn-small btn-primary-soft" type="button" data-health-config-copy>Copiar resumo</button>
            </div>
        </div>

        <nav class="tenant-health-config-index" aria-label="Índice das configurações">
            <?php foreach ($configGroups as $group): ?>
                <button type="button" data-health-config-jump="<?= View::e((string) ($group['key'] ?? '')) ?>">
                    <strong><?= View::e((string) ($group['label'] ?? 'Configuração')) ?></strong>
                    <small><?= count($group['records'] ?? []) ?> item(ns)</small>
                </button>
            <?php endforeach; ?>
        </nav>

        <div class="tenant-health-config-groups">
            <?php foreach ($configGroups as $group): ?>
                <?php
                $groupTextParts = [(string) ($group['label'] ?? ''), (string) ($group['description'] ?? '')];
                foreach (($group['records'] ?? []) as $record) {
                    $groupTextParts[] = (string) ($record['title'] ?? '');
                    $groupTextParts[] = (string) ($record['subtitle'] ?? '');
                    foreach (($record['fields'] ?? []) as $label => $value) {
                        $groupTextParts[] = (string) $label . ' ' . (string) $value;
                    }
                }
                $groupSearch = mb_strtolower(implode(' ', $groupTextParts));
                ?>
                <section class="drawer-section tenant-health-config-group" id="health-config-<?= View::e((string) ($group['key'] ?? 'group')) ?>" data-health-config-group data-health-config-search-text="<?= View::e($groupSearch) ?>">
                    <div class="drawer-section-title tenant-health-config-group-title">
                        <div>
                            <span class="eyebrow">Configuração atual</span>
                            <h3><?= View::e((string) ($group['label'] ?? 'Configuração')) ?></h3>
                            <small><?= View::e((string) ($group['description'] ?? '')) ?></small>
                        </div>
                        <?php if (!empty($group['action_url'])): ?>
                            <a class="btn btn-small btn-outline" href="<?= View::e(Router::url((string) $group['action_url'])) ?>">Editar módulo</a>
                        <?php endif; ?>
                    </div>

                    <div class="tenant-health-config-records">
                        <?php foreach (($group['records'] ?? []) as $record): ?>
                            <?php $tone = (string) ($record['tone'] ?? 'info'); ?>
                            <details class="tenant-health-config-record">
                                <summary>
                                    <span>
                                        <strong><?= View::e((string) ($record['title'] ?? 'Registro')) ?></strong>
                                        <?php if (!empty($record['subtitle'])): ?><small><?= View::e((string) $record['subtitle']) ?></small><?php endif; ?>
                                    </span>
                                    <em class="health-check-label is-<?= View::e($tone) ?>"><?= View::e($configToneLabels[$tone] ?? 'Informação') ?></em>
                                </summary>
                                <dl class="tenant-health-config-fields">
                                    <?php foreach (($record['fields'] ?? []) as $label => $value): ?>
                                        <div>
                                            <dt><?= View::e((string) $label) ?></dt>
                                            <dd><?= nl2br(View::e((string) $value)) ?></dd>
                                        </div>
                                    <?php endforeach; ?>
                                </dl>
                                <?php if (!empty($record['long_fields'])): ?>
                                    <div class="tenant-health-config-long-fields">
                                        <?php foreach ($record['long_fields'] as $label => $value): ?>
                                            <details>
                                                <summary><?= View::e((string) $label) ?></summary>
                                                <pre><?= View::e((string) $value) ?></pre>
                                            </details>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
            <?php if (!$configGroups): ?><div class="empty-state">Não foi possível carregar as configurações desta empresa.</div><?php endif; ?>
            <div class="empty-state" data-health-config-empty hidden>Nenhuma configuração corresponde à busca.</div>
        </div>
    </div>
</aside>

