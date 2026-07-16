<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Router;
use App\Core\View;

$canManage = Auth::can('instances.manage');
$isSuperAdmin = Auth::isSuperAdmin();
$adminAgents = $adminAgents ?? [];
$instancesByTenant = $instancesByTenant ?? [];
?>
<div class="content-grid <?= $canManage ? 'instances-layout' : '' ?>">
    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Evolution API</span><h2>Instâncias cadastradas</h2></div>
            <span class="badge"><?= count($instances) ?> registro(s)</span>
        </div>

        <?php if ($isSuperAdmin): ?>
            <div class="admin-technical-note">
                <strong>Operação técnica RS</strong>
                <span>Quando a instância for recriada na Evolution, atualize o cadastro existente abaixo. Isso mantém o mesmo ID e preserva agente, conversas e histórico.</span>
            </div>
        <?php endif; ?>

        <div class="instance-list">
            <?php foreach ($instances as $instance): ?>
                <article class="instance-item">
                    <div class="instance-main">
                        <span class="instance-icon instance-icon-device" aria-hidden="true"></span>
                        <div>
                            <h3><?= View::e($instance['name']) ?></h3>
                            <p><?= View::e($instance['instance_name']) ?> · <?= View::e($instance['tenant_name']) ?></p>
                            <small><?= View::e($instance['base_url']) ?></small>
                        </div>
                    </div>
                    <div class="instance-meta">
                        <span class="badge badge-<?= View::e($instance['status']) ?>"><?= View::e(ucfirst($instance['status'])) ?></span>
                        <?php if ((int) $instance['is_default'] === 1): ?><span class="badge">Padrão</span><?php endif; ?>
                    </div>

                    <?php if ($canManage): ?>
                        <?php
                        $webhookToken = trim((string) Env::get('EVOLUTION_WEBHOOK_TOKEN', ''));
                        $webhookUrl = Router::url('/webhooks/evolution?instance_id=' . (int) $instance['id'] . ($webhookToken !== '' ? '&token=' . rawurlencode($webhookToken) : ''));
                        ?>
                        <div class="webhook-box">
                            <strong>Webhook para mensagens</strong>
                            <code><?= View::e($webhookUrl) ?></code>
                            <small><?= $webhookToken === '' ? 'Defina EVOLUTION_WEBHOOK_TOKEN no .env antes de usar este endereço.' : 'Configure este endereço na Evolution para o evento messages.upsert.' ?></small>
                        </div>
                    <?php endif; ?>

                    <?php if ($isSuperAdmin): ?>
                        <div class="instance-admin-summary">
                            <span><strong><?= (int) $instance['agents_count'] ?></strong> agente(s)</span>
                            <span><strong><?= (int) $instance['contacts_count'] ?></strong> contato(s)</span>
                            <span><strong><?= (int) $instance['conversations_count'] ?></strong> conversa(s)</span>
                            <span><strong><?= (int) $instance['campaigns_count'] ?></strong> campanha(s)</span>
                        </div>

                        <details class="admin-technical-panel">
                            <summary>Atualizar ou excluir esta instância</summary>
                            <div class="admin-technical-grid">
                                <form class="admin-technical-form" method="post" action="<?= View::e(Router::url('/instances/update')) ?>">
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="instance_id" value="<?= (int) $instance['id'] ?>">
                                    <div class="section-heading compact-heading"><div><span class="eyebrow">Reconfiguração</span><h3>Atualizar conexão</h3></div></div>
                                    <p class="field-hint">Use esta opção após recriar a instância na Evolution. Deixe a API Key vazia para manter a atual.</p>
                                    <label class="field"><span>Nome interno</span><input name="name" value="<?= View::e($instance['name']) ?>" required></label>
                                    <label class="field"><span>Novo nome na Evolution</span><input name="instance_name" value="<?= View::e($instance['instance_name']) ?>" required></label>
                                    <label class="field"><span>URL base</span><input type="url" name="base_url" value="<?= View::e($instance['base_url']) ?>" required></label>
                                    <label class="field"><span>Nova API Key</span><input type="password" name="api_key" placeholder="Deixe vazio para preservar a chave atual"></label>
                                    <div class="form-grid two">
                                        <label class="field"><span>Status</span><select name="status">
                                            <?php foreach (['connected' => 'Conectada', 'disconnected' => 'Desconectada', 'pending' => 'Pendente'] as $value => $label): ?>
                                                <option value="<?= View::e($value) ?>" <?= $instance['status'] === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select></label>
                                        <label class="check-field admin-default-check"><input type="checkbox" name="is_default" value="1" <?= (int) $instance['is_default'] === 1 ? 'checked' : '' ?>><span>Instância padrão</span></label>
                                    </div>
                                    <button class="btn btn-primary btn-block" type="submit">Atualizar sem perder vínculos</button>
                                </form>

                                <form class="admin-technical-form danger-zone" method="post" action="<?= View::e(Router::url('/instances/delete')) ?>" onsubmit="return confirm('Confirma a exclusão deste cadastro no RS Connect?');">
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="instance_id" value="<?= (int) $instance['id'] ?>">
                                    <div class="section-heading compact-heading"><div><span class="eyebrow text-danger">Ação restrita</span><h3>Excluir cadastro</h3></div></div>
                                    <p class="field-hint">A exclusão ocorre somente no RS Connect. Ela não remove a instância no painel da Evolution.</p>
                                    <label class="field"><span>Migrar vínculos para</span><select name="replacement_instance_id">
                                        <option value="">Nenhuma — permitido apenas se não houver vínculos</option>
                                        <?php foreach (($instancesByTenant[(int) $instance['tenant_id']] ?? []) as $replacement): ?>
                                            <?php if ((int) $replacement['id'] === (int) $instance['id']) continue; ?>
                                            <option value="<?= (int) $replacement['id'] ?>"><?= View::e($replacement['name']) ?> (<?= View::e($replacement['instance_name']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select><small class="field-hint">Com substituta, agentes, contatos, conversas e campanhas são preservados e migrados.</small></label>
                                    <label class="field"><span>Confirmação</span><input name="confirmation" autocomplete="off" placeholder="EXCLUIR <?= View::e($instance['instance_name']) ?>" required><small class="field-hint">Digite exatamente: <strong>EXCLUIR <?= View::e($instance['instance_name']) ?></strong></small></label>
                                    <button class="btn btn-danger btn-block" type="submit">Excluir cadastro da instância</button>
                                </form>
                            </div>
                        </details>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$instances): ?><div class="empty-state">Nenhuma instância cadastrada.</div><?php endif; ?>
        </div>
    </section>

    <?php if ($canManage): ?>
        <aside class="stack">
            <form class="card" method="post" action="<?= View::e(Router::url('/instances')) ?>">
                <?= Csrf::input() ?>
                <div class="section-heading"><div><span class="eyebrow">Nova conexão</span><h2>Cadastrar instância</h2></div></div>

                <?php if ($isSuperAdmin): ?>
                    <label class="field"><span>Empresa</span><select name="tenant_id" required><option value="">Selecione</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>"><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select></label>
                <?php endif; ?>

                <label class="field"><span>Nome interno</span><input name="name" placeholder="WhatsApp Comercial" required></label>
                <label class="field"><span>Nome na Evolution</span><input name="instance_name" placeholder="rsconnect-comercial" required></label>
                <label class="field"><span>URL base</span><input type="url" name="base_url" value="<?= View::e($defaultUrl) ?>" placeholder="https://evolution.seudominio.com" required></label>
                <label class="field"><span>API Key</span><input type="password" name="api_key" placeholder="Chave global ou da instância" required></label>
                <label class="field"><span>Status</span><select name="status"><option value="disconnected">Desconectada</option><option value="pending">Pendente</option><option value="connected">Conectada</option></select></label>
                <label class="check-field"><input type="checkbox" name="is_default" value="1"><span>Definir como instância padrão</span></label>
                <button class="btn btn-primary btn-block" type="submit">Salvar instância</button>
            </form>

            <form class="card" method="post" action="<?= View::e(Router::url('/instances/test')) ?>">
                <?= Csrf::input() ?>
                <div class="section-heading"><div><span class="eyebrow">Validação</span><h2>Enviar teste</h2></div></div>
                <label class="field"><span>Instância</span><select name="instance_id" required><option value="">Selecione</option><?php foreach ($instances as $instance): ?><option value="<?= (int) $instance['id'] ?>"><?= View::e($instance['name']) ?> — <?= View::e($instance['tenant_name']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Telefone</span><input name="phone" inputmode="numeric" placeholder="5511999999999" required></label>
                <label class="field"><span>Mensagem</span><textarea name="message" rows="3" required>Teste de integração do RS Connect.</textarea></label>
                <button class="btn btn-secondary btn-block" type="submit" <?= !$instances ? 'disabled' : '' ?>>Enviar pela Evolution</button>
            </form>
        </aside>
    <?php endif; ?>
</div>

<?php if ($isSuperAdmin): ?>
<section class="card admin-agent-recovery" style="margin-top:18px">
    <div class="section-heading">
        <div><span class="eyebrow">Administração RS</span><h2>Recuperar ou atualizar agentes de IA</h2></div>
        <span class="badge"><?= count($adminAgents) ?> agente(s)</span>
    </div>
    <p class="muted-text">Use esta área quando uma instância for recriada. A alteração reassocia o agente à nova conexão sem apagar prompt, base de conhecimento, horários, n8n ou credenciais de IA.</p>

    <div class="admin-agent-list">
        <?php foreach ($adminAgents as $agent): ?>
            <?php $tenantInstances = $instancesByTenant[(int) $agent['tenant_id']] ?? []; ?>
            <details class="admin-agent-item" <?= $agent['instance_id'] === null ? 'open' : '' ?>>
                <summary>
                    <span><strong><?= View::e($agent['name']) ?></strong><small><?= View::e($agent['tenant_name']) ?> · <?= View::e($agent['segment']) ?></small></span>
                    <span class="badge <?= $agent['instance_id'] === null ? 'badge-warning' : 'badge-success' ?>"><?= $agent['instance_id'] === null ? 'Sem instância' : View::e((string) $agent['linked_instance_name']) ?></span>
                </summary>
                <form class="admin-agent-form" method="post" action="<?= View::e(Router::url('/instances/agent-update')) ?>">
                    <?= Csrf::input() ?>
                    <input type="hidden" name="agent_id" value="<?= (int) $agent['id'] ?>">
                    <div class="form-grid two">
                        <label class="field"><span>Instância vinculada</span><select name="instance_id" required>
                            <option value="">Selecione</option>
                            <?php foreach ($tenantInstances as $tenantInstance): ?>
                                <option value="<?= (int) $tenantInstance['id'] ?>" <?= (int) ($agent['instance_id'] ?? 0) === (int) $tenantInstance['id'] ? 'selected' : '' ?>><?= View::e($tenantInstance['name']) ?> (<?= View::e($tenantInstance['instance_name']) ?>)</option>
                            <?php endforeach; ?>
                        </select></label>
                        <label class="field"><span>Nome do agente</span><input name="name" value="<?= View::e($agent['name']) ?>" required></label>
                    </div>
                    <div class="form-grid two">
                        <label class="field"><span>Segmento</span><input name="segment" value="<?= View::e($agent['segment']) ?>" required></label>
                        <label class="field"><span>Status</span><select name="status"><option value="active" <?= $agent['status'] === 'active' ? 'selected' : '' ?>>Ativo</option><option value="inactive" <?= $agent['status'] === 'inactive' ? 'selected' : '' ?>>Inativo</option></select></label>
                    </div>
                    <div class="form-grid three">
                        <label class="field"><span>Provedor</span><select name="model_provider">
                            <?php foreach (['openai' => 'OpenAI', 'google' => 'Google Gemini', 'anthropic' => 'Anthropic', 'custom' => 'Custom'] as $provider => $label): ?>
                                <option value="<?= View::e($provider) ?>" <?= $agent['model_provider'] === $provider ? 'selected' : '' ?>><?= View::e($label) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <label class="field"><span>Modelo</span><input name="model_name" value="<?= View::e($agent['model_name']) ?>" required></label>
                        <label class="field"><span>Temperatura</span><input type="number" name="temperature" min="0" max="1" step="0.1" value="<?= View::e((string) $agent['temperature']) ?>"></label>
                    </div>
                    <div class="form-grid two">
                        <label class="field"><span>Mensagens de contexto</span><input type="number" name="max_context_messages" min="4" max="30" value="<?= (int) $agent['max_context_messages'] ?>"></label>
                        <div class="admin-check-stack">
                            <label class="check-field"><input type="checkbox" name="auto_reply_enabled" value="1" <?= (int) $agent['auto_reply_enabled'] === 1 ? 'checked' : '' ?>><span>Auto-resposta ativa</span></label>
                            <label class="check-field"><input type="checkbox" name="is_default" value="1" <?= (int) $agent['is_default'] === 1 ? 'checked' : '' ?>><span>Agente padrão da empresa</span></label>
                        </div>
                    </div>
                    <?php if (!$tenantInstances): ?><p class="message-error">Cadastre primeiro uma nova instância para esta empresa.</p><?php endif; ?>
                    <button class="btn btn-primary" type="submit" <?= !$tenantInstances ? 'disabled' : '' ?>>Salvar vínculo e informações</button>
                </form>
            </details>
        <?php endforeach; ?>
        <?php if (!$adminAgents): ?><div class="empty-state">Nenhum agente de IA cadastrado.</div><?php endif; ?>
    </div>
</section>
<?php endif; ?>
