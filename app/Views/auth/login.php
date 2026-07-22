<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
?>
<div class="login-grid login-grid-clean">
    <section class="login-showcase" aria-label="Apresentação do RS Connect">
        <div class="login-brand login-brand-main">
            <img src="<?= View::e(Router::url('/assets/img/rs-connect-mark.png')) ?>" alt="RS Connect">
            <div class="login-brand-copy">
                <strong>CONNECT</strong>
                <span aria-hidden="true"></span>
            </div>
        </div>

        <div class="login-showcase-content">
            <p class="login-kicker">Plataforma de atendimento inteligente</p>
            <h1>Atendimento, CRM e automação em um só lugar.</h1>
            <div class="login-title-line" aria-hidden="true"></div>

            <div class="login-benefits">
                <article class="login-benefit">
                    <span class="login-benefit-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M7.5 4.5h9a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3h-5.1L7 20v-3.5a3 3 0 0 1-2.5-3v-6a3 3 0 0 1 3-3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8.5 9.3c.7 2 2.2 3.4 4.1 4.1.5.2 1-.1 1.2-.5l.4-.8c.2-.4.6-.6 1-.4l1.2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    </span>
                    <div><strong>WhatsApp integrado</strong><small>Converse, automatize e acompanhe.</small></div>
                </article>

                <article class="login-benefit">
                    <span class="login-benefit-icon is-purple" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M9 4.7A3.2 3.2 0 0 0 4.8 8a3 3 0 0 0 .4 5.7A3.2 3.2 0 0 0 9 18.9V4.7Zm6 0A3.2 3.2 0 0 1 19.2 8a3 3 0 0 1-.4 5.7A3.2 3.2 0 0 1 15 18.9V4.7Z" stroke="currentColor" stroke-width="1.8"/><path d="M9 8.5H7.5M9 13H6.8M15 8.5h1.5M15 13h2.2M9 11h6M12 7v9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    </span>
                    <div><strong>Agentes de IA</strong><small>Mais produtividade e respostas inteligentes.</small></div>
                </article>

                <article class="login-benefit">
                    <span class="login-benefit-icon is-indigo" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><rect x="4" y="5.5" width="16" height="14" rx="2.5" stroke="currentColor" stroke-width="1.8"/><path d="M8 3.8v3.4M16 3.8v3.4M4 9.5h16M8 13h3M13 13h3M8 16h3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                    </span>
                    <div><strong>Agenda e CRM</strong><small>Organize sua operação e acompanhe tudo.</small></div>
                </article>
            </div>
        </div>

        <footer class="login-creator">
            <span class="login-creator-mark">RS</span>
            <span>Criado por <strong>RS Digital Lab</strong></span>
        </footer>
    </section>

    <section class="login-panel login-panel-clean">
        <form class="login-card-clean" method="post" action="<?= View::e(Router::url('/login')) ?>">
            <?= Csrf::input() ?>

            <div class="login-card-brand">
                <img src="<?= View::e(Router::url('/assets/img/rs-connect-mark.png')) ?>" alt="">
                <strong>CONNECT</strong>
                <span aria-hidden="true"></span>
            </div>

            <header class="login-card-header">
                <h2>Entrar no painel</h2>
                <p>Acesse sua operação com segurança.</p>
            </header>

            <label class="login-field">
                <span>E-mail</span>
                <span class="login-input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="5.5" width="17" height="13" rx="2.5" stroke="currentColor" stroke-width="1.7"/><path d="m5 7 7 5 7-5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <input type="email" name="email" autocomplete="email" placeholder="seu@email.com" required autofocus>
                </span>
            </label>

            <label class="login-field">
                <span>Senha</span>
                <span class="login-input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2.5" stroke="currentColor" stroke-width="1.7"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10M12 14v2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                    <input id="login-password" type="password" name="password" autocomplete="current-password" placeholder="Digite sua senha" required>
                    <button class="login-password-toggle" type="button" aria-label="Mostrar senha" aria-controls="login-password" aria-pressed="false">
                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.8 12s3.2-5.3 9.2-5.3 9.2 5.3 9.2 5.3-3.2 5.3-9.2 5.3S2.8 12 2.8 12Z" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.7"/></svg>
                    </button>
                </span>
            </label>

            <label class="login-remember">
                <input type="checkbox" name="remember" value="1">
                <span>Lembrar de mim</span>
            </label>

            <button class="login-submit" type="submit">Entrar no RS Connect</button>

            <p class="login-security">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3.5 19 6v5.4c0 4.4-2.9 7.7-7 9.1-4.1-1.4-7-4.7-7-9.1V6l7-2.5Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Ambiente seguro para administradores, equipes e clientes.
            </p>
        </form>
    </section>
</div>
<script>
(() => {
    const button = document.querySelector('.login-password-toggle');
    const input = document.getElementById('login-password');
    if (!button || !input) return;
    button.addEventListener('click', () => {
        const visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        button.setAttribute('aria-pressed', visible ? 'false' : 'true');
        button.setAttribute('aria-label', visible ? 'Mostrar senha' : 'Ocultar senha');
    });
})();
</script>
