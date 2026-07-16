<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Router;
use App\Core\View;

$canManage = Auth::can('instances.manage');
$isSuperAdmin = Auth::isSuperAdmin();
$canGenerateQr = $canManage;
$adminAgents = $adminAgents ?? [];
$instancesByTenant = $instancesByTenant ?? [];

if (!$isSuperAdmin) {
    require __DIR__ . '/_client.php';
    return;
}

$total = count($instances);
$connected = count(array_filter($instances, static fn (array $item): bool => ($item['status'] ?? '') === 'connected'));
$pending = count(array_filter($instances, static fn (array $item): bool => in_array(($item['status'] ?? ''), ['pending', 'disconnected'], true)));
$linkedAgents = array_sum(array_map(static fn (array $item): int => (int) ($item['agents_count'] ?? 0), $instances));
$statusLabels = ['connected' => 'Conectada', 'disconnected' => 'Desconectada', 'pending' => 'Pendente'];
$webhookToken = trim((string) Env::get('EVOLUTION_WEBHOOK_TOKEN', ''));
?>

<section class="admin-module-hero">
    <div>
        <span class="eyebrow">Operação WhatsApp</span>
        <h2>Conexões WhatsApp</h2>
        <p>Cadastre, teste e recupere conexões da Evolution sem perder os vínculos com assistentes, contatos e conversas.</p>
    </div>
    <div class="admin-module-hero-actions">
        <button class="btn btn-outline" type="button" data-toggle-panel="instance-test-drawer">Enviar teste</button>
        <button class="btn btn-primary" type="button" data-instance-open="new" data-toggle-panel="instance-drawer">Nova conexão</button>
    </div>
</section>

<section class="admin-module-summary" aria-label="Resumo das conexões">
    <article><span>Total</span><strong><?= $total ?></strong><small>conexões cadastradas</small></article>
    <article class="is-success"><span>Conectadas</span><strong><?= $connected ?></strong><small>prontas para atendimento</small></article>
    <article class="is-warning"><span>Precisam de ação</span><strong><?= $pending ?></strong><small>pendentes ou desconectadas</small></article>
    <article class="is-blue"><span>Assistentes vinculados</span><strong><?= $linkedAgents ?></strong><small>vínculos preservados</small></article>
</section>

<section class="card admin-module-panel">
    <div class="section-heading admin-module-heading">
        <div><span class="eyebrow">Conexões cadastradas</span><h2>WhatsApps por empresa</h2><p>Localize uma empresa e abra somente a ação que precisa executar.</p></div>
        <span class="badge" data-admin-visible-count><?= $total ?> registro(s)</span>
    </div>
    <div class="admin-module-filters" data-admin-filter-root>
        <label class="field admin-module-search"><span>Buscar</span><input type="search" placeholder="Empresa, nome interno ou identificador Evolution" data-admin-search></label>
        <label class="field"><span>Situação</span><select data-admin-filter="status"><option value="">Todas</option><option value="connected">Conectadas</option><option value="pending">Pendentes</option><option value="disconnected">Desconectadas</option></select></label>
        <button class="btn btn-quiet" type="button" data-admin-clear>Limpar</button>
    </div>

    <div class="admin-module-card-list" data-admin-card-list>
        <?php foreach ($instances as $instance): ?>
            <?php
            $webhookUrl = Router::url('/webhooks/evolution?instance_id=' . (int) $instance['id'] . ($webhookToken !== '' ? '&token=' . rawurlencode($webhookToken) : ''));
            $searchText = mb_strtolower(trim(implode(' ', [$instance['name'], $instance['instance_name'], $instance['tenant_name'], $instance['base_url']])));
            ?>
            <article class="admin-record-card" data-admin-card data-search="<?= View::e($searchText) ?>" data-status="<?= View::e((string) $instance['status']) ?>">
                <div class="admin-record-main">
                    <span class="admin-record-mark is-whatsapp" aria-hidden="true">WA</span>
                    <div class="admin-record-copy">
                        <div class="admin-record-title-row">
                            <div><h3><?= View::e($instance['name']) ?></h3><p><?= View::e($instance['tenant_name']) ?> · <?= View::e($instance['instance_name']) ?></p></div>
                            <div class="admin-record-badges"><span class="badge badge-<?= View::e($instance['status']) ?>"><?= View::e($statusLabels[$instance['status']] ?? ucfirst((string) $instance['status'])) ?></span><?php if ((int) $instance['is_default'] === 1): ?><span class="badge">Padrão</span><?php endif; ?></div>
                        </div>
                        <small class="admin-record-muted"><?= View::e($instance['base_url']) ?></small>
                    </div>
                </div>
                <dl class="admin-record-metrics">
                    <div><dt>Assistentes</dt><dd><?= (int) $instance['agents_count'] ?></dd></div>
                    <div><dt>Contatos</dt><dd><?= (int) $instance['contacts_count'] ?></dd></div>
                    <div><dt>Conversas</dt><dd><?= (int) $instance['conversations_count'] ?></dd></div>
                    <div><dt>Campanhas</dt><dd><?= (int) $instance['campaigns_count'] ?></dd></div>
                </dl>
                <details class="admin-inline-details"><summary>Webhook e informações técnicas</summary><div class="admin-technical-copy"><strong>Webhook para mensagens</strong><code><?= View::e($webhookUrl) ?></code><small><?= $webhookToken === '' ? 'Defina EVOLUTION_WEBHOOK_TOKEN antes de utilizar.' : 'Use este endereço no evento MESSAGES_UPSERT da Evolution.' ?></small></div></details>
                <div class="admin-record-actions">
                    <?php if ($instance['status'] !== 'connected'): ?>
                        <form method="post" action="<?= View::e(Router::url('/instances/qr')) ?>" data-qr-code-form><?= Csrf::input() ?><input type="hidden" name="instance_id" value="<?= (int) $instance['id'] ?>"><button class="btn btn-small btn-outline" type="submit" data-qr-code-button>Gerar QR Code</button></form>
                    <?php endif; ?>
                    <button class="btn btn-small btn-outline" type="button" data-toggle-panel="instance-drawer" data-instance-open="edit"
                        data-id="<?= (int) $instance['id'] ?>" data-name="<?= View::e($instance['name']) ?>" data-instance-name="<?= View::e($instance['instance_name']) ?>" data-base-url="<?= View::e($instance['base_url']) ?>" data-status="<?= View::e($instance['status']) ?>" data-is-default="<?= (int) $instance['is_default'] ?>">Editar conexão</button>
                    <button class="btn btn-small btn-danger-soft" type="button" data-toggle-panel="instance-delete-drawer" data-instance-delete
                        data-id="<?= (int) $instance['id'] ?>" data-name="<?= View::e($instance['name']) ?>" data-instance-name="<?= View::e($instance['instance_name']) ?>" data-tenant-id="<?= (int) $instance['tenant_id'] ?>">Excluir</button>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$instances): ?><div class="empty-state admin-filter-empty">Nenhuma conexão cadastrada.</div><?php endif; ?>
        <div class="empty-state admin-filter-empty" data-admin-filter-empty hidden>Nenhuma conexão corresponde aos filtros.</div>
    </div>
</section>

<section class="card admin-agent-recovery admin-secondary-panel">
    <div class="section-heading"><div><span class="eyebrow">Recuperação</span><h2>Assistentes e conexões</h2><p>Reassocie um assistente quando uma conexão for recriada.</p></div><span class="badge"><?= count($adminAgents) ?> assistente(s)</span></div>
    <div class="admin-agent-list">
        <?php foreach ($adminAgents as $agent): ?>
            <?php $tenantInstances = $instancesByTenant[(int) $agent['tenant_id']] ?? []; ?>
            <details class="admin-agent-item" <?= $agent['instance_id'] === null ? 'open' : '' ?>>
                <summary><span><strong><?= View::e($agent['name']) ?></strong><small><?= View::e($agent['tenant_name']) ?> · <?= View::e($agent['segment']) ?></small></span><span class="badge <?= $agent['instance_id'] === null ? 'badge-warning' : 'badge-success' ?>"><?= $agent['instance_id'] === null ? 'Sem conexão' : View::e((string) $agent['linked_instance_name']) ?></span></summary>
                <form class="admin-agent-form" method="post" action="<?= View::e(Router::url('/instances/agent-update')) ?>">
                    <?= Csrf::input() ?><input type="hidden" name="agent_id" value="<?= (int) $agent['id'] ?>">
                    <div class="form-grid two"><label class="field"><span>Conexão vinculada</span><select name="instance_id" required><option value="">Selecione</option><?php foreach ($tenantInstances as $tenantInstance): ?><option value="<?= (int) $tenantInstance['id'] ?>" <?= (int) ($agent['instance_id'] ?? 0) === (int) $tenantInstance['id'] ? 'selected' : '' ?>><?= View::e($tenantInstance['name']) ?> (<?= View::e($tenantInstance['instance_name']) ?>)</option><?php endforeach; ?></select></label><label class="field"><span>Nome do assistente</span><input name="name" value="<?= View::e($agent['name']) ?>" required></label></div>
                    <div class="form-grid two"><label class="field"><span>Área de atendimento</span><input name="segment" value="<?= View::e($agent['segment']) ?>" required></label><label class="field"><span>Situação</span><select name="status"><option value="active" <?= $agent['status'] === 'active' ? 'selected' : '' ?>>Ativo</option><option value="inactive" <?= $agent['status'] === 'inactive' ? 'selected' : '' ?>>Inativo</option></select></label></div>
                    <details class="admin-inline-details"><summary>Configurações avançadas do modelo</summary><div class="form-grid three"><label class="field"><span>Provedor</span><select name="model_provider"><?php foreach (['openai' => 'OpenAI', 'google' => 'Google Gemini', 'anthropic' => 'Anthropic', 'custom' => 'Personalizado'] as $provider => $label): ?><option value="<?= View::e($provider) ?>" <?= $agent['model_provider'] === $provider ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select></label><label class="field"><span>Modelo</span><input name="model_name" value="<?= View::e($agent['model_name']) ?>" required></label><label class="field"><span>Criatividade</span><input type="number" name="temperature" min="0" max="1" step="0.1" value="<?= View::e((string) $agent['temperature']) ?>"></label></div><div class="form-grid two"><label class="field"><span>Mensagens de contexto</span><input type="number" name="max_context_messages" min="4" max="30" value="<?= (int) $agent['max_context_messages'] ?>"></label><div class="admin-check-stack"><label class="check-field"><input type="checkbox" name="auto_reply_enabled" value="1" <?= (int) $agent['auto_reply_enabled'] === 1 ? 'checked' : '' ?>><span>Respostas automáticas</span></label><label class="check-field"><input type="checkbox" name="is_default" value="1" <?= (int) $agent['is_default'] === 1 ? 'checked' : '' ?>><span>Assistente principal</span></label></div></div></details>
                    <?php if (!$tenantInstances): ?><p class="message-error">Cadastre primeiro uma conexão para esta empresa.</p><?php endif; ?>
                    <button class="btn btn-primary" type="submit" <?= !$tenantInstances ? 'disabled' : '' ?>>Salvar vínculo</button>
                </form>
            </details>
        <?php endforeach; ?>
        <?php if (!$adminAgents): ?><div class="empty-state">Nenhum assistente cadastrado.</div><?php endif; ?>
    </div>
</section>

<aside class="conversation-details conversation-drawer admin-form-drawer" id="instance-drawer" aria-label="Configurar conexão WhatsApp" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header"><div><span class="eyebrow" data-instance-drawer-eyebrow>Nova conexão</span><h2 data-instance-drawer-title>Configurar WhatsApp</h2><p data-instance-drawer-description>Cadastre a conexão preparada na Evolution API.</p></div><button class="icon-button drawer-close" type="button" data-close-panel="instance-drawer" aria-label="Fechar">×</button></div>
    <div class="conversation-drawer-body"><form class="drawer-form" method="post" action="<?= View::e(Router::url('/instances')) ?>" data-instance-form><?= Csrf::input() ?><input type="hidden" name="instance_id" value="0" data-instance-field="id">
        <section class="drawer-section"><div class="drawer-section-title"><div><span class="eyebrow">1. Cliente</span><h3>Empresa e identificação</h3></div></div><div class="drawer-form-grid"><label class="field drawer-span" data-instance-tenant-field><span>Empresa</span><select name="tenant_id" data-instance-field="tenant_id" required><option value="">Selecione</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>"><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select></label><label class="field"><span>Nome interno</span><input name="name" data-instance-field="name" placeholder="WhatsApp Comercial" required></label><label class="field"><span>Identificador na Evolution</span><input name="instance_name" data-instance-field="instance_name" placeholder="rsconnect-comercial" required></label></div></section>
        <section class="drawer-section"><div class="drawer-section-title"><div><span class="eyebrow">2. Integração</span><h3>Acesso à Evolution</h3></div></div><div class="drawer-form-grid"><label class="field drawer-span"><span>URL base</span><input type="url" name="base_url" data-instance-field="base_url" value="<?= View::e($defaultUrl) ?>" placeholder="https://evolution.seudominio.com" required></label><label class="field drawer-span"><span data-instance-api-label>API Key</span><input type="password" name="api_key" data-instance-field="api_key" placeholder="Chave global ou da conexão"><small class="field-hint" data-instance-api-hint>Obrigatória no primeiro cadastro.</small></label><label class="field"><span>Situação</span><select name="status" data-instance-field="status"><option value="disconnected">Desconectada</option><option value="pending">Pendente</option><option value="connected">Conectada</option></select></label><label class="check-field drawer-check"><input type="checkbox" name="is_default" value="1" data-instance-field="is_default"><span>Definir como conexão padrão</span></label></div></section>
        <div class="drawer-savebar"><button class="btn btn-quiet" type="button" data-close-panel="instance-drawer">Cancelar</button><button class="btn btn-primary" type="submit" data-instance-submit>Salvar conexão</button></div>
    </form></div>
</aside>

<aside class="conversation-details conversation-drawer admin-form-drawer" id="instance-test-drawer" aria-label="Testar conexão WhatsApp" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header"><div><span class="eyebrow">Validação</span><h2>Enviar mensagem de teste</h2><p>Confirme se a conexão consegue enviar mensagens antes de liberar para o cliente.</p></div><button class="icon-button drawer-close" type="button" data-close-panel="instance-test-drawer" aria-label="Fechar">×</button></div>
    <div class="conversation-drawer-body"><form class="drawer-form" method="post" action="<?= View::e(Router::url('/instances/test')) ?>"><?= Csrf::input() ?><section class="drawer-section"><div class="drawer-form-grid"><label class="field drawer-span"><span>Conexão</span><select name="instance_id" required><option value="">Selecione</option><?php foreach ($instances as $instance): ?><option value="<?= (int) $instance['id'] ?>"><?= View::e($instance['name']) ?> — <?= View::e($instance['tenant_name']) ?></option><?php endforeach; ?></select></label><label class="field drawer-span"><span>Telefone com DDI</span><input name="phone" inputmode="numeric" placeholder="5511999999999" required></label><label class="field drawer-span"><span>Mensagem</span><textarea name="message" rows="5" required>Teste de integração do RS Connect.</textarea></label></div></section><div class="drawer-savebar"><button class="btn btn-quiet" type="button" data-close-panel="instance-test-drawer">Cancelar</button><button class="btn btn-primary" type="submit" <?= !$instances ? 'disabled' : '' ?>>Enviar teste</button></div></form></div>
</aside>

<aside class="conversation-details conversation-drawer admin-form-drawer" id="instance-delete-drawer" aria-label="Excluir conexão" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header"><div><span class="eyebrow text-danger">Ação restrita</span><h2>Excluir cadastro da conexão</h2><p>A conexão será removida apenas do RS Connect. O cadastro na Evolution permanece.</p></div><button class="icon-button drawer-close" type="button" data-close-panel="instance-delete-drawer" aria-label="Fechar">×</button></div>
    <div class="conversation-drawer-body"><form class="drawer-form" method="post" action="<?= View::e(Router::url('/instances/delete')) ?>" data-instance-delete-form onsubmit="return confirm('Confirma a exclusão deste cadastro no RS Connect?');"><?= Csrf::input() ?><input type="hidden" name="instance_id" data-instance-delete-field="id"><section class="drawer-section danger-zone"><div class="drawer-form-grid"><div class="drawer-span admin-danger-message"><strong data-instance-delete-name>Conexão</strong><span>Selecione uma substituta quando existirem assistentes, contatos, conversas ou campanhas vinculadas.</span></div><label class="field drawer-span"><span>Migrar vínculos para</span><select name="replacement_instance_id" data-instance-delete-field="replacement"><option value="">Nenhuma — somente se não houver vínculos</option><?php foreach ($instances as $replacement): ?><option value="<?= (int) $replacement['id'] ?>" data-tenant-id="<?= (int) $replacement['tenant_id'] ?>"><?= View::e($replacement['name']) ?> — <?= View::e($replacement['tenant_name']) ?></option><?php endforeach; ?></select></label><label class="field drawer-span"><span>Confirmação</span><input name="confirmation" autocomplete="off" data-instance-delete-field="confirmation" required><small class="field-hint" data-instance-delete-hint></small></label></div></section><div class="drawer-savebar"><button class="btn btn-quiet" type="button" data-close-panel="instance-delete-drawer">Cancelar</button><button class="btn btn-danger" type="submit">Excluir cadastro</button></div></form></div>
</aside>

<?php if ($canGenerateQr): ?>
<div class="qr-connection-modal" data-qr-code-modal hidden aria-hidden="true"><button class="qr-modal-backdrop" type="button" data-close-qr-modal aria-label="Fechar QR Code"></button><section class="qr-modal-card" role="dialog" aria-modal="true" aria-labelledby="qr-modal-title"><div class="qr-modal-header"><div><span class="eyebrow">Conectar WhatsApp</span><h2 id="qr-modal-title">Escaneie o QR Code</h2></div><button class="icon-button" type="button" data-close-qr-modal aria-label="Fechar">×</button></div><div class="qr-modal-body"><div class="qr-loading" data-qr-loading>Gerando QR Code com segurança...</div><img data-qr-image alt="QR Code para conectar o WhatsApp" hidden><p data-qr-message>Abra o WhatsApp no celular, toque em <strong>Dispositivos conectados</strong> e depois em <strong>Conectar dispositivo</strong>.</p><div class="qr-error-message" data-qr-error hidden></div></div><div class="qr-modal-actions"><button class="btn btn-quiet" type="button" data-close-qr-modal>Fechar</button></div></section></div>
<?php endif; ?>
