<?php

use App\Core\Auth;
use App\Core\PublicId;
use App\Core\Router;
use App\Core\View;

$overview = $overview ?? [];
$professionals = $professionals ?? [];
$dailySeries = $dailySeries ?? [];
$recentActivities = $recentActivities ?? [];
$warnings = $warnings ?? [];
$users = $users ?? [];
$tenants = $tenants ?? [];
$scope = $scope ?? ['mode' => 'pending'];
$readiness = $readiness ?? ['ready' => false, 'missing' => []];
$tenant = $tenant ?? [];
$selectedUserId = (int) ($selected_user_id ?? 0);
$tenantId = (int) ($filters['tenant_id'] ?? 0);

$number = static fn (float|int|string $value): string => number_format((float) $value, 0, ',', '.');
$percent = static fn (float|int|string $value): string => number_format((float) $value, 1, ',', '.') . '%';
$duration = static function (float|int|string $seconds): string {
    $seconds = max(0, (int) round((float) $seconds));
    if ($seconds === 0) return 'Sem dados';
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return intdiv($seconds, 60) . 'min ' . ($seconds % 60) . 's';
    return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'min';
};
$statusLabel = static fn (string $status): string => $status === 'active' ? 'Ativo' : 'Inativo';
$activityTone = static function (string $type, string $action): string {
    if ($action === 'no_show' || $action === 'cancelled') return 'warning';
    if ($action === 'completed' || $action === 'confirmed') return 'success';
    if ($action === 'transfer' || $action === 'owner_changed') return 'info';
    return $type === 'appointment' ? 'neutral' : 'primary';
};

$queryBase = array_filter([
    'start' => $filters['start'] ?? '',
    'end' => $filters['end'] ?? '',
    'tenant_id' => $tenantId,
    'user_id' => $selectedUserId,
], static fn ($value): bool => $value !== '' && $value !== 0);

$dailyMax = 1;
foreach ($dailySeries as $day) {
    $dailyMax = max($dailyMax, (int) ($day['human_messages'] ?? 0), (int) ($day['appointments'] ?? 0));
}
$professionalMax = 1;
foreach ($professionals as $professional) {
    $professionalMax = max($professionalMax, (int) ($professional['activity_score'] ?? 0));
}
?>
<link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/reports.css?v=36.10.0')) ?>">
<div class="executive-report-page team-report-page report-v36100">
    <section class="client-report-hero team-report-hero">
        <div>
            <span class="eyebrow">Equipe e profissionais</span>
            <h2>Desempenho de atendimento e agenda</h2>
            <p>Compare responsabilidade nas conversas, velocidade da primeira resposta, transferências, carteira preferencial e resultados dos agendamentos sem misturar os papéis da equipe.</p>
        </div>
        <div class="client-report-hero-actions">
            <a class="btn btn-outline" href="<?= View::e(Router::url('/reports?' . http_build_query(array_filter([
                'start' => $filters['start'] ?? '',
                'end' => $filters['end'] ?? '',
                'tenant_id' => $tenantId,
            ], static fn ($value): bool => $value !== '' && $value !== 0)))) ?>">Visão geral</a>
            <?php if ($tenantId > 0 && !empty($readiness['ready'])): ?>
                <a class="btn btn-primary" href="<?= View::e(Router::url('/reports/team/export?' . http_build_query($queryBase))) ?>">Exportar equipe</a>
            <?php endif; ?>
            <button class="btn btn-outline" type="button" onclick="window.print()">Imprimir</button>
        </div>
    </section>

    <?php if ($warnings): ?>
        <div class="flash warning executive-report-warning"><strong>Relatório parcialmente disponível.</strong><span><?= View::e(implode(' · ', array_unique($warnings))) ?></span></div>
    <?php endif; ?>

    <form class="card team-report-filters" method="get" action="<?= View::e(Router::url('/reports/team')) ?>">
        <?php if (Auth::isSuperAdmin()): ?>
            <label class="field team-report-company"><span>Empresa</span><select name="tenant_uuid" required><option value="">Selecione uma empresa</option><?php foreach ($tenants as $item): $itemId = (int) ($item['id'] ?? 0); ?><option value="<?= View::e(PublicId::encode('tenant', $itemId)) ?>" <?= $tenantId === $itemId ? 'selected' : '' ?>><?= View::e((string) ($item['name'] ?? 'Empresa')) ?><?= ($item['status'] ?? '') !== 'active' ? ' · inativa' : '' ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <label class="field"><span>Data inicial</span><input type="date" name="start" value="<?= View::e((string) ($filters['start'] ?? '')) ?>"></label>
        <label class="field"><span>Data final</span><input type="date" name="end" value="<?= View::e((string) ($filters['end'] ?? '')) ?>"></label>
        <?php if ($tenantId > 0): ?>
            <label class="field"><span>Profissional</span><select name="user_uuid" <?= ($scope['mode'] ?? '') === 'own' ? 'disabled' : '' ?>><option value="">Toda a equipe</option><?php foreach ($users as $user): $userId = (int) ($user['id'] ?? 0); ?><option value="<?= View::e(PublicId::encode('user', $userId)) ?>" <?= $selectedUserId === $userId ? 'selected' : '' ?>><?= View::e((string) (($user['whatsapp_display_name'] ?? '') ?: ($user['name'] ?? 'Usuário'))) ?><?= ($user['status'] ?? '') !== 'active' ? ' · inativo' : '' ?></option><?php endforeach; ?></select><?php if (($scope['mode'] ?? '') === 'own'): ?><input type="hidden" name="user_uuid" value="<?= View::e(PublicId::encode('user', $selectedUserId)) ?>"><?php endif; ?></label>
        <?php endif; ?>
        <div class="team-report-filter-actions"><button class="btn btn-primary" type="submit">Atualizar relatório</button><a class="btn btn-quiet" href="<?= View::e(Router::url('/reports/team' . (Auth::isSuperAdmin() && $tenantId > 0 ? '?tenant_id=' . $tenantId : ''))) ?>">Limpar filtros</a></div>
    </form>

    <?php if ($tenantId < 1): ?>
        <section class="card team-report-empty-selection">
            <span class="eyebrow">Empresa obrigatória</span>
            <h2>Selecione a empresa que deseja analisar</h2>
            <p>O relatório de profissionais respeita o isolamento entre empresas e só consulta uma operação por vez.</p>
        </section>
    <?php elseif (empty($readiness['ready'])): ?>
        <section class="card team-report-empty-selection is-warning">
            <span class="eyebrow">Base histórica pendente</span>
            <h2>Execute as migrations 067 e 068</h2>
            <p>Sem as tabelas de histórico e os ciclos persistentes, o sistema não consegue atribuir primeiras respostas, transferências, encerramentos e mudanças da agenda ao profissional correto.</p>
            <?php if (!empty($readiness['missing'])): ?><small>Pendente: <?= View::e(implode(', ', $readiness['missing'])) ?></small><?php endif; ?>
        </section>
    <?php else: ?>
        <section class="team-report-context card">
            <div><span class="eyebrow">Escopo</span><strong><?= View::e((string) ($overview['scope_label'] ?? 'Toda a equipe')) ?></strong><small><?= View::e((string) ($tenant['name'] ?? 'Empresa')) ?> · <?= View::e(date('d/m/Y', strtotime((string) $filters['start']))) ?> a <?= View::e(date('d/m/Y', strtotime((string) $filters['end']))) ?></small></div>
            <div class="team-report-context-badges"><span class="badge <?= !empty($tenant['professional_assignment_enabled']) ? 'badge-success' : 'badge-info' ?>">Atendimento por profissional <?= !empty($tenant['professional_assignment_enabled']) ? 'ativo' : 'opcional' ?></span><span class="badge <?= !empty($tenant['professional_calendar_enabled']) ? 'badge-success' : 'badge-info' ?>">Agenda individual <?= !empty($tenant['professional_calendar_enabled']) ? 'ativa' : 'opcional' ?></span></div>
        </section>

        <section class="team-report-kpis" aria-label="Indicadores da equipe">
            <article class="card"><span>Profissionais analisados</span><strong><?= $number($overview['team_members'] ?? 0) ?></strong><small><?= $number($overview['preferred_clients'] ?? 0) ?> clientes preferenciais</small></article>
            <article class="card is-primary"><span>Conversas respondidas</span><strong><?= $number($overview['conversations_replied'] ?? 0) ?></strong><small><?= $number($overview['human_messages'] ?? 0) ?> mensagens humanas</small></article>
            <article class="card"><span>Tempo médio da 1ª resposta</span><strong><?= View::e($duration($overview['avg_first_response_seconds'] ?? 0)) ?></strong><small><?= $number($overview['first_responses'] ?? 0) ?> primeiras respostas medidas</small></article>
            <article class="card"><span>Conversas encerradas</span><strong><?= $number($overview['closed_conversations'] ?? 0) ?></strong><small><?= $number($overview['open_conversations'] ?? 0) ?> abertas agora</small></article>
            <article class="card"><span>Transferências</span><strong><?= $number(($overview['transfers_received'] ?? 0) + ($overview['transfers_out'] ?? 0)) ?></strong><small><?= $number($overview['releases'] ?? 0) ?> liberações</small></article>
            <article class="card"><span>Agendamentos</span><strong><?= $number($overview['appointments'] ?? 0) ?></strong><small><?= $number($overview['appointments_upcoming'] ?? 0) ?> futuros no período</small></article>
            <article class="card is-success"><span>Resultado da agenda</span><strong><?= $percent($overview['appointment_success_rate'] ?? 0) ?></strong><small>Confirmados e concluídos</small></article>
            <article class="card <?= (int) ($overview['appointments_no_show'] ?? 0) > 0 ? 'is-warning' : '' ?>"><span>Comparecimento</span><strong><?= $percent($overview['attendance_rate'] ?? 0) ?></strong><small><?= $number($overview['appointments_no_show'] ?? 0) ?> falta(s) · <?= $number($overview['appointments_cancelled'] ?? 0) ?> cancelado(s)</small></article>
        </section>

        <section class="card team-report-section">
            <div class="section-heading"><div><span class="eyebrow">Evolução diária</span><h2>Atendimento humano e agenda</h2><p>Volume por dia dentro do período filtrado.</p></div></div>
            <div class="team-report-daily-chart" role="img" aria-label="Mensagens humanas e agendamentos por dia">
                <?php foreach ($dailySeries as $day): ?>
                    <article title="<?= View::e(($day['label'] ?? '') . ': ' . ($day['human_messages'] ?? 0) . ' mensagem(ns), ' . ($day['appointments'] ?? 0) . ' agendamento(s)') ?>">
                        <div class="team-report-day-bars"><i class="is-message" style="height:<?= max(3, ((int) ($day['human_messages'] ?? 0) / $dailyMax) * 100) ?>%"></i><i class="is-calendar" style="height:<?= max(3, ((int) ($day['appointments'] ?? 0) / $dailyMax) * 100) ?>%"></i></div>
                        <strong><?= View::e((string) ($day['label'] ?? '')) ?></strong>
                        <small><?= $number($day['human_messages'] ?? 0) ?> · <?= $number($day['appointments'] ?? 0) ?></small>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="team-report-legend"><span><i class="is-message"></i>Mensagens humanas</span><span><i class="is-calendar"></i>Agendamentos</span></div>
        </section>

        <section class="card team-report-section">
            <div class="section-heading"><div><span class="eyebrow">Comparativo</span><h2>Desempenho por profissional</h2><p>Os indicadores respeitam quem respondeu, quem encerrou e quem executará o agendamento.</p></div></div>
            <div class="table-wrap team-report-table-wrap"><table class="team-report-table"><thead><tr><th>Profissional</th><th>Conversas</th><th>1ª resposta</th><th>Encerradas</th><th>Transferências</th><th>Clientes</th><th>Agenda</th><th>Resultado</th><th>Faltas</th><th></th></tr></thead><tbody>
                <?php foreach ($professionals as $professional): $userId = (int) ($professional['user_id'] ?? 0); ?>
                    <tr>
                        <td><div class="team-report-person"><span><?= View::e(mb_strtoupper(mb_substr((string) ($professional['name'] ?? 'U'), 0, 2))) ?></span><div><strong><?= View::e((string) ($professional['name'] ?? 'Usuário')) ?></strong><small><?= View::e((string) ($professional['role_label'] ?? 'Profissional')) ?> · <?= View::e($statusLabel((string) ($professional['status'] ?? 'active'))) ?></small></div></div></td>
                        <td><strong><?= $number($professional['conversations_replied'] ?? 0) ?></strong><small><?= $number($professional['human_messages'] ?? 0) ?> mensagens</small></td>
                        <td><strong><?= View::e($duration($professional['avg_first_response_seconds'] ?? 0)) ?></strong><small><?= $number($professional['first_responses'] ?? 0) ?> medida(s)</small></td>
                        <td><strong><?= $number($professional['closed_conversations'] ?? 0) ?></strong><small><?= $number($professional['open_conversations'] ?? 0) ?> abertas</small></td>
                        <td><strong><?= $number(($professional['transfers_received'] ?? 0) + ($professional['transfers_out'] ?? 0)) ?></strong><small><?= $number($professional['transfers_received'] ?? 0) ?> recebidas</small></td>
                        <td><strong><?= $number($professional['preferred_clients'] ?? 0) ?></strong><small>preferenciais</small></td>
                        <td><strong><?= $number($professional['appointments'] ?? 0) ?></strong><small><?= $number($professional['appointments_completed'] ?? 0) ?> concluídos</small></td>
                        <td><strong><?= $percent($professional['appointment_success_rate'] ?? 0) ?></strong><small><?= $percent($professional['attendance_rate'] ?? 0) ?> comparecimento</small></td>
                        <td><strong><?= $number($professional['appointments_no_show'] ?? 0) ?></strong><small><?= $number($professional['appointments_cancelled'] ?? 0) ?> cancelados</small></td>
                        <td><?php if (($scope['mode'] ?? '') !== 'own'): ?><a class="table-link" href="<?= View::e(Router::url('/reports/team?' . http_build_query(array_filter([
                            'start' => $filters['start'] ?? '',
                            'end' => $filters['end'] ?? '',
                            'tenant_id' => $tenantId,
                            'user_id' => $userId,
                        ], static fn ($value): bool => $value !== '' && $value !== 0)))) ?>">Detalhar</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$professionals): ?><tr><td colspan="10"><div class="empty-state">Nenhum profissional com dados no período selecionado.</div></td></tr><?php endif; ?>
            </tbody></table></div>
        </section>

        <div class="team-report-two-columns">
            <section class="card team-report-section">
                <div class="section-heading"><div><span class="eyebrow">Leitura rápida</span><h2>Carga operacional</h2></div></div>
                <div class="team-report-ranking">
                    <?php foreach ($professionals as $index => $professional): $score = (int) ($professional['activity_score'] ?? 0); ?>
                        <article><b><?= $index + 1 ?></b><div><strong><?= View::e((string) ($professional['name'] ?? 'Usuário')) ?></strong><span><i style="width:<?= min(100, ($score / $professionalMax) * 100) ?>%"></i></span><small><?= $number($professional['human_messages'] ?? 0) ?> mensagens · <?= $number($professional['appointments_completed'] ?? 0) ?> atendimentos concluídos</small></div></article>
                    <?php endforeach; ?>
                    <?php if (!$professionals): ?><div class="empty-state">Sem atividade humana registrada.</div><?php endif; ?>
                </div>
            </section>

            <section class="card team-report-section">
                <div class="section-heading"><div><span class="eyebrow">Histórico</span><h2>Movimentações recentes</h2></div></div>
                <div class="team-report-timeline">
                    <?php foreach ($recentActivities as $activity): $tone = $activityTone((string) ($activity['activity_type'] ?? ''), (string) ($activity['action'] ?? '')); ?>
                        <article class="is-<?= View::e($tone) ?>"><i></i><div><strong><?= View::e((string) ($activity['description'] ?? 'Movimentação registrada.')) ?></strong><small><?= View::e(date('d/m/Y H:i', strtotime((string) ($activity['occurred_at'] ?? 'now')))) ?></small></div></article>
                    <?php endforeach; ?>
                    <?php if (!$recentActivities): ?><div class="empty-state">Nenhuma atribuição ou mudança da agenda no período.</div><?php endif; ?>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>
