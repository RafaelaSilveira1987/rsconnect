<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Router;
use App\Core\View;

$canManage = Auth::can('instances.manage');
?>
<div class="content-grid <?= $canManage ? 'instances-layout' : '' ?>">
    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Evolution API</span><h2>Instâncias cadastradas</h2></div>
            <span class="badge"><?= count($instances) ?> registro(s)</span>
        </div>

        <div class="instance-list">
            <?php foreach ($instances as $instance): ?>
                <article class="instance-item">
                    <div class="instance-main">
                        <span class="instance-icon">◉</span>
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
