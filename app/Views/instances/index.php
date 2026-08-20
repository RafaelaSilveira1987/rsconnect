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
$routingByInstance = $routingByInstance ?? [];
$allowedWebhookEvents = $allowedWebhookEvents ?? [];
$eventLabels = [
    'MESSAGES_UPSERT' => 'Novas mensagens',
    'MESSAGES_UPDATE' => 'Status das mensagens',
    'MESSAGES_DELETE' => 'Mensagens apagadas',
    'SEND_MESSAGE' => 'Mensagens enviadas pela API',
    'CONNECTION_UPDATE' => 'Estado da conexão',
    'QRCODE_UPDATED' => 'Atualização do QR Code',
    'CONTACTS_UPSERT' => 'Novos contatos',
    'CONTACTS_UPDATE' => 'Atualização de contatos',
    'CHATS_UPSERT' => 'Novas conversas',
    'CHATS_UPDATE' => 'Atualização de conversas',
    'PRESENCE_UPDATE' => 'Presença e digitação',
    'GROUPS_UPSERT' => 'Novos grupos',
    'GROUPS_UPDATE' => 'Atualização de grupos',
    'GROUP_PARTICIPANTS_UPDATE' => 'Participantes de grupos',
    'CALL' => 'Chamadas recebidas',
];

if (!$canManage) {
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
        <h2>Canais WhatsApp</h2>
        <p><?= $isSuperAdmin ? 'Gerencie os números de todas as empresas, conecte assistentes e preserve os vínculos com contatos e conversas.' : 'Crie e administre os números da sua empresa sem depender do acesso ao painel da Evolution.' ?></p>
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
        <div><span class="eyebrow">Canais cadastrados</span><h2><?= $isSuperAdmin ? 'WhatsApps por empresa' : 'WhatsApps da empresa' ?></h2><p>Cada número é um canal. Configure conexão, filtros, webhook e roteamento no mesmo registro.</p></div>
        <span class="badge" data-admin-visible-count><?= $total ?> registro(s)</span>
    </div>
    <div class="admin-module-filters" data-admin-filter-root>
        <label class="field admin-module-search"><span>Buscar</span><input type="search" placeholder="<?= $isSuperAdmin ? 'Empresa, nome interno ou identificador Evolution' : 'Nome interno ou identificador do WhatsApp' ?>" data-admin-search></label>
        <label class="field"><span>Situação</span><select data-admin-filter="status"><option value="">Todas</option><option value="connected">Conectadas</option><option value="pending">Pendentes</option><option value="disconnected">Desconectadas</option></select></label>
        <button class="btn btn-quiet" type="button" data-admin-clear>Limpar</button>
    </div>

    <div class="admin-module-card-list" data-admin-card-list>
        <?php foreach ($instances as $instance): ?>
            <?php
            $webhookUrl = $isSuperAdmin ? Router::url('/webhooks/evolution?instance_id=' . (int) $instance['id'] . ($webhookToken !== '' ? '&token=' . rawurlencode($webhookToken) : '')) : '';
            $searchParts = [$instance['name'], $instance['instance_name']];
            if ($isSuperAdmin) {
                $searchParts[] = $instance['tenant_name'];
                $searchParts[] = $instance['base_url'];
            }
            $searchText = mb_strtolower(trim(implode(' ', $searchParts)));
            $settingsData = rawurlencode(json_encode([
                'id' => (int) $instance['id'],
                'name' => (string) $instance['name'],
                'webhook_enabled' => (int) ($instance['webhook_enabled'] ?? 1),
                'webhook_events' => $instance['webhook_events_list'] ?? [],
                'receive_messages' => (int) ($instance['receive_messages'] ?? 1),
                'ignore_groups' => (int) ($instance['ignore_groups'] ?? 1),
                'ignore_status' => (int) ($instance['ignore_status'] ?? 1),
                'ignore_broadcast' => (int) ($instance['ignore_broadcast'] ?? 1),
                'ignore_newsletters' => (int) ($instance['ignore_newsletters'] ?? 1),
                'ignore_from_me' => (int) ($instance['ignore_from_me'] ?? 0),
                'reject_calls' => (int) ($instance['reject_calls'] ?? 0),
                'reject_call_message' => (string) ($instance['reject_call_message'] ?? ''),
                'always_online' => (int) ($instance['always_online'] ?? 0),
                'read_messages' => (int) ($instance['read_messages'] ?? 0),
                'read_status' => (int) ($instance['read_status'] ?? 0),
                'sync_full_history' => (int) ($instance['sync_full_history'] ?? 0),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            ?>
            <article class="admin-record-card" data-admin-card data-instance-status-card data-status-endpoint="<?= View::e(Router::url('/instances/status-feed')) ?>" data-instance-id="<?= (int) $instance['id'] ?>" data-search="<?= View::e($searchText) ?>" data-status="<?= View::e((string) $instance['status']) ?>">
                <div class="admin-record-main">
                    <span class="admin-record-mark is-whatsapp" aria-hidden="true">WA</span>
                    <div class="admin-record-copy">
                        <div class="admin-record-title-row">
                            <div><h3><?= View::e($instance['name']) ?></h3><p><?= $isSuperAdmin ? View::e($instance['tenant_name']) . ' · ' : '' ?><?= View::e($instance['instance_name']) ?></p></div>
                            <div class="admin-record-badges"><span class="badge badge-<?= View::e($instance['status']) ?>" data-instance-status-badge><?= View::e($statusLabels[$instance['status']] ?? ucfirst((string) $instance['status'])) ?></span><span class="badge <?= ($instance['management_mode'] ?? 'external') === 'managed' ? 'badge-success' : '' ?>"><?= ($instance['management_mode'] ?? 'external') === 'managed' ? 'Gerenciada pelo sistema' : 'Instância externa' ?></span><?php if ((int) $instance['is_default'] === 1): ?><span class="badge">Padrão</span><?php endif; ?></div>
                        </div>
                        <?php if ($isSuperAdmin): ?><small class="admin-record-muted"><?= View::e($instance['base_url']) ?></small><?php endif; ?><small class="admin-record-muted" data-instance-status-detail><?= View::e((string) (($instance['connection_state'] ?? '') ?: 'Aguardando atualização')) ?></small>
                    </div>
                </div>
                <dl class="admin-record-metrics">
                    <div><dt>Assistentes</dt><dd><?= (int) $instance['agents_count'] ?></dd></div>
                    <div><dt>Contatos</dt><dd><?= (int) $instance['contacts_count'] ?></dd></div>
                    <div><dt>Conversas</dt><dd><?= (int) $instance['conversations_count'] ?></dd></div>
                    <div><dt>Campanhas</dt><dd><?= (int) $instance['campaigns_count'] ?></dd></div>
                </dl>
                <details class="admin-inline-details"><summary><?= $isSuperAdmin ? 'Webhook e informações técnicas' : 'Regras de recebimento' ?></summary><div class="admin-technical-copy"><strong><?= $isSuperAdmin ? 'Webhook da instância' : 'Webhook administrado automaticamente' ?></strong><?php if ($isSuperAdmin): ?><code><?= View::e($webhookUrl) ?></code><?php endif; ?><small><?= (int) ($instance['webhook_enabled'] ?? 1) === 1 ? 'Ativo · ' . count($instance['webhook_events_list'] ?? []) . ' evento(s) selecionado(s).' : 'Webhook desativado para esta conexão.' ?></small><small>Grupos: <?= (int) ($instance['ignore_groups'] ?? 1) === 1 ? 'ignorados' : 'recebidos' ?> · Chamadas: <?= (int) ($instance['reject_calls'] ?? 0) === 1 ? 'rejeitadas' : 'permitidas' ?> · Histórico completo: <?= (int) ($instance['sync_full_history'] ?? 0) === 1 ? 'sim' : 'não' ?></small><?php if (!$isSuperAdmin): ?><small>A URL e a chave da Evolution permanecem protegidas no servidor do RS Connect.</small><?php endif; ?></div></details>
                <?php
                $bindings = $routingByInstance[(int) $instance['id']] ?? [];
                $allAgents = array_values(array_filter($adminAgents, static fn (array $agent): bool => (int) ($agent['tenant_id'] ?? 0) === (int) $instance['tenant_id']));
                $canRoute = Auth::can('agents.manage');
                require __DIR__ . '/_routing.php';
                ?>
                <div class="admin-record-actions instance-management-actions">
                    <form method="post" action="<?= View::e(Router::url('/instances/qr')) ?>" data-qr-code-form <?= $instance['status'] === 'connected' ? 'hidden' : '' ?>><?= Csrf::input() ?><input type="hidden" name="instance_id" value="<?= (int) $instance['id'] ?>"><button class="btn btn-small btn-primary" type="submit" data-qr-code-button>Gerar QR Code</button></form>
                    <button class="btn btn-small btn-outline" type="button" data-toggle-panel="instance-settings-drawer" data-instance-settings="<?= View::e($settingsData) ?>">Configurar Evolution</button>
                    <form method="post" action="<?= View::e(Router::url('/instances/action')) ?>"><?= Csrf::input() ?><input type="hidden" name="instance_id" value="<?= (int) $instance['id'] ?>"><input type="hidden" name="action" value="sync"><button class="btn btn-small btn-outline" type="submit">Sincronizar</button></form>
                    <form method="post" action="<?= View::e(Router::url('/instances/action')) ?>" onsubmit="return confirm('Reiniciar esta instância na Evolution?');"><?= Csrf::input() ?><input type="hidden" name="instance_id" value="<?= (int) $instance['id'] ?>"><input type="hidden" name="action" value="restart"><button class="btn btn-small btn-outline" type="submit">Reiniciar</button></form>
                    <form method="post" action="<?= View::e(Router::url('/instances/action')) ?>" onsubmit="return confirm('Desconectar o WhatsApp desta instância? Será necessário ler um novo QR Code.');"><?= Csrf::input() ?><input type="hidden" name="instance_id" value="<?= (int) $instance['id'] ?>"><input type="hidden" name="action" value="logout"><button class="btn btn-small btn-danger-soft" type="submit">Desconectar</button></form>
                    <button class="btn btn-small btn-outline" type="button" data-toggle-panel="instance-drawer" data-instance-open="edit"
                        data-id="<?= (int) $instance['id'] ?>" data-name="<?= View::e($instance['name']) ?>" data-instance-name="<?= View::e($instance['instance_name']) ?>" data-base-url="<?= $isSuperAdmin ? View::e($instance['base_url']) : '' ?>" data-status="<?= View::e($instance['status']) ?>" data-is-default="<?= (int) $instance['is_default'] ?>" data-management-mode="<?= View::e((string) ($instance['management_mode'] ?? 'external')) ?>">Editar cadastro</button>
                    <button class="btn btn-small btn-danger-soft" type="button" data-toggle-panel="instance-delete-drawer" data-instance-delete
                        data-id="<?= (int) $instance['id'] ?>" data-name="<?= View::e($instance['name']) ?>" data-instance-name="<?= View::e($instance['instance_name']) ?>" data-tenant-id="<?= (int) $instance['tenant_id'] ?>" data-management-mode="<?= View::e((string) ($instance['management_mode'] ?? 'external')) ?>">Excluir</button>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$instances): ?><div class="empty-state admin-filter-empty">Nenhuma conexão cadastrada.</div><?php endif; ?>
        <div class="empty-state admin-filter-empty" data-admin-filter-empty hidden>Nenhuma conexão corresponde aos filtros.</div>
    </div>
</section>

<?php if ($isSuperAdmin): ?>
<section class="card admin-agent-recovery admin-secondary-panel">
    <div class="section-heading"><div><span class="eyebrow">Recuperação técnica (legado)</span><h2>Compatibilidade de assistentes e conexões</h2><p>Use apenas para recuperar associações antigas após recriar uma conexão. O roteamento normal agora é feito em cada Canal WhatsApp.</p></div><span class="badge"><?= count($adminAgents) ?> assistente(s)</span></div>
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
<?php endif; ?>

<aside class="conversation-details conversation-drawer admin-form-drawer" id="instance-drawer" aria-label="Configurar conexão WhatsApp" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header">
        <div><span class="eyebrow" data-instance-drawer-eyebrow>Nova conexão</span><h2 data-instance-drawer-title>Criar WhatsApp</h2><p data-instance-drawer-description>Crie a instância na Evolution e conecte o número sem sair do RS Connect.</p></div>
        <button class="icon-button drawer-close" type="button" data-close-panel="instance-drawer" aria-label="Fechar">×</button>
    </div>
    <div class="conversation-drawer-body">
        <form class="drawer-form" method="post" action="<?= View::e(Router::url('/instances')) ?>" data-instance-form data-create-action="<?= View::e(Router::url('/instances')) ?>" data-update-action="<?= View::e(Router::url('/instances/update')) ?>">
            <?= Csrf::input() ?><input type="hidden" name="instance_id" value="0" data-instance-field="id">
            <section class="drawer-section">
                <div class="drawer-section-title"><div><span class="eyebrow">1. Cliente</span><h3>Empresa e identificação</h3></div></div>
                <div class="drawer-form-grid">
                    <?php if ($isSuperAdmin): ?>
                        <label class="field drawer-span" data-instance-tenant-field><span>Empresa</span><select name="tenant_id" data-instance-field="tenant_id" required><option value="">Selecione</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>"><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select></label>
                    <?php else: ?>
                        <div hidden data-instance-tenant-field><input type="hidden" name="tenant_id" value="<?= (int) Auth::tenantId() ?>" data-instance-field="tenant_id"></div>
                    <?php endif; ?>
                    <label class="field"><span>Nome interno</span><input name="name" data-instance-field="name" placeholder="WhatsApp Comercial" required></label>
                    <label class="field"><span>Identificador na Evolution</span><input name="instance_name" data-instance-field="instance_name" placeholder="cliente-comercial" pattern="[A-Za-z0-9._-]{2,120}" required><small class="field-hint">Sem espaços; use letras, números, hífen ou sublinhado.</small></label>
                </div>
            </section>
            <section class="drawer-section" data-instance-create-only>
                <div class="drawer-section-title"><div><span class="eyebrow">2. Provisionamento</span><h3><?= $isSuperAdmin ? 'Criar ou vincular na Evolution' : 'Criação automática da conexão' ?></h3></div></div>
                <div class="drawer-form-grid">
                    <?php if ($isSuperAdmin): ?>
                        <label class="check-field drawer-check drawer-span"><input type="checkbox" name="create_in_evolution" value="1" checked data-instance-field="create_in_evolution"><span><strong>Criar automaticamente na Evolution</strong><small>Desmarque somente para vincular uma instância que já existe.</small></span></label>
                        <label class="field"><span>Integração</span><select name="integration" data-instance-field="integration"><option value="WHATSAPP-BAILEYS">WhatsApp via QR Code (Baileys)</option><option value="WHATSAPP-BUSINESS">WhatsApp Business Cloud</option></select></label>
                    <?php else: ?>
                        <input type="hidden" name="create_in_evolution" value="1" data-instance-field="create_in_evolution">
                        <input type="hidden" name="integration" value="WHATSAPP-BAILEYS" data-instance-field="integration">
                        <div class="drawer-span admin-danger-message is-info"><strong>Conexão independente</strong><span>O RS Connect criará a instância, aplicará o webhook e abrirá o QR Code. As credenciais da Evolution permanecem protegidas.</span></div>
                    <?php endif; ?>
                    <label class="field"><span>Número com DDI — opcional</span><input name="phone_number" inputmode="numeric" placeholder="5532999999999"></label>
                </div>
            </section>
            <?php if ($isSuperAdmin): ?>
                <section class="drawer-section">
                    <div class="drawer-section-title"><div><span class="eyebrow">3. Servidor</span><h3>Acesso protegido à Evolution</h3></div></div>
                    <div class="drawer-form-grid">
                        <label class="field drawer-span"><span>URL base</span><input type="url" name="base_url" data-instance-field="base_url" value="<?= View::e($defaultUrl) ?>" placeholder="https://evolution.seudominio.com" required></label>
                        <label class="field drawer-span"><span data-instance-api-label>API Key global</span><input type="password" name="api_key" data-instance-field="api_key" placeholder="Use a chave do .env ou informe outra"><small class="field-hint" data-instance-api-hint>Se EVOLUTION_DEFAULT_API_KEY estiver configurada, este campo pode ficar vazio.</small></label>
                        <label class="check-field drawer-check"><input type="checkbox" name="is_default" value="1" data-instance-field="is_default"><span>Definir como conexão padrão</span></label>
                    </div>
                </section>
            <?php else: ?>
                <input type="hidden" name="base_url" value="" data-instance-field="base_url">
                <input type="hidden" name="api_key" value="" data-instance-field="api_key">
                <section class="drawer-section">
                    <div class="drawer-section-title"><div><span class="eyebrow">3. Preferência</span><h3>Canal principal da empresa</h3></div></div>
                    <label class="check-field drawer-check"><input type="checkbox" name="is_default" value="1" data-instance-field="is_default"><span><strong>Definir como conexão padrão</strong><small>Novas operações usarão este canal quando nenhuma conexão específica for escolhida.</small></span></label>
                </section>
            <?php endif; ?>
            <section class="drawer-section" data-instance-create-only>
                <div class="drawer-section-title"><div><span class="eyebrow">4. Comportamento inicial</span><h3>Mensagens, grupos e chamadas</h3></div></div>
                <div class="instance-option-grid">
                    <label class="check-field"><input type="checkbox" name="webhook_enabled" value="1" checked><span>Ativar webhook do RS Connect</span></label>
                    <label class="check-field"><input type="checkbox" name="receive_messages" value="1" checked><span>Receber novas mensagens</span></label>
                    <label class="check-field"><input type="checkbox" name="ignore_groups" value="1" checked><span>Ignorar grupos</span></label>
                    <label class="check-field"><input type="checkbox" name="ignore_status" value="1" checked><span>Ignorar status</span></label>
                    <label class="check-field"><input type="checkbox" name="ignore_broadcast" value="1" checked><span>Ignorar listas de transmissão</span></label>
                    <label class="check-field"><input type="checkbox" name="ignore_newsletters" value="1" checked><span>Ignorar canais/newsletters</span></label>
                    <label class="check-field"><input type="checkbox" name="ignore_from_me" value="1"><span>Ignorar mensagens enviadas pelo próprio número</span></label>
                    <label class="check-field"><input type="checkbox" name="reject_calls" value="1"><span>Rejeitar chamadas</span></label>
                    <label class="check-field"><input type="checkbox" name="always_online" value="1"><span>Manter sempre online</span></label>
                    <label class="check-field"><input type="checkbox" name="read_messages" value="1"><span>Marcar mensagens como lidas</span></label>
                    <label class="check-field"><input type="checkbox" name="read_status" value="1"><span>Marcar status como visualizado</span></label>
                    <label class="check-field"><input type="checkbox" name="sync_full_history" value="1"><span>Sincronizar histórico completo</span></label>
                </div>
                <label class="field"><span>Mensagem ao rejeitar chamadas</span><input name="reject_call_message" value="Este número não recebe chamadas. Envie uma mensagem por WhatsApp."></label>
            </section>
            <section class="drawer-section" data-instance-create-only>
                <div class="drawer-section-title"><div><span class="eyebrow">5. Eventos</span><h3>O que a Evolution enviará ao RS Connect</h3></div></div>
                <div class="instance-event-grid">
                    <?php foreach ($allowedWebhookEvents as $event): ?>
                        <label class="check-field"><input type="checkbox" name="webhook_events[]" value="<?= View::e($event) ?>" <?= in_array($event, ['MESSAGES_UPSERT','MESSAGES_UPDATE','CONNECTION_UPDATE','QRCODE_UPDATED','CONTACTS_UPSERT'], true) ? 'checked' : '' ?>><span><?= View::e($eventLabels[$event] ?? $event) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </section>
            <div class="drawer-savebar"><button class="btn btn-quiet" type="button" data-close-panel="instance-drawer">Cancelar</button><button class="btn btn-primary" type="submit" data-instance-submit>Criar conexão</button></div>
        </form>
    </div>
</aside>

<aside class="conversation-details conversation-drawer admin-form-drawer" id="instance-settings-drawer" aria-label="Configurações da Evolution" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header">
        <div><span class="eyebrow">Evolution API</span><h2 data-instance-settings-title>Configurar conexão</h2><p>As alterações são aplicadas imediatamente na Evolution e também controlam o que o webhook aceita no RS Connect.</p></div>
        <button class="icon-button drawer-close" type="button" data-close-panel="instance-settings-drawer" aria-label="Fechar">×</button>
    </div>
    <div class="conversation-drawer-body">
        <form class="drawer-form" method="post" action="<?= View::e(Router::url('/instances/settings')) ?>" data-instance-settings-form>
            <?= Csrf::input() ?><input type="hidden" name="instance_id" data-instance-settings-field="id">
            <section class="drawer-section">
                <div class="drawer-section-title"><div><span class="eyebrow">Recebimento</span><h3>Filtros das mensagens</h3></div></div>
                <div class="instance-option-grid">
                    <label class="check-field"><input type="checkbox" name="receive_messages" value="1" data-instance-settings-field="receive_messages"><span>Receber novas mensagens</span></label>
                    <label class="check-field"><input type="checkbox" name="ignore_groups" value="1" data-instance-settings-field="ignore_groups"><span>Ignorar grupos</span></label>
                    <label class="check-field"><input type="checkbox" name="ignore_status" value="1" data-instance-settings-field="ignore_status"><span>Ignorar status</span></label>
                    <label class="check-field"><input type="checkbox" name="ignore_broadcast" value="1" data-instance-settings-field="ignore_broadcast"><span>Ignorar listas de transmissão</span></label>
                    <label class="check-field"><input type="checkbox" name="ignore_newsletters" value="1" data-instance-settings-field="ignore_newsletters"><span>Ignorar canais/newsletters</span></label>
                    <label class="check-field"><input type="checkbox" name="ignore_from_me" value="1" data-instance-settings-field="ignore_from_me"><span>Ignorar mensagens do próprio número</span></label>
                </div>
            </section>
            <section class="drawer-section">
                <div class="drawer-section-title"><div><span class="eyebrow">WhatsApp</span><h3>Comportamento da sessão</h3></div></div>
                <div class="instance-option-grid">
                    <label class="check-field"><input type="checkbox" name="reject_calls" value="1" data-instance-settings-field="reject_calls"><span>Rejeitar chamadas</span></label>
                    <label class="check-field"><input type="checkbox" name="always_online" value="1" data-instance-settings-field="always_online"><span>Manter sempre online</span></label>
                    <label class="check-field"><input type="checkbox" name="read_messages" value="1" data-instance-settings-field="read_messages"><span>Marcar mensagens como lidas</span></label>
                    <label class="check-field"><input type="checkbox" name="read_status" value="1" data-instance-settings-field="read_status"><span>Visualizar status automaticamente</span></label>
                    <label class="check-field"><input type="checkbox" name="sync_full_history" value="1" data-instance-settings-field="sync_full_history"><span>Sincronizar histórico completo</span></label>
                </div>
                <label class="field"><span>Mensagem ao rejeitar chamadas</span><input name="reject_call_message" data-instance-settings-field="reject_call_message" placeholder="Este número não recebe chamadas."></label>
            </section>
            <section class="drawer-section">
                <div class="drawer-section-title"><div><span class="eyebrow">Webhook</span><h3>Eventos enviados ao RS Connect</h3></div></div>
                <label class="check-field instance-webhook-master"><input type="checkbox" name="webhook_enabled" value="1" data-instance-settings-field="webhook_enabled"><span><strong>Webhook ativo</strong><small>Desative somente para interromper completamente os eventos desta instância.</small></span></label>
                <div class="instance-event-grid">
                    <?php foreach ($allowedWebhookEvents as $event): ?>
                        <label class="check-field"><input type="checkbox" name="webhook_events[]" value="<?= View::e($event) ?>" data-instance-event="<?= View::e($event) ?>"><span><?= View::e($eventLabels[$event] ?? $event) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </section>
            <div class="drawer-savebar"><button class="btn btn-quiet" type="button" data-close-panel="instance-settings-drawer">Cancelar</button><button class="btn btn-primary" type="submit">Aplicar configurações</button></div>
        </form>
    </div>
</aside>

<aside class="conversation-details conversation-drawer admin-form-drawer" id="instance-test-drawer" aria-label="Testar conexão WhatsApp" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header"><div><span class="eyebrow">Validação</span><h2>Enviar mensagem de teste</h2><p><?= $isSuperAdmin ? 'Confirme se a conexão consegue enviar mensagens antes de liberar para o cliente.' : 'Confirme se o seu WhatsApp está enviando mensagens corretamente.' ?></p></div><button class="icon-button drawer-close" type="button" data-close-panel="instance-test-drawer" aria-label="Fechar">×</button></div>
    <div class="conversation-drawer-body"><form class="drawer-form" method="post" action="<?= View::e(Router::url('/instances/test')) ?>"><?= Csrf::input() ?><section class="drawer-section"><div class="drawer-form-grid"><label class="field drawer-span"><span>Conexão</span><select name="instance_id" required><option value="">Selecione</option><?php foreach ($instances as $instance): ?><option value="<?= (int) $instance['id'] ?>"><?= View::e($instance['name']) ?> — <?= View::e($instance['tenant_name']) ?></option><?php endforeach; ?></select></label><label class="field drawer-span"><span>Telefone com DDI</span><input name="phone" inputmode="numeric" placeholder="5511999999999" required></label><label class="field drawer-span"><span>Mensagem</span><textarea name="message" rows="5" required>Teste de integração do RS Connect.</textarea></label></div></section><div class="drawer-savebar"><button class="btn btn-quiet" type="button" data-close-panel="instance-test-drawer">Cancelar</button><button class="btn btn-primary" type="submit" <?= !$instances ? 'disabled' : '' ?>>Enviar teste</button></div></form></div>
</aside>

<aside class="conversation-details conversation-drawer admin-form-drawer" id="instance-delete-drawer" aria-label="Excluir conexão" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header"><div><span class="eyebrow text-danger">Ação restrita</span><h2>Excluir conexão</h2><p data-instance-delete-description>Remova o cadastro do RS Connect e escolha se a instância também deve ser apagada na Evolution.</p></div><button class="icon-button drawer-close" type="button" data-close-panel="instance-delete-drawer" aria-label="Fechar">×</button></div>
    <div class="conversation-drawer-body">
        <form class="drawer-form" method="post" action="<?= View::e(Router::url('/instances/delete')) ?>" data-instance-delete-form onsubmit="return confirm('Confirma a exclusão desta conexão?');">
            <?= Csrf::input() ?><input type="hidden" name="instance_id" data-instance-delete-field="id">
            <section class="drawer-section danger-zone">
                <div class="drawer-form-grid">
                    <div class="drawer-span admin-danger-message"><strong data-instance-delete-name>Conexão</strong><span>Selecione uma substituta quando existirem assistentes, contatos, conversas ou campanhas vinculadas.</span></div>
                    <label class="field drawer-span"><span>Migrar vínculos para</span><select name="replacement_instance_id" data-instance-delete-field="replacement"><option value="">Nenhuma — somente se não houver vínculos</option><?php foreach ($instances as $replacement): ?><option value="<?= (int) $replacement['id'] ?>" data-tenant-id="<?= (int) $replacement['tenant_id'] ?>"><?= View::e($replacement['name']) ?> — <?= View::e($replacement['tenant_name']) ?></option><?php endforeach; ?></select></label>
                    <label class="check-field drawer-check drawer-span" data-instance-delete-remote-row><input type="checkbox" name="delete_remote" value="1" data-instance-delete-field="delete_remote"><span><strong>Excluir também na Evolution API</strong><small>Esta ação remove a instância remota e exige uma nova criação para reconectar o número.</small></span></label>
                    <label class="field drawer-span"><span>Confirmação</span><input name="confirmation" autocomplete="off" data-instance-delete-field="confirmation" required><small class="field-hint" data-instance-delete-hint></small></label>
                </div>
            </section>
            <div class="drawer-savebar"><button class="btn btn-quiet" type="button" data-close-panel="instance-delete-drawer">Cancelar</button><button class="btn btn-danger" type="submit">Excluir conexão</button></div>
        </form>
    </div>
</aside>

<?php if ($canGenerateQr): ?>
<div class="qr-connection-modal" data-qr-code-modal hidden aria-hidden="true"><button class="qr-modal-backdrop" type="button" data-close-qr-modal aria-label="Fechar QR Code"></button><section class="qr-modal-card" role="dialog" aria-modal="true" aria-labelledby="qr-modal-title"><div class="qr-modal-header"><div><span class="eyebrow">Conectar WhatsApp</span><h2 id="qr-modal-title">Escaneie o QR Code</h2></div><button class="icon-button" type="button" data-close-qr-modal aria-label="Fechar">×</button></div><div class="qr-modal-body"><div class="qr-loading" data-qr-loading>Gerando QR Code com segurança...</div><img data-qr-image alt="QR Code para conectar o WhatsApp" hidden><p data-qr-message>Abra o WhatsApp no celular, toque em <strong>Dispositivos conectados</strong> e depois em <strong>Conectar dispositivo</strong>.</p><div class="qr-error-message" data-qr-error hidden></div></div><div class="qr-modal-actions"><button class="btn btn-quiet" type="button" data-close-qr-modal>Fechar</button></div></section></div>
<?php endif; ?>
