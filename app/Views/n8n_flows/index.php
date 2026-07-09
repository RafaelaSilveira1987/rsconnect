<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$formatDate = static function (?string $date): string {
    if (!$date) {
        return '—';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date('d/m/Y H:i:s', $timestamp) : $date;
};
$statusLabel = ['active' => 'Ativo', 'inactive' => 'Inativo', 'success' => 'Sucesso', 'error' => 'Erro', 'skipped' => 'Ignorado'];
?>

<div class="content-grid management-layout n8n-flow-page">
    <section class="card">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Integrações por empresa</span>
                <h2>Fluxos n8n</h2>
            </div>
            <span class="badge"><?= count($flows) ?> fluxo(s)</span>
        </div>
        <p class="muted">Cadastre um webhook n8n separado para cada empresa. Assim cada cliente pode ter automações próprias sem depender de uma URL global no <code>.env</code>.</p>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr><th>Empresa</th><th>Fluxo</th><th>Eventos</th><th>Webhook</th><th>Status</th><th>Último resultado</th><th>Ações</th></tr>
                </thead>
                <tbody>
                <?php foreach ($flows as $flow): ?>
                    <tr>
                        <td><strong><?= View::e($flow['tenant_name']) ?></strong></td>
                        <td><strong><?= View::e($flow['name']) ?></strong><br><small><?= View::e($flow['flow_key']) ?></small><?php if (!empty($flow['description'])): ?><br><small><?= View::e($flow['description']) ?></small><?php endif; ?></td>
                        <td><?= View::e($flow['events_label']) ?></td>
                        <td><small><?= View::e($flow['webhook_url_masked']) ?></small><br><small>Token: <?= View::e($flow['secret_masked']) ?></small></td>
                        <td><span class="badge badge-<?= View::e($flow['status']) ?>"><?= View::e($statusLabel[$flow['status']] ?? $flow['status']) ?></span></td>
                        <td><small>Sucesso: <?= View::e($formatDate($flow['last_success_at'] ?? null)) ?></small><br><small>Erro: <?= View::e($flow['last_error'] ?? '—') ?></small></td>
                        <td class="actions-cell">
                            <form method="post" action="<?= View::e(Router::url('/n8n-flows/test')) ?>">
                                <?= Csrf::input() ?>
                                <input type="hidden" name="flow_id" value="<?= (int) $flow['id'] ?>">
                                <button class="btn btn-small btn-outline" type="submit">Testar</button>
                            </form>
                            <details>
                                <summary class="btn btn-small btn-quiet">Editar</summary>
                                <form class="stack compact-form" method="post" action="<?= View::e(Router::url('/n8n-flows/save')) ?>">
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="id" value="<?= (int) $flow['id'] ?>">
                                    <label class="field compact-field"><span>Empresa</span><select name="tenant_id" required><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>" <?= (int) $tenant['id'] === (int) $flow['tenant_id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select></label>
                                    <div class="form-grid two"><label class="field compact-field"><span>Identificador</span><input name="flow_key" value="<?= View::e($flow['flow_key']) ?>" required></label><label class="field compact-field"><span>Status</span><select name="status"><option value="active" <?= $flow['status'] === 'active' ? 'selected' : '' ?>>Ativo</option><option value="inactive" <?= $flow['status'] === 'inactive' ? 'selected' : '' ?>>Inativo</option></select></label></div>
                                    <label class="field compact-field"><span>Nome</span><input name="name" value="<?= View::e($flow['name']) ?>" required></label>
                                    <label class="field compact-field"><span>Descrição</span><input name="description" value="<?= View::e($flow['description'] ?? '') ?>"></label>
                                    <label class="field compact-field"><span>Nova URL do webhook</span><input name="webhook_url" placeholder="Deixe em branco para manter a atual"></label>
                                    <label class="field compact-field"><span>Novo token secreto</span><input name="secret_token" type="password" placeholder="Opcional; em branco mantém o atual"></label>
                                    <fieldset class="field checkbox-fieldset"><legend>Eventos</legend><?php $currentEvents = json_decode((string) ($flow['events_json'] ?? '[]'), true); $currentEvents = is_array($currentEvents) ? $currentEvents : []; foreach ($eventOptions as $eventKey => $eventLabel): ?><label class="check-field compact-check"><input type="checkbox" name="events[]" value="<?= View::e($eventKey) ?>" <?= in_array($eventKey, $currentEvents, true) ? 'checked' : '' ?>><span><?= View::e($eventLabel) ?></span></label><?php endforeach; ?></fieldset>
                                    <button class="btn btn-primary btn-block" type="submit">Salvar alterações</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$flows): ?><tr><td colspan="7"><div class="empty-state">Nenhum fluxo n8n cadastrado ainda.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="stack">
        <form class="card sticky-card" method="post" action="<?= View::e(Router::url('/n8n-flows/save')) ?>">
            <?= Csrf::input() ?>
            <div class="section-heading"><div><span class="eyebrow">Novo fluxo</span><h2>Webhook do cliente</h2></div></div>
            <label class="field"><span>Empresa</span><select name="tenant_id" required><option value="">Selecione</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>"><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select></label>
            <div class="form-grid two"><label class="field"><span>Identificador</span><input name="flow_key" placeholder="agenda-google" required><small class="field-hint">Ex.: agenda-google, crm-leads, pos-venda.</small></label><label class="field"><span>Status</span><select name="status"><option value="active">Ativo</option><option value="inactive">Inativo</option></select></label></div>
            <label class="field"><span>Nome do fluxo</span><input name="name" placeholder="Agenda Google do Cliente X" required></label>
            <label class="field"><span>Descrição</span><input name="description" placeholder="Fluxo que cria eventos no Google Calendar"></label>
            <label class="field"><span>URL do Webhook n8n</span><input name="webhook_url" placeholder="https://n8n.seudominio.com/webhook/..." required></label>
            <label class="field"><span>Token secreto opcional</span><input name="secret_token" type="password" placeholder="Será enviado em Authorization: Bearer"><small class="field-hint">Use para validar no fluxo n8n se o evento veio do RS Connect.</small></label>
            <fieldset class="field checkbox-fieldset"><legend>Eventos que disparam este fluxo</legend><?php foreach ($eventOptions as $eventKey => $eventLabel): ?><label class="check-field"><input type="checkbox" name="events[]" value="<?= View::e($eventKey) ?>" <?= $eventKey === '*' ? 'checked' : '' ?>><span><?= View::e($eventLabel) ?></span></label><?php endforeach; ?></fieldset>
            <button class="btn btn-primary btn-block" type="submit">Salvar fluxo da empresa</button>
        </form>
    </aside>
</div>

<section class="card table-card">
    <div class="section-heading"><div><span class="eyebrow">Auditoria</span><h2>Últimos envios para n8n</h2></div><span class="badge"><?= count($logs) ?> registros</span></div>
    <div class="table-wrap">
        <table class="clean-table">
            <thead><tr><th>Data</th><th>Empresa</th><th>Fluxo</th><th>Evento</th><th>Status</th><th>HTTP</th><th>Detalhe</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= View::e($formatDate($log['created_at'])) ?></td>
                    <td><?= View::e($log['tenant_name']) ?></td>
                    <td><?= View::e($log['flow_name'] ?? '—') ?></td>
                    <td><?= View::e($log['event']) ?></td>
                    <td><span class="badge badge-<?= View::e($log['status']) ?>"><?= View::e($statusLabel[$log['status']] ?? $log['status']) ?></span></td>
                    <td><?= View::e((string) ($log['http_status'] ?? '—')) ?></td>
                    <td class="automation-detail"><?= View::e($log['error_message'] ?: ($log['response_preview'] ?: '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?><tr><td colspan="7"><div class="empty-state">Nenhum envio registrado.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
