<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
?>
<div class="login-grid login-grid-pro">
    <?php if (!empty($isPreview)): ?>
        <div class="preview-login-banner">Pré-visualização do white label. Esta tela não altera o login real do usuário logado.</div>
    <?php endif; ?>
    <section class="login-hero login-hero-pro">
        <span class="brand-large">
            <?php if (!empty($branding['logo_url'])): ?>
                <img class="login-brand-logo" src="<?= View::e($branding['logo_url']) ?>" alt="<?= View::e($branding['app_name']) ?>">
            <?php else: ?>
                <b><?= View::e($branding['icon_text']) ?></b>
            <?php endif; ?>
            <?= View::e($branding['app_name']) ?>
        </span>
        <div class="login-hero-content">
            <span class="eyebrow light"><?= View::e($branding['subtitle']) ?></span>
            <h1><?= View::e($branding['login_title']) ?></h1>
            <p><?= View::e($branding['login_subtitle']) ?></p>
            <div class="login-feature-grid">
                <span>WhatsApp + Evolution API</span>
                <span>IA com regras comerciais</span>
                <span>CRM e agenda integrados</span>
            </div>
        </div>
        <small><?= View::e($branding['footer_text'] ?: ($branding['show_powered_by'] ? 'Powered by RS Connect' : '')) ?></small>
    </section>

    <section class="login-panel login-panel-pro">
        <form class="card login-card login-card-pro" method="post" action="<?= View::e(Router::url('/login')) ?>">
            <?= Csrf::input() ?>
            <div class="card-heading login-card-heading">
                <?php if (!empty($branding['logo_url'])): ?>
                    <img class="login-card-logo" src="<?= View::e($branding['logo_url']) ?>" alt="<?= View::e($branding['app_name']) ?>">
                <?php else: ?>
                    <span class="brand-mark"><?= View::e($branding['icon_text']) ?></span>
                <?php endif; ?>
                <div><h2>Entrar no painel</h2><p>Acesse sua operação com segurança.</p></div>
            </div>

            <label class="field">
                <span>E-mail</span>
                <input type="email" name="email" autocomplete="email" placeholder="voce@empresa.com" required>
            </label>

            <label class="field">
                <span>Senha</span>
                <input type="password" name="password" autocomplete="current-password" placeholder="Digite sua senha" required>
            </label>

            <button class="btn btn-primary btn-block" type="submit">Acessar painel</button>
            <p class="form-hint">Ambiente seguro para administradores, equipes e clientes.</p>
            <?php if (!empty($branding['support_email'])): ?>
                <p class="form-hint">Suporte: <?= View::e($branding['support_email']) ?></p>
            <?php endif; ?>
        </form>
    </section>
</div>
