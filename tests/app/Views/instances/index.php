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

<aside class="conversation-details conversation-drawer admin-form-drawer instance-create-drawer" id="instance-drawer" aria-label="Configurar conexão WhatsApp" aria-modal="true" role="dialog">
    <svg class="instance-icon-sprite" aria-hidden="true" focusable="false">
        <symbol id="instance-icon-whatsapp" viewBox="0 0 24 24"><path d="M20.5 11.8a8.4 8.4 0 0 1-12.4 7.4L3 20.5l1.4-4.9A8.4 8.4 0 1 1 20.5 11.8Z"/><path d="M8.1 7.6c.2-.5.5-.5.8-.5h.6c.2 0 .4.1.5.4l.8 1.9c.1.3.1.5-.1.7l-.7.8c-.2.2-.1.4 0 .6.7 1.3 1.7 2.3 3 3 .2.1.4.2.6 0l.9-1c.2-.2.4-.3.7-.2l1.9.9c.3.1.4.3.4.5 0 .5-.2 1.5-1 2.1-.6.5-1.4.8-2.3.6-1.5-.3-3.5-1.1-5.4-2.8-1.5-1.4-2.6-3.1-3-4.6-.2-.8.1-1.8.7-2.4Z"/></symbol>
        <symbol id="instance-icon-id" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.2"/><path d="M5.8 16c.8-1.7 1.9-2.6 3.2-2.6s2.4.9 3.2 2.6M14.5 9h4M14.5 13h4"/></symbol>
        <symbol id="instance-icon-cloud" viewBox="0 0 24 24"><path d="M7.5 18.5h10a4 4 0 0 0 .5-8 6 6 0 0 0-11.5-1.8A4.8 4.8 0 0 0 7.5 18.5Z"/><path d="M12 9.5v6M9.5 12l2.5-2.5 2.5 2.5"/></symbol>
        <symbol id="instance-icon-server" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/><path d="M7 7h.01M7 17h.01M11 7h7M11 17h7"/></symbol>
        <symbol id="instance-icon-star" viewBox="0 0 24 24"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9L12 3Z"/></symbol>
        <symbol id="instance-icon-webhook" viewBox="0 0 24 24"><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="7" r="2.5"/><circle cx="12" cy="17" r="2.5"/><path d="M9.2 8.2 11 14M14.8 8.2 13 14M9.5 17h-3a3.5 3.5 0 0 1-3.5-3.5V12"/></symbol>
        <symbol id="instance-icon-message" viewBox="0 0 24 24"><path d="M4 5.5h16v11H9l-5 3v-14Z"/><path d="M8 9h8M8 13h5"/></symbol>
        <symbol id="instance-icon-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3.5 19c.7-3.3 2.5-5 5.5-5s4.8 1.7 5.5 5M14.5 14.5c2.9-.5 5 .9 6 4.5"/></symbol>
        <symbol id="instance-icon-status" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2M4 4l16 16"/></symbol>
        <symbol id="instance-icon-broadcast" viewBox="0 0 24 24"><circle cx="12" cy="12" r="2"/><path d="M8.5 8.5a5 5 0 0 0 0 7M15.5 8.5a5 5 0 0 1 0 7M5.5 5.5a9 9 0 0 0 0 13M18.5 5.5a9 9 0 0 1 0 13"/></symbol>
        <symbol id="instance-icon-news" viewBox="0 0 24 24"><path d="M5 4h13v16H6.5A2.5 2.5 0 0 1 4 17.5V5a1 1 0 0 1 1-1Z"/><path d="M18 7h2v10.5a2.5 2.5 0 0 1-2.5 2.5M8 8h6M8 12h7M8 16h4"/></symbol>
        <symbol id="instance-icon-send" viewBox="0 0 24 24"><path d="m3 11 18-8-8 18-2-8-8-2Z"/><path d="m11 13 5-5"/></symbol>
        <symbol id="instance-icon-phone-off" viewBox="0 0 24 24"><path d="M6.6 3.8 9 7.5 7.5 9c1 2.5 3 4.5 5.5 5.5l1.5-1.5 3.7 2.4c.5.3.7.9.5 1.5-.5 1.7-2 2.7-3.8 2.4-5.5-.9-9.8-5.2-10.7-10.7-.3-1.8.7-3.3 2.4-3.8Z"/><path d="M4 4l16 16"/></symbol>
        <symbol id="instance-icon-wifi" viewBox="0 0 24 24"><path d="M4 9a12 12 0 0 1 16 0M7 12.5a7.5 7.5 0 0 1 10 0M10 16a3 3 0 0 1 4 0"/><circle cx="12" cy="19" r="1"/></symbol>
        <symbol id="instance-icon-checks" viewBox="0 0 24 24"><path d="m3 12 4 4 7-8M10 16l2 2 9-10"/></symbol>
        <symbol id="instance-icon-eye" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></symbol>
        <symbol id="instance-icon-history" viewBox="0 0 24 24"><path d="M4 12a8 8 0 1 0 2.3-5.7L4 8.5"/><path d="M4 4v4.5h4.5M12 8v4l3 2"/></symbol>
        <symbol id="instance-icon-events" viewBox="0 0 24 24"><path d="M3 12h4l2-6 4 12 2-6h6"/></symbol>
        <symbol id="instance-icon-chevron" viewBox="0 0 24 24"><path d="m8 10 4 4 4-4"/></symbol>
        <symbol id="instance-icon-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
        <symbol id="instance-icon-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    </svg>

    <div class="conversation-drawer-header instance-create-header">
        <div class="instance-create-heading">
            <span class="instance-create-logo" aria-hidden="true"><svg><use href="#instance-icon-whatsapp"></use></svg></span>
            <div>
                <span class="eyebrow" data-instance-drawer-eyebrow>Nova conexão</span>
                <h2 data-instance-drawer-title>Criar WhatsApp</h2>
                <p data-instance-drawer-description>Crie a instância na Evolution e conecte o número sem sair do RS Connect.</p>
            </div>
        </div>
        <button class="icon-button drawer-close instance-create-close" type="button" data-close-panel="instance-drawer" aria-label="Fechar">×</button>
    </div>
    <div class="conversation-drawer-body instance-create-body">
        <form class="drawer-form instance-create-form" method="post" action="<?= View::e(Router::url('/instances')) ?>" data-instance-form data-create-action="<?= View::e(Router::url('/instances')) ?>" data-update-action="<?= View::e(Router::url('/instances/update')) ?>">
            <?= Csrf::input() ?><input type="hidden" name="instance_id" value="0" data-instance-field="id">

            <section class="drawer-section instance-create-section">
                <div class="drawer-section-title instance-step-title">
                    <span class="instance-step-number">1</span>
                    <span class="instance-step-icon" aria-hidden="true"><svg><use href="#instance-icon-id"></use></svg></span>
                    <div><span class="eyebrow">Identificação</span><h3>Empresa e conexão</h3><p>Use nomes claros para localizar este canal depois.</p></div>
                </div>
                <div class="drawer-form-grid instance-form-grid">
                    <?php if ($isSuperAdmin): ?>
                        <label class="field drawer-span" data-instance-tenant-field><span>Empresa</span><select name="tenant_id" data-instance-field="tenant_id" required><option value="">Selecione</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>"><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select></label>
                    <?php else: ?>
                        <div hidden data-instance-tenant-field><input type="hidden" name="tenant_id" value="<?= (int) Auth::tenantId() ?>" data-instance-field="tenant_id"></div>
                    <?php endif; ?>
                    <label class="field"><span>Nome interno</span><input name="name" data-instance-field="name" placeholder="Ex.: WhatsApp Comercial" autocomplete="off" required><small class="field-hint">Nome que aparecerá para sua equipe.</small></label>
                    <label class="field"><span>Identificador na Evolution</span><input name="instance_name" data-instance-field="instance_name" placeholder="Ex.: empresa-comercial" pattern="[A-Za-z0-9._-]{2,120}" autocomplete="off" required><small class="field-hint">Sem espaços; use letras, números, hífen ou sublinhado.</small></label>
                </div>
            </section>

            <section class="drawer-section instance-create-section" data-instance-create-only>
                <div class="drawer-section-title instance-step-title">
                    <span class="instance-step-number">2</span>
                    <span class="instance-step-icon" aria-hidden="true"><svg><use href="#instance-icon-cloud"></use></svg></span>
                    <div><span class="eyebrow">Provisionamento</span><h3><?= $isSuperAdmin ? 'Criar ou vincular na Evolution' : 'Criação automática da conexão' ?></h3><p>O RS Connect prepara a instância e abre o QR Code para conexão.</p></div>
                </div>
                <div class="drawer-form-grid instance-form-grid">
                    <?php if ($isSuperAdmin): ?>
                        <label class="instance-choice-card instance-choice-featured drawer-span">
                            <input type="checkbox" name="create_in_evolution" value="1" checked data-instance-field="create_in_evolution">
                            <span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-cloud"></use></svg></span><span class="instance-choice-copy"><strong>Criar automaticamente na Evolution</strong><small>Desmarque somente para vincular uma instância que já existe.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span>
                        </label>
                        <label class="field"><span>Integração</span><select name="integration" data-instance-field="integration"><option value="WHATSAPP-BAILEYS">WhatsApp via QR Code (Baileys)</option><option value="WHATSAPP-BUSINESS">WhatsApp Business Cloud</option></select></label>
                    <?php else: ?>
                        <input type="hidden" name="create_in_evolution" value="1" data-instance-field="create_in_evolution">
                        <input type="hidden" name="integration" value="WHATSAPP-BAILEYS" data-instance-field="integration">
                        <div class="drawer-span instance-provision-note"><span class="instance-provision-note-icon"><svg><use href="#instance-icon-cloud"></use></svg></span><span><strong>Conexão independente</strong><small>O RS Connect criará a instância, aplicará o webhook e abrirá o QR Code. As credenciais da Evolution permanecem protegidas.</small></span></div>
                    <?php endif; ?>
                    <label class="field"><span>Número com DDI — opcional</span><input name="phone_number" inputmode="numeric" placeholder="Ex.: 5532999999999" autocomplete="tel"><small class="field-hint">Pode ser informado depois da leitura do QR Code.</small></label>
                </div>
            </section>

            <?php if ($isSuperAdmin): ?>
                <section class="drawer-section instance-create-section">
                    <div class="drawer-section-title instance-step-title">
                        <span class="instance-step-number">3</span>
                        <span class="instance-step-icon" aria-hidden="true"><svg><use href="#instance-icon-server"></use></svg></span>
                        <div><span class="eyebrow">Servidor</span><h3>Acesso protegido à Evolution</h3><p>Credenciais técnicas ficam visíveis somente no suporte RS Connect.</p></div>
                    </div>
                    <div class="drawer-form-grid instance-form-grid">
                        <label class="field drawer-span"><span>URL base</span><input type="url" name="base_url" data-instance-field="base_url" value="<?= View::e($defaultUrl) ?>" placeholder="https://evolution.seudominio.com" required></label>
                        <label class="field drawer-span"><span data-instance-api-label>API Key global</span><input type="password" name="api_key" data-instance-field="api_key" placeholder="Use a chave do .env ou informe outra" autocomplete="new-password"><small class="field-hint" data-instance-api-hint>Se EVOLUTION_DEFAULT_API_KEY estiver configurada, este campo pode ficar vazio.</small></label>
                        <label class="instance-choice-card instance-choice-featured drawer-span">
                            <input type="checkbox" name="is_default" value="1" data-instance-field="is_default">
                            <span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-star"></use></svg></span><span class="instance-choice-copy"><strong>Definir como conexão padrão</strong><small>Novas operações usarão este canal quando nenhuma conexão específica for escolhida.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span>
                        </label>
                    </div>
                </section>
            <?php else: ?>
                <input type="hidden" name="base_url" value="" data-instance-field="base_url">
                <input type="hidden" name="api_key" value="" data-instance-field="api_key">
                <section class="drawer-section instance-create-section instance-preference-section">
                    <div class="drawer-section-title instance-step-title">
                        <span class="instance-step-number">3</span>
                        <span class="instance-step-icon" aria-hidden="true"><svg><use href="#instance-icon-star"></use></svg></span>
                        <div><span class="eyebrow">Preferência</span><h3>Canal principal da empresa</h3><p>Escolha se esta conexão será usada como padrão nas novas operações.</p></div>
                    </div>
                    <label class="instance-choice-card instance-choice-featured">
                        <input type="checkbox" name="is_default" value="1" data-instance-field="is_default">
                        <span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-star"></use></svg></span><span class="instance-choice-copy"><strong>Definir como conexão padrão</strong><small>Novas operações usarão este canal quando nenhuma conexão específica for escolhida.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span>
                    </label>
                </section>
            <?php endif; ?>

            <section class="drawer-section instance-create-section" data-instance-create-only>
                <div class="drawer-section-title instance-step-title">
                    <span class="instance-step-number">4</span>
                    <span class="instance-step-icon" aria-hidden="true"><svg><use href="#instance-icon-message"></use></svg></span>
                    <div><span class="eyebrow">Comportamento inicial</span><h3>Mensagens, grupos e chamadas</h3><p>As opções recomendadas já vêm ativadas para evitar ruído no atendimento.</p></div>
                </div>
                <div class="instance-option-grid instance-choice-grid">
                    <label class="instance-choice-card"><input type="checkbox" name="webhook_enabled" value="1" checked><span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-webhook"></use></svg></span><span class="instance-choice-copy"><strong>Ativar webhook do RS Connect</strong><small>Receba eventos e atualizações em tempo real.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span></label>
                    <label class="instance-choice-card"><input type="checkbox" name="receive_messages" value="1" checked><span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-message"></use></svg></span><span class="instance-choice-copy"><strong>Receber novas mensagens</strong><small>Crie conversas quando novas mensagens chegarem.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span></label>
                    <label class="instance-choice-card"><input type="checkbox" name="ignore_groups" value="1" checked><span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-users"></use></svg></span><span class="instance-choice-copy"><strong>Ignorar grupos</strong><small>Não transforme mensagens de grupos em atendimentos.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span></label>
                    <label class="instance-choice-card"><input type="checkbox" name="ignore_status" value="1" checked><span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-status"></use></svg></span><span class="instance-choice-copy"><strong>Ignorar status</strong><small>Descarte atualizações do status do WhatsApp.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span></label>
                    <label class="instance-choice-card"><input type="checkbox" name="ignore_broadcast" value="1" checked><span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-broadcast"></use></svg></span><span class="instance-choice-copy"><strong>Ignorar listas de transmissão</strong><small>Não processe mensagens enviadas por listas.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span></label>
                    <label class="instance-choice-card"><input type="checkbox" name="ignore_newsletters" value="1" checked><span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-news"></use></svg></span><span class="instance-choice-copy"><strong>Ignorar canais e newsletters</strong><small>Evite criar conversas para conteúdos informativos.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span></label>
                    <label class="instance-choice-card"><input type="checkbox" name="ignore_from_me" value="1"><span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-send"></use></svg></span><span class="instance-choice-copy"><strong>Ignorar mensagens próprias</strong><small>Evite duplicar mensagens enviadas pelo próprio número.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span></label>
                    <label class="instance-choice-card"><input type="checkbox" name="reject_calls" value="1" data-instance-reject-toggle><span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-phone-off"></use></svg></span><span class="instance-choice-copy"><strong>Rejeitar chamadas</strong><small>Recuse ligações e oriente o contato por mensagem.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span></label>
                    <label class="instance-choice-card"><input type="checkbox" name="always_online" value="1"><span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-wifi"></use></svg></span><span class="instance-choice-copy"><strong>Manter sempre online</strong><small>Mantenha a sessão disponível no WhatsApp.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span></label>
                    <label class="instance-choice-card"><input type="checkbox" name="read_messages" value="1"><span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-checks"></use></svg></span><span class="instance-choice-copy"><strong>Marcar mensagens como lidas</strong><small>Confirme automaticamente a leitura das mensagens.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span></label>
                    <label class="instance-choice-card"><input type="checkbox" name="read_status" value="1"><span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-eye"></use></svg></span><span class="instance-choice-copy"><strong>Visualizar status automaticamente</strong><small>Marque atualizações de status como visualizadas.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span></label>
                    <label class="instance-choice-card"><input type="checkbox" name="sync_full_history" value="1"><span class="instance-choice-ui"><span class="instance-choice-icon"><svg><use href="#instance-icon-history"></use></svg></span><span class="instance-choice-copy"><strong>Sincronizar histórico completo</strong><small>Importe conversas anteriores ao conectar o número.</small></span><span class="instance-choice-state"><svg><use href="#instance-icon-check"></use></svg></span></span></label>
                </div>
                <label class="field instance-dependent-field" data-instance-reject-message-wrap hidden><span>Mensagem ao rejeitar chamadas</span><input name="reject_call_message" value="Este número não recebe chamadas. Envie uma mensagem por WhatsApp." data-instance-reject-message disabled><small class="field-hint">Esta mensagem será enviada quando uma ligação for recusada.</small></label>
            </section>

            <details class="drawer-section instance-create-section instance-advanced-section" data-instance-create-only>
                <summary class="instance-advanced-summary">
                    <span class="instance-step-number">5</span>
                    <span class="instance-step-icon" aria-hidden="true"><svg><use href="#instance-icon-events"></use></svg></span>
                    <span class="instance-advanced-copy"><span class="eyebrow">Eventos avançados</span><strong>O que a Evolution enviará ao RS Connect</strong><small><span data-instance-event-count>5 eventos selecionados</span>. Os essenciais já estão configurados.</small></span>
                    <span class="instance-advanced-chevron" aria-hidden="true"><svg><use href="#instance-icon-chevron"></use></svg></span>
                </summary>
                <div class="instance-advanced-body">
                    <p>Altere esta seleção somente quando sua operação precisar de contatos, presença, grupos ou outros eventos específicos.</p>
                    <div class="instance-event-grid">
                        <?php foreach ($allowedWebhookEvents as $event): ?>
                            <label class="instance-event-card"><input type="checkbox" name="webhook_events[]" value="<?= View::e($event) ?>" <?= in_array($event, ['MESSAGES_UPSERT','MESSAGES_UPDATE','CONNECTION_UPDATE','QRCODE_UPDATED','CONTACTS_UPSERT','CONTACTS_UPDATE'], true) ? 'checked' : '' ?> data-instance-create-event><span><strong><?= View::e($eventLabels[$event] ?? $event) ?></strong><small><?= View::e($event) ?></small></span><span class="instance-event-check"><svg><use href="#instance-icon-check"></use></svg></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </details>

            <div class="drawer-savebar instance-create-savebar">
                <button class="btn btn-quiet" type="button" data-close-panel="instance-drawer">Cancelar</button>
                <button class="btn btn-primary instance-create-submit" type="submit" data-instance-submit><svg aria-hidden="true"><use href="#instance-icon-plus"></use></svg><span data-instance-submit-label>Criar conexão</span></button>
            </div>
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
