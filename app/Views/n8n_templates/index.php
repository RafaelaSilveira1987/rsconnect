<?php

use App\Core\Router;
use App\Core\View;

$formatDate = static function (?string $date): string {
    if (!$date) {
        return '—';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date('d/m/Y H:i:s', $timestamp) : $date;
};
$statusLabel = ['success' => 'Sucesso', 'error' => 'Erro', 'info' => 'Info'];
?>

<div class="content-grid management-layout n8n-template-page">
    <section class="card">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Modelos comerciais</span>
                <h2>Templates n8n por segmento</h2>
            </div>
            <span class="badge"><?= count($templates) ?> templates</span>
        </div>
        <p class="muted">Use estes modelos como ponto de partida. No RS Connect, cada empresa deve ter seu próprio fluxo cadastrado em <strong>Fluxos n8n</strong>, com webhook e credenciais separadas.</p>

        <div class="template-grid">
            <?php foreach ($templates as $key => $template): ?>
                <article class="template-card">
                    <span class="badge"><?= View::e($template['segment']) ?></span>
                    <h3><?= View::e($template['title']) ?></h3>
                    <p><?= View::e($template['description']) ?></p>
                    <div class="template-events">
                        <?php foreach ($template['events'] as $event): ?><code><?= View::e($event) ?></code><?php endforeach; ?>
                    </div>
                    <a class="btn btn-primary btn-small" href="<?= View::e(Router::url('/n8n-templates/download') . '?template=' . urlencode((string) $key)) ?>">Baixar JSON n8n</a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <aside class="stack">
        <section class="card sticky-card">
            <div class="section-heading"><div><span class="eyebrow">Callback</span><h2>Retorno do n8n</h2></div></div>
            <p class="muted">Use este endpoint no final do fluxo n8n para registrar sucesso ou erro dentro do RS Connect.</p>
            <label class="field"><span>URL de callback</span><input readonly value="<?= View::e($callbackUrl) ?>"></label>
            <p class="field-hint">Token configurado: <strong><?= !empty($callbackTokenConfigured) ? 'Sim' : 'Não' ?></strong>. Se usar <code>N8N_CALLBACK_TOKEN</code>, envie em <code>X-RS-Connect-Token</code> ou <code>Authorization: Bearer</code>.</p>
            <pre class="code-block"><code>{
  "tenant_id": 1,
  "flow_id": 1,
  "event": "calendar.appointment.created",
  "status": "success",
  "external_id": "google_event_id",
  "message": "Evento criado com sucesso"
}</code></pre>
        </section>
    </aside>
</div>

<section class="card">
    <div class="section-heading"><div><span class="eyebrow">Payloads</span><h2>Exemplos enviados pelo RS Connect</h2></div></div>
    <div class="template-grid two-columns">
        <?php foreach ($samplePayloads as $event => $payload): ?>
            <article class="template-card">
                <h3><?= View::e($event) ?></h3>
                <pre class="code-block"><code><?= View::e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></code></pre>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="card table-card">
    <div class="section-heading"><div><span class="eyebrow">Retornos</span><h2>Últimos callbacks do n8n</h2></div><span class="badge"><?= count($callbacks) ?> registros</span></div>
    <div class="table-wrap">
        <table class="clean-table">
            <thead><tr><th>Data</th><th>Empresa</th><th>Fluxo</th><th>Evento</th><th>Status</th><th>Externo</th><th>Mensagem</th></tr></thead>
            <tbody>
            <?php foreach ($callbacks as $callback): ?>
                <tr>
                    <td><?= View::e($formatDate($callback['created_at'] ?? null)) ?></td>
                    <td><?= View::e($callback['tenant_name'] ?? '—') ?></td>
                    <td><?= View::e($callback['flow_name'] ?? '—') ?></td>
                    <td><?= View::e($callback['event'] ?? '') ?></td>
                    <td><span class="badge badge-<?= View::e($callback['status'] ?? 'info') ?>"><?= View::e($statusLabel[$callback['status'] ?? 'info'] ?? ($callback['status'] ?? 'Info')) ?></span></td>
                    <td><?= View::e($callback['external_id'] ?? '—') ?></td>
                    <td><?= View::e($callback['message'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$callbacks): ?><tr><td colspan="7"><div class="empty-state">Nenhum callback registrado. Rode a migration 011 e teste um fluxo n8n.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
