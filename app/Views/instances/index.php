<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Router;
use App\Core\View;

$canManage = Auth::can('instances.manage');
$statusLabel = static function (string $status, ?string $state = null): string {
    $state = strtolower(trim((string) $state));
    return match ($status) {
        'connected' => 'Conectada',
        'pending' => $state === 'qrcode' ? 'Aguardando QR Code' : 'Pendente',
        default => 'Desconectada',
    };
};
$statusHint = static function (array $instance): string {
    if (!empty($instance['connection_state'])) {
        return 'Estado Evolution: ' . $instance['connection_state'];
    }
    if (!empty($instance['last_status_check_at'])) {
        return 'Última consulta: ' . $instance['last_status_check_at'];
    }
    return 'Use Atualizar status para consultar a Evolution.';
};
?>
<div class="content-grid <?= $canManage ? 'instances-layout' : '' ?>">
    <section class="card instances-panel">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Evolution API</span>
                <h2>Instâncias cadastradas</h2>
                <p class="section-description">Cadastre a instância para a empresa e permita que o cliente leia o QR Code diretamente pelo painel.</p>
            </div>
            <span class="badge"><?= count($instances) ?> registro(s)</span>
        </div>

        <div class="instance-list instance-list-pro">
            <?php foreach ($instances as $instance): ?>
                <?php
                $webhookToken = trim((string) Env::get('EVOLUTION_WEBHOOK_TOKEN', ''));
                $webhookUrl = Router::url('/webhooks/evolution?instance_id=' . (int) $instance['id'] . ($webhookToken !== '' ? '&token=' . rawurlencode($webhookToken) : ''));
                $currentState = (string) ($instance['connection_state'] ?? '');
                ?>
                <article class="instance-item instance-card-pro" data-instance-card="<?= (int) $instance['id'] ?>">
                    <div class="instance-main">
                        <span class="instance-icon instance-icon-device" aria-hidden="true"></span>
                        <div>
                            <h3><?= View::e($instance['name']) ?></h3>
                            <p><?= View::e($instance['instance_name']) ?> · <?= View::e($instance['tenant_name']) ?></p>
                            <small><?= View::e($instance['base_url']) ?></small>
                        </div>
                    </div>

                    <div class="instance-meta instance-status-line">
                        <span class="badge badge-<?= View::e($instance['status']) ?>" data-instance-status><?= View::e($statusLabel((string) $instance['status'], $currentState)) ?></span>
                        <?php if ((int) $instance['is_default'] === 1): ?><span class="badge">Padrão</span><?php endif; ?>
                        <small data-instance-status-hint><?= View::e($statusHint($instance)) ?></small>
                    </div>

                    <div class="instance-actions">
                        <form method="post" action="<?= View::e(Router::url('/instances/status')) ?>" data-instance-status-form>
                            <?= Csrf::input() ?>
                            <input type="hidden" name="instance_id" value="<?= (int) $instance['id'] ?>">
                            <button class="btn btn-secondary" type="submit">Atualizar status</button>
                        </form>

                        <?php if ($canManage): ?>
                            <form method="post" action="<?= View::e(Router::url('/instances/qrcode')) ?>" data-instance-qr-form>
                                <?= Csrf::input() ?>
                                <input type="hidden" name="instance_id" value="<?= (int) $instance['id'] ?>">
                                <button class="btn btn-primary" type="submit">Gerar QR Code</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if ($canManage): ?>
                        <details class="webhook-details">
                            <summary>Webhook da instância</summary>
                            <div class="webhook-box">
                                <strong>Webhook para mensagens</strong>
                                <code><?= View::e($webhookUrl) ?></code>
                                <small><?= $webhookToken === '' ? 'Defina EVOLUTION_WEBHOOK_TOKEN no .env antes de usar este endereço.' : 'Configure este endereço na Evolution para o evento messages.upsert.' ?></small>
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

                <?php if (Auth::isSuperAdmin()): ?>
                    <label class="field"><span>Empresa</span><select name="tenant_id" required><option value="">Selecione</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>"><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select></label>
                <?php endif; ?>

                <label class="field"><span>Nome interno</span><input name="name" placeholder="WhatsApp Comercial" required></label>
                <label class="field"><span>Nome na Evolution</span><input name="instance_name" placeholder="rsconnect-comercial" required></label>
                <label class="field"><span>URL base</span><input type="url" name="base_url" value="<?= View::e($defaultUrl) ?>" placeholder="https://evolution.seudominio.com" required></label>
                <label class="field"><span>API Key</span><input type="password" name="api_key" placeholder="Chave global ou da instância" required></label>
                <label class="field"><span>Status inicial</span><select name="status"><option value="disconnected">Desconectada</option><option value="pending">Pendente</option><option value="connected">Conectada</option></select></label>
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

<div class="qr-modal" id="instanceQrModal" hidden>
    <div class="qr-modal-backdrop" data-close-qr></div>
    <section class="qr-modal-card" role="dialog" aria-modal="true" aria-labelledby="qrModalTitle">
        <header class="qr-modal-header">
            <div>
                <span class="eyebrow">Conexão WhatsApp</span>
                <h2 id="qrModalTitle">QR Code da instância</h2>
                <p data-qr-subtitle>Escaneie com o WhatsApp do cliente para conectar.</p>
            </div>
            <button class="btn icon-btn" type="button" data-close-qr aria-label="Fechar">×</button>
        </header>

        <div class="qr-modal-body">
            <div class="qr-image-box" data-qr-image-box>
                <div class="qr-loading">Aguardando geração do QR Code...</div>
            </div>

            <div class="qr-info-card">
                <strong>Status</strong>
                <p data-qr-status>Processando...</p>
            </div>

            <div class="qr-info-card" data-pairing-card hidden>
                <strong>Código de pareamento</strong>
                <code data-pairing-code></code>
                <small>Use este código apenas quando a Evolution retornar pareamento sem imagem do QR Code.</small>
            </div>

            <div class="qr-help">
                <strong>Como conectar</strong>
                <ol>
                    <li>Abra o WhatsApp no celular do cliente.</li>
                    <li>Acesse Aparelhos conectados.</li>
                    <li>Toque em Conectar aparelho.</li>
                    <li>Escaneie o QR Code exibido nesta tela.</li>
                </ol>
            </div>
        </div>

        <footer class="qr-modal-footer">
            <button class="btn" type="button" data-close-qr>Fechar</button>
            <button class="btn btn-secondary" type="button" data-refresh-current-status>Atualizar status</button>
        </footer>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('instanceQrModal');
    const imageBox = modal?.querySelector('[data-qr-image-box]');
    const statusText = modal?.querySelector('[data-qr-status]');
    const subtitle = modal?.querySelector('[data-qr-subtitle]');
    const pairingCard = modal?.querySelector('[data-pairing-card]');
    const pairingCode = modal?.querySelector('[data-pairing-code]');
    const refreshCurrent = modal?.querySelector('[data-refresh-current-status]');
    let currentStatusForm = null;

    const openModal = () => {
        if (!modal) return;
        modal.hidden = false;
        document.body.classList.add('modal-open');
    };

    const closeModal = () => {
        if (!modal) return;
        modal.hidden = true;
        document.body.classList.remove('modal-open');
    };

    modal?.querySelectorAll('[data-close-qr]').forEach((button) => button.addEventListener('click', closeModal));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal && !modal.hidden) closeModal();
    });

    const updateCardStatus = (form, instance) => {
        const card = form?.closest('[data-instance-card]');
        if (!card || !instance) return;
        const badge = card.querySelector('[data-instance-status]');
        const hint = card.querySelector('[data-instance-status-hint]');
        const status = instance.status || 'disconnected';
        const label = instance.label || (status === 'connected' ? 'Conectada' : (status === 'pending' ? 'Pendente' : 'Desconectada'));
        badge.className = `badge badge-${status}`;
        badge.textContent = label;
        if (hint) hint.textContent = instance.state ? `Estado Evolution: ${instance.state}` : 'Status atualizado agora.';
    };

    const postForm = async (form) => {
        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data) {
            throw new Error(data?.message || 'Não foi possível concluir a solicitação.');
        }
        return data;
    };

    document.querySelectorAll('[data-instance-status-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            const oldText = button?.textContent || '';
            if (button) { button.disabled = true; button.textContent = 'Consultando...'; }
            try {
                const data = await postForm(form);
                updateCardStatus(form, data.instance);
            } catch (error) {
                alert(error.message || 'Falha ao atualizar status.');
            } finally {
                if (button) { button.disabled = false; button.textContent = oldText; }
            }
        });
    });

    document.querySelectorAll('[data-instance-qr-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            currentStatusForm = form.closest('[data-instance-card]')?.querySelector('[data-instance-status-form]') || null;
            openModal();
            if (imageBox) imageBox.innerHTML = '<div class="qr-loading">Gerando QR Code na Evolution...</div>';
            if (statusText) statusText.textContent = 'Solicitando conexão...';
            if (subtitle) subtitle.textContent = 'Escaneie com o WhatsApp do cliente para conectar.';
            if (pairingCard) pairingCard.hidden = true;

            const button = form.querySelector('button[type="submit"]');
            const oldText = button?.textContent || '';
            if (button) { button.disabled = true; button.textContent = 'Gerando...'; }

            try {
                const data = await postForm(form);
                updateCardStatus(form, data.instance);

                const base64 = data.qr?.base64 || '';
                const code = data.qr?.pairing_code || '';

                if (base64 && imageBox) {
                    const src = base64.startsWith('data:image') ? base64 : `data:image/png;base64,${base64}`;
                    imageBox.innerHTML = `<img src="${src}" alt="QR Code da instância WhatsApp">`;
                } else if (imageBox) {
                    imageBox.innerHTML = '<div class="qr-empty">A Evolution não retornou imagem base64 para esta tentativa. Clique em Gerar QR Code novamente ou consulte a versão/configuração da Evolution.</div>';
                }

                if (code && pairingCard && pairingCode) {
                    pairingCode.textContent = code;
                    pairingCard.hidden = false;
                }

                if (statusText) statusText.textContent = data.message || 'QR Code solicitado.';
            } catch (error) {
                if (imageBox) imageBox.innerHTML = '<div class="qr-empty">Não foi possível gerar o QR Code.</div>';
                if (statusText) statusText.textContent = error.message || 'Falha ao gerar QR Code.';
            } finally {
                if (button) { button.disabled = false; button.textContent = oldText; }
            }
        });
    });

    refreshCurrent?.addEventListener('click', async () => {
        if (!currentStatusForm) return;
        refreshCurrent.disabled = true;
        refreshCurrent.textContent = 'Atualizando...';
        try {
            const data = await postForm(currentStatusForm);
            updateCardStatus(currentStatusForm, data.instance);
            if (statusText) statusText.textContent = data.instance?.label || data.message || 'Status atualizado.';
            if (data.instance?.status === 'connected') {
                if (subtitle) subtitle.textContent = 'Instância conectada com sucesso.';
            }
        } catch (error) {
            if (statusText) statusText.textContent = error.message || 'Falha ao atualizar status.';
        } finally {
            refreshCurrent.disabled = false;
            refreshCurrent.textContent = 'Atualizar status';
        }
    });
});
</script>
