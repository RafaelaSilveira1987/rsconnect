<?php

use App\Core\Router;
use App\Core\View;

$money = static fn (float|int|string $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$number = static fn (float|int|string $value): string => number_format((float) $value, 0, ',', '.');
$percent = static fn (float|int|string $value): string => number_format((float) $value, 1, ',', '.') . '%';
$duration = static function (int $seconds): string {
    if ($seconds <= 0) return 'Sem dados';
    if ($seconds < 60) return $seconds . ' seg';
    if ($seconds < 3600) return floor($seconds / 60) . ' min ' . ($seconds % 60) . ' seg';
    return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'min';
};
$maxValue = static function (array $rows, string $key = 'total'): int {
    $values = array_map(static fn (array $row): int => (int) ($row[$key] ?? 0), $rows);
    return max(1, ...($values ?: [1]));
};
$statusLabels = [
    'scheduled' => 'Agendado', 'confirmed' => 'Confirmado', 'completed' => 'Concluído',
    'cancelled' => 'Cancelado', 'no_show' => 'Não compareceu',
];
$requestedSection = (string) ($_GET['section'] ?? 'overview');
$activeSection = in_array($requestedSection, ['overview', 'service', 'team', 'results'], true)
    ? $requestedSection : 'overview';
$queryBase = array_filter([
    'start' => $filters['start'] ?? '',
    'end' => $filters['end'] ?? '',
], static fn ($value) => $value !== '');
$dayMax = $maxValue($byDay);
$hourMax = $maxValue($byHour);
$teamMax = $maxValue($teamPerformance);
$crmMax = $maxValue($crmByStage);
$agendaMax = $maxValue($agendaByStatus);
?>
<link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/reports.css?v=34.1')) ?>">
<div class="executive-report-page client-manager-report">
    <section class="client-report-hero">
        <div>
            <span class="eyebrow">Relatório gerencial</span>
            <h2>Resultados do atendimento</h2>
            <p>Entenda o volume de conversas, a atuação da equipe e da IA, oportunidades do CRM e resultados da agenda no período escolhido.</p>
        </div>
        <div class="client-report-hero-actions">
            <a class="btn btn-primary" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'conversations']))) ?>">Exportar conversas</a>
            <button class="btn btn-outline" type="button" onclick="window.print()">Imprimir relatório</button>
        </div>
    </section>

    <form class="card client-report-filters" method="get" action="<?= View::e(Router::url('/reports')) ?>">
        <label class="field"><span>Data inicial</span><input type="date" name="start" value="<?= View::e($filters['start']) ?>"></label>
        <label class="field"><span>Data final</span><input type="date" name="end" value="<?= View::e($filters['end']) ?>"></label>
        <input type="hidden" name="section" value="<?= View::e($activeSection) ?>">
        <div class="client-report-filter-actions"><button class="btn btn-primary" type="submit">Atualizar período</button><a class="btn btn-quiet" href="<?= View::e(Router::url('/reports')) ?>">Limpar</a></div>
    </form>

    <section class="client-report-score-grid" aria-label="Principais resultados">
        <article class="client-report-score is-primary"><span>Conversas iniciadas</span><strong><?= $number($metrics['conversations'] ?? 0) ?></strong><small><?= $number($metrics['closed_conversations'] ?? 0) ?> encerrada(s) no período</small></article>
        <article class="client-report-score"><span>Mensagens recebidas</span><strong><?= $number($metrics['incoming_messages'] ?? 0) ?></strong><small><?= $number($metrics['unread'] ?? 0) ?> pendente(s) de leitura agora</small></article>
        <article class="client-report-score"><span>Tempo médio de 1ª resposta</span><strong><?= View::e($duration((int) ($metrics['avg_first_response_seconds'] ?? 0))) ?></strong><small>entre a primeira mensagem e a primeira resposta</small></article>
        <article class="client-report-score"><span>Atendimentos pela IA</span><strong><?= $percent($metrics['ai_share'] ?? 0) ?></strong><small><?= $number($metrics['ai_replies'] ?? 0) ?> resposta(s) automática(s)</small></article>
        <article class="client-report-score"><span>Conversão do CRM</span><strong><?= $percent($metrics['crm_conversion'] ?? 0) ?></strong><small><?= $number($metrics['crm_won'] ?? 0) ?> de <?= $number($metrics['crm_leads'] ?? 0) ?> oportunidade(s)</small></article>
        <article class="client-report-score"><span>Conversão da agenda</span><strong><?= $percent($metrics['agenda_conversion'] ?? 0) ?></strong><small><?= $number($metrics['appointments_confirmed'] ?? 0) ?> confirmado(s) ou concluído(s)</small></article>
    </section>

    <section class="card client-report-tabs-card">
        <div class="settings-tabs client-report-tabs" data-tabs>
            <button class="<?= $activeSection === 'overview' ? 'is-active' : '' ?>" type="button" data-tab-target="overview">Visão geral</button>
            <button class="<?= $activeSection === 'service' ? 'is-active' : '' ?>" type="button" data-tab-target="service">Atendimento</button>
            <button class="<?= $activeSection === 'team' ? 'is-active' : '' ?>" type="button" data-tab-target="team">IA e equipe</button>
            <button class="<?= $activeSection === 'results' ? 'is-active' : '' ?>" type="button" data-tab-target="results">CRM e agenda</button>
        </div>

        <div class="client-report-panel" data-tab-panel="overview" <?= $activeSection !== 'overview' ? 'hidden' : '' ?>>
            <div class="client-report-two-columns">
                <section>
                    <div class="section-heading"><div><span class="eyebrow">Movimento diário</span><h2>Mensagens por dia</h2></div><span class="badge"><?= View::e(date('d/m/Y', strtotime($filters['start']))) ?> a <?= View::e(date('d/m/Y', strtotime($filters['end']))) ?></span></div>
                    <div class="client-report-days">
                        <?php foreach ($byDay as $row): $pct = min(100, ((int) $row['total'] / $dayMax) * 100); ?>
                            <article><time><?= View::e(date('d/m', strtotime($row['label']))) ?></time><span class="client-report-track"><i style="width:<?= (float) $pct ?>%"></i></span><strong><?= (int) $row['total'] ?></strong><small><?= (int) $row['incoming'] ?> recebidas · <?= (int) $row['outgoing'] ?> enviadas · <?= (int) $row['ai'] ?> pela IA</small></article>
                        <?php endforeach; ?>
                        <?php if (!$byDay): ?><div class="empty-state">Nenhuma mensagem no período selecionado.</div><?php endif; ?>
                    </div>
                </section>
                <aside class="client-report-summary-card">
                    <span class="eyebrow">Resumo do período</span><h3>Leitura rápida da operação</h3>
                    <dl>
                        <div><dt>Total de mensagens</dt><dd><?= $number($metrics['total_messages'] ?? 0) ?></dd></div>
                        <div><dt>Média por conversa</dt><dd><?= number_format((float) ($metrics['avg_messages_per_conversation'] ?? 0), 1, ',', '.') ?></dd></div>
                        <div><dt>Novos contatos</dt><dd><?= $number($metrics['contacts'] ?? 0) ?></dd></div>
                        <div><dt>Mensagens com falha</dt><dd><?= $number($metrics['failed_messages'] ?? 0) ?></dd></div>
                    </dl>
                    <a class="btn btn-outline btn-block" href="<?= View::e(Router::url('/conversations')) ?>">Abrir conversas</a>
                </aside>
            </div>
            <div class="client-report-priority-grid">
                <section><div class="section-heading"><div><span class="eyebrow">Atenção</span><h2>Conversas prioritárias</h2></div></div><div class="client-report-list"><?php foreach ($attention as $item): ?><a href="<?= View::e(Router::url('/conversations?conversation_id=' . (int) $item['id'])) ?>"><span><strong><?= View::e($item['contact_name'] ?: $item['phone']) ?></strong><small><?= View::e($item['attendance_mode']) ?> · <?= View::e($item['last_message_at'] ?? '') ?></small></span><b><?= (int) $item['unread_count'] ?></b></a><?php endforeach; ?><?php if (!$attention): ?><div class="empty-state">Nenhuma conversa pendente neste momento.</div><?php endif; ?></div></section>
                <section><div class="section-heading"><div><span class="eyebrow">Relacionamento</span><h2>Contatos com mais interações</h2></div></div><div class="client-report-list"><?php foreach ($topContacts as $item): ?><a href="<?= View::e(Router::url('/contacts')) ?>"><span><strong><?= View::e($item['label']) ?></strong><small><?= View::e($item['phone']) ?> · última interação <?= View::e(date('d/m H:i', strtotime($item['last_message_at']))) ?></small></span><b><?= (int) $item['total'] ?></b></a><?php endforeach; ?><?php if (!$topContacts): ?><div class="empty-state">Nenhum contato com mensagens no período.</div><?php endif; ?></div></section>
            </div>
        </div>

        <div class="client-report-panel" data-tab-panel="service" <?= $activeSection !== 'service' ? 'hidden' : '' ?>>
            <div class="client-report-two-columns">
                <section><div class="section-heading"><div><span class="eyebrow">Demanda</span><h2>Horários com mais mensagens recebidas</h2></div></div><div class="client-hour-chart"><?php foreach ($byHour as $row): $pct=min(100,((int)$row['total']/$hourMax)*100); ?><article title="<?= (int)$row['total'] ?> mensagem(ns)"><span style="height:<?= max(6,(float)$pct) ?>%"></span><strong><?= str_pad((string)$row['label'],2,'0',STR_PAD_LEFT) ?>h</strong><small><?= (int)$row['total'] ?></small></article><?php endforeach; ?><?php if (!$byHour): ?><div class="empty-state">Sem mensagens recebidas no período.</div><?php endif; ?></div></section>
                <aside class="client-report-summary-card"><span class="eyebrow">Eficiência</span><h3>Indicadores de atendimento</h3><dl><div><dt>Respostas enviadas</dt><dd><?= $number($metrics['outgoing_messages'] ?? 0) ?></dd></div><div><dt>Respostas humanas</dt><dd><?= $number($metrics['human_replies'] ?? 0) ?></dd></div><div><dt>Participação humana</dt><dd><?= $percent($metrics['human_share'] ?? 0) ?></dd></div><div><dt>Conversas abertas agora</dt><dd><?= $number($metrics['open_conversations'] ?? 0) ?></dd></div></dl></aside>
            </div>
            <div class="client-report-insight-banner"><strong>Como usar este relatório:</strong><span>concentre a equipe nos horários de maior demanda, revise conversas ainda abertas e acompanhe se o tempo de primeira resposta está melhorando.</span></div>
        </div>

        <div class="client-report-panel" data-tab-panel="team" <?= $activeSection !== 'team' ? 'hidden' : '' ?>>
            <div class="client-report-two-columns">
                <section><div class="section-heading"><div><span class="eyebrow">Equipe</span><h2>Respostas humanas por responsável</h2></div></div><div class="executive-bars client-team-bars"><?php foreach ($teamPerformance as $row): ?><div><strong><?= View::e($row['label']) ?></strong><span><i style="width:<?= min(100,((int)$row['total']/$teamMax)*100) ?>%"></i></span><b><?= (int)$row['total'] ?></b><small><?= (int)$row['conversations'] ?> conversa(s)</small></div><?php endforeach; ?><?php if (!$teamPerformance): ?><div class="empty-state">Nenhuma resposta humana registrada no período.</div><?php endif; ?></div></section>
                <aside class="client-report-summary-card"><span class="eyebrow">Assistente virtual</span><h3>Desempenho da IA</h3><dl><div><dt>Respostas automáticas</dt><dd><?= $number($metrics['ai_replies'] ?? 0) ?></dd></div><div><dt>Participação nas respostas</dt><dd><?= $percent($metrics['ai_share'] ?? 0) ?></dd></div><div><dt>Execuções bem-sucedidas</dt><dd><?= $number($metrics['ai_success'] ?? 0) ?></dd></div><div><dt>Falhas registradas</dt><dd><?= $number($metrics['ai_errors'] ?? 0) ?></dd></div></dl><a class="btn btn-outline btn-block" href="<?= View::e(Router::url('/automations')) ?>">Ver respostas e integrações</a></aside>
            </div>
        </div>

        <div class="client-report-panel" data-tab-panel="results" <?= $activeSection !== 'results' ? 'hidden' : '' ?>>
            <div class="client-report-two-columns">
                <section><div class="section-heading"><div><span class="eyebrow">Comercial</span><h2>Oportunidades por etapa</h2></div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'leads']))) ?>">Exportar CRM</a></div><div class="executive-bars"><?php foreach ($crmByStage as $row): ?><div><strong><?= View::e($row['label']) ?></strong><span><i style="width:<?= min(100,((int)$row['total']/$crmMax)*100) ?>%"></i></span><b><?= (int)$row['total'] ?></b><small><?= $money($row['value']) ?></small></div><?php endforeach; ?><?php if (!$crmByStage): ?><div class="empty-state">Nenhuma oportunidade cadastrada.</div><?php endif; ?></div></section>
                <aside class="client-report-summary-card"><span class="eyebrow">CRM</span><h3>Resultado comercial</h3><dl><div><dt>Oportunidades criadas</dt><dd><?= $number($metrics['crm_leads'] ?? 0) ?></dd></div><div><dt>Oportunidades ganhas</dt><dd><?= $number($metrics['crm_won'] ?? 0) ?></dd></div><div><dt>Taxa de conversão</dt><dd><?= $percent($metrics['crm_conversion'] ?? 0) ?></dd></div></dl><a class="btn btn-primary btn-block" href="<?= View::e(Router::url('/crm')) ?>">Abrir CRM</a></aside>
            </div>
            <div class="client-report-two-columns client-report-agenda-row">
                <section><div class="section-heading"><div><span class="eyebrow">Agenda</span><h2>Compromissos por situação</h2></div></div><div class="executive-bars"><?php foreach ($agendaByStatus as $row): ?><div><strong><?= View::e($statusLabels[$row['label']] ?? $row['label']) ?></strong><span><i style="width:<?= min(100,((int)$row['total']/$agendaMax)*100) ?>%"></i></span><b><?= (int)$row['total'] ?></b></div><?php endforeach; ?><?php if (!$agendaByStatus): ?><div class="empty-state">Nenhum compromisso no período.</div><?php endif; ?></div></section>
                <aside class="client-report-summary-card"><span class="eyebrow">Conversão</span><h3>Resultado da agenda</h3><dl><div><dt>Total de compromissos</dt><dd><?= $number($metrics['appointments'] ?? 0) ?></dd></div><div><dt>Confirmados/concluídos</dt><dd><?= $number($metrics['appointments_confirmed'] ?? 0) ?></dd></div><div><dt>Cancelados/não compareceu</dt><dd><?= $number($metrics['appointments_cancelled'] ?? 0) ?></dd></div><div><dt>Conversão</dt><dd><?= $percent($metrics['agenda_conversion'] ?? 0) ?></dd></div></dl><a class="btn btn-outline btn-block" href="<?= View::e(Router::url('/calendar')) ?>">Abrir agenda</a></aside>
            </div>
        </div>
    </section>
<script src="<?= View::e(Router::url('/assets/js/reports.js?v=34.1')) ?>" defer></script>
</div>
