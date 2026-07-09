<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
?>
<div class="content-grid management-layout">
    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Operação RS</span><h2>Credenciais por empresa/agente</h2></div>
            <span class="badge"><?= count($credentials) ?> credencial(is)</span>
        </div>
        <p class="muted">Use esta área para cadastrar a chave OpenAI/Gemini do cliente sem expor dados técnicos no painel do cliente. A chave fica criptografada e nunca é exibida novamente.</p>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Empresa</th><th>Escopo</th><th>Provedor</th><th>Modelo</th><th>Chave</th><th>Status</th><th>Atualizar</th></tr></thead>
                <tbody>
                <?php foreach ($credentials as $credential): ?>
                    <tr>
                        <td><strong><?= View::e($credential['tenant_name']) ?></strong><br><small><?= View::e($credential['label']) ?></small></td>
                        <td><?= $credential['agent_name'] ? 'Agente: ' . View::e($credential['agent_name']) : 'Empresa inteira' ?><?= (int) $credential['is_default'] === 1 ? '<br><span class="badge">Padrão</span>' : '' ?></td>
                        <td><?= View::e($credential['provider']) ?></td>
                        <td><?= View::e($credential['default_model'] ?? 'modelo do agente') ?></td>
                        <td><?= View::e($credential['api_key_masked']) ?></td>
                        <td><span class="badge badge-<?= View::e($credential['status']) ?>"><?= $credential['status'] === 'active' ? 'Ativa' : 'Inativa' ?></span></td>
                        <td>
                            <details>
                                <summary class="btn btn-small btn-outline">Editar</summary>
                                <form class="stack compact-form" method="post" action="<?= View::e(Router::url('/ai-credentials/save')) ?>">
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="id" value="<?= (int) $credential['id'] ?>">
                                    <label class="field compact-field"><span>Empresa</span><select name="tenant_id" required><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>" <?= (int) $credential['tenant_id'] === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select></label>
                                    <label class="field compact-field"><span>Agente específico</span><select name="agent_id"><option value="0">Empresa inteira</option><?php foreach ($agents as $agent): ?><option value="<?= (int) $agent['id'] ?>" <?= (int) ($credential['agent_id'] ?? 0) === (int) $agent['id'] ? 'selected' : '' ?>><?= View::e($agent['tenant_name'] . ' — ' . $agent['name']) ?></option><?php endforeach; ?></select></label>
                                    <label class="field compact-field"><span>Nome</span><input name="label" value="<?= View::e($credential['label']) ?>" required></label>
                                    <div class="form-grid two"><label class="field compact-field"><span>Provedor</span><select name="provider"><option value="openai" <?= $credential['provider'] === 'openai' ? 'selected' : '' ?>>OpenAI</option><option value="google" <?= $credential['provider'] === 'google' ? 'selected' : '' ?>>Google Gemini</option><option value="custom" <?= $credential['provider'] === 'custom' ? 'selected' : '' ?>>Custom</option></select></label><label class="field compact-field"><span>Status</span><select name="status"><option value="active" <?= $credential['status'] === 'active' ? 'selected' : '' ?>>Ativa</option><option value="inactive" <?= $credential['status'] === 'inactive' ? 'selected' : '' ?>>Inativa</option></select></label></div>
                                    <label class="field compact-field"><span>Nova API Key</span><input name="api_key" placeholder="Deixe em branco para manter a atual"></label>
                                    <div class="form-grid two"><label class="field compact-field"><span>Base URL</span><input name="base_url" value="<?= View::e($credential['base_url'] ?? '') ?>" placeholder="Opcional"></label><label class="field compact-field"><span>Modelo padrão</span><input name="default_model" value="<?= View::e($credential['default_model'] ?? '') ?>" placeholder="gpt-4o-mini"></label></div>
                                    <label class="check-field compact-check"><input type="checkbox" name="is_default" value="1" <?= (int) $credential['is_default'] === 1 ? 'checked' : '' ?>><span>Credencial padrão deste escopo</span></label>
                                    <button class="btn btn-primary btn-block" type="submit">Salvar credencial</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$credentials): ?><tr><td colspan="7" class="empty-cell">Nenhuma credencial cadastrada.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="stack">
        <form class="card sticky-card" method="post" action="<?= View::e(Router::url('/ai-credentials/save')) ?>">
            <?= Csrf::input() ?>
            <div class="section-heading"><div><span class="eyebrow">Nova credencial</span><h2>API do cliente</h2></div></div>
            <label class="field"><span>Empresa</span><select name="tenant_id" required><option value="">Selecione</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>"><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Agente específico</span><select name="agent_id"><option value="0">Usar para a empresa inteira</option><?php foreach ($agents as $agent): ?><option value="<?= (int) $agent['id'] ?>"><?= View::e($agent['tenant_name'] . ' — ' . $agent['name']) ?></option><?php endforeach; ?></select><small class="field-hint">Deixe como empresa inteira para todos os agentes do cliente usarem esta chave por padrão.</small></label>
            <label class="field"><span>Nome da credencial</span><input name="label" placeholder="OpenAI Cliente X" required></label>
            <div class="form-grid two"><label class="field"><span>Provedor</span><select name="provider"><option value="openai">OpenAI</option><option value="google">Google Gemini</option><option value="custom">Custom</option></select></label><label class="field"><span>Status</span><select name="status"><option value="active">Ativa</option><option value="inactive">Inativa</option></select></label></div>
            <label class="field"><span>API Key</span><input name="api_key" type="password" placeholder="sk-..." required><small class="field-hint">A chave será criptografada. Depois de salvar, ela não será exibida novamente.</small></label>
            <div class="form-grid two"><label class="field"><span>Base URL</span><input name="base_url" placeholder="https://api.openai.com/v1"></label><label class="field"><span>Modelo padrão</span><input name="default_model" value="gpt-4o-mini"></label></div>
            <label class="check-field"><input type="checkbox" name="is_default" value="1" checked><span>Definir como padrão do escopo selecionado</span></label>
            <button class="btn btn-primary btn-block" type="submit">Salvar chave criptografada</button>
        </form>
    </aside>
</div>
