<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
use App\Services\BrandingService;

$branding = is_array($branding ?? null) ? $branding : BrandingService::forCurrentRequest();
$brandEnabled = !empty($branding['enabled']);
$brandName = (string) ($branding['app_name'] ?? 'RS Connect');
$brandSubtitle = (string) ($branding['subtitle'] ?? 'Atendimento e CRM');
$brandLogoUrl = (string) ($branding['logo_url'] ?? '');
$brandIconUrl = (string) ($branding['icon_url'] ?? '');
$brandAssetHref = static fn (string $url): string => preg_match('~^https://~i', $url) === 1 ? $url : Router::url($url);
$brandMainImage = $brandLogoUrl !== '' ? $brandAssetHref($brandLogoUrl) : Router::url('/assets/img/rs-connect-mark.png');
$brandCompactImage = ($brandIconUrl ?: $brandLogoUrl) !== '' ? $brandAssetHref($brandIconUrl ?: $brandLogoUrl) : Router::url('/assets/img/rs-connect-mark.png');
$loginEyebrow = $brandEnabled ? (string) ($branding['login_eyebrow'] ?? $brandSubtitle) : 'Plataforma de atendimento inteligente';
$loginTitle = $brandEnabled ? (string) ($branding['login_title'] ?? ('Acesse o painel da ' . $brandName)) : 'Atendimento, CRM e automação em um só lugar.';
$loginSubtitle = $brandEnabled ? (string) ($branding['login_subtitle'] ?? '') : '';
$loginButtonText = $brandEnabled ? (string) ($branding['login_button_text'] ?? 'Acessar painel') : 'Entrar no RS Connect';
$loginSecurityText = (string) ($branding['login_security_text'] ?? 'Ambiente seguro para administradores, equipes e clientes.');
$benefits = $brandEnabled ? array_values((array) ($branding['login_benefits'] ?? [])) : ['WhatsApp integrado', 'Assistentes virtuais', 'Agenda e CRM'];
$benefits = array_pad(array_slice($benefits, 0, 3), 3, 'Operação integrada');
$footerText = trim((string) ($branding['footer_text'] ?? ''));
$showPoweredBy = !empty($branding['show_powered_by']);
?>
<div class="login-grid login-grid-clean">
    <section class="login-showcase" aria-label="Apresentação de <?= View::e($brandName) ?>">
        <div class="login-brand login-brand-main">
            <img src="<?= View::e($brandMainImage) ?>" alt="<?= View::e($brandName) ?>">
            <div class="login-brand-copy">
                <strong><?= View::e($brandEnabled ? $brandName : 'CONNECT') ?></strong>
                <span aria-hidden="true"></span>
            </div>
        </div>

        <div class="login-showcase-content">
            <p class="login-kicker"><?= View::e($loginEyebrow) ?></p>
            <h1><?= View::e($loginTitle) ?></h1>
            <?php if ($loginSubtitle !== ''): ?><p class="login-custom-subtitle"><?= View::e($loginSubtitle) ?></p><?php endif; ?>
            <div class="login-title-line" aria-hidden="true"></div>

            <div class="login-benefits">
                <article class="login-benefit">
                    <span class="login-benefit-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M7.5 4.5h9a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3h-5.1L7 20v-3.5a3 3 0 0 1-2.5-3v-6a3 3 0 0 1 3-3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8.5 9.3c.7 2 2.2 3.4 4.1 4.1.5.2 1-.1 1.2-.5l.4-.8c.2-.4.6-.6 1-.4l1.2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    </span>
                    <div><strong><?= View::e((string) $benefits[0]) ?></strong><small>Recursos organizados para sua operação.</small></div>
                </article>

                <article class="login-benefit">
                    <span class="login-benefit-icon is-purple" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M9 4.7A3.2 3.2 0 0 0 4.8 8a3 3 0 0 0 .4 5.7A3.2 3.2 0 0 0 9 18.9V4.7Zm6 0A3.2 3.2 0 0 1 19.2 8a3 3 0 0 1-.4 5.7A3.2 3.2 0 0 1 15 18.9V4.7Z" stroke="currentColor" stroke-width="1.8"/><path d="M9 8.5H7.5M9 13H6.8M15 8.5h1.5M15 13h2.2M9 11h6M12 7v9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    </span>
                    <div><strong><?= View::e((string) $benefits[1]) ?></strong><small>Mais produtividade para sua equipe.</small></div>
                </article>

                <article class="login-benefit">
                    <span class="login-benefit-icon is-indigo" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><rect x="4" y="5.5" width="16" height="14" rx="2.5" stroke="currentColor" stroke-width="1.8"/><path d="M8 3.8v3.4M16 3.8v3.4M4 9.5h16M8 13h3M13 13h3M8 16h3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                    </span>
                    <div><strong><?= View::e((string) $benefits[2]) ?></strong><small>Acompanhe tudo em um único ambiente.</small></div>
                </article>
            </div>
        </div>

        <footer class="login-creator">
            <span class="login-creator-mark"><?= View::e(mb_strtoupper(mb_substr($brandName, 0, 2))) ?></span>
            <span><?= View::e($footerText !== '' ? $footerText : $brandName) ?><?php if ($showPoweredBy): ?> · <strong>Powered by RS Connect</strong><?php endif; ?></span>
        </footer>
    </section>

    <section class="login-panel login-panel-clean">
        <form class="login-card-clean" method="post" action="<?= View::e(Router::url('/login')) ?>">
            <?= Csrf::input() ?>

            <div class="login-card-brand">
                <img src="<?= View::e($brandCompactImage) ?>" alt="">
                <strong><?= View::e($brandEnabled ? $brandName : 'CONNECT') ?></strong>
                <span aria-hidden="true"></span>
            </div>

            <header class="login-card-header">
                <h2>Entrar no painel</h2>
                <p><?= View::e($brandEnabled ? ('Acesse o ambiente da ' . $brandName . '.') : 'Acesse sua operação com segurança.') ?></p>
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

            <button class="login-submit" type="submit"><?= View::e($loginButtonText) ?></button>
            <button class="login-demo-trigger" type="button" data-login-demo-open aria-haspopup="dialog" aria-expanded="false">
                <span class="login-demo-trigger-icon" aria-hidden="true">✦</span>
                Testar a IA em uma demonstração
            </button>

            <p class="login-security">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3.5 19 6v5.4c0 4.4-2.9 7.7-7 9.1-4.1-1.4-7-4.7-7-9.1V6l7-2.5Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <?= View::e($loginSecurityText) ?>
            </p>
        </form>
    </section>
</div>
<div class="login-demo-modal" data-login-demo-modal hidden>
    <button class="login-demo-backdrop" type="button" data-login-demo-close aria-label="Fechar demonstração"></button>
    <section class="login-demo-dialog" role="dialog" aria-modal="true" aria-labelledby="login-demo-title">
        <header class="login-demo-dialog-header">
            <div>
                <span class="eyebrow">Experiência interativa</span>
                <h2 id="login-demo-title">Veja a IA atendendo e qualificando um lead</h2>
                <p>Escolha as respostas dentro do celular e acompanhe a oportunidade avançando no comercial.</p>
            </div>
            <button class="login-demo-close" type="button" data-login-demo-close aria-label="Fechar">×</button>
        </header>

        <div class="login-demo-experience">
            <div class="demo-phone" aria-label="Simulação de conversa no WhatsApp">
                <div class="demo-phone-speaker" aria-hidden="true"></div>
                <header class="demo-chat-header">
                    <span class="demo-chat-avatar">RS</span>
                    <div><strong>Assistente RS Connect</strong><small><i></i> online agora</small></div>
                </header>
                <div class="demo-chat-body" data-demo-messages aria-live="polite"></div>
                <div class="demo-chat-options" data-demo-options></div>
                <footer class="demo-chat-footer"><span>Digite uma mensagem...</span><button type="button" aria-label="Enviar" disabled>➤</button></footer>
            </div>

            <aside class="demo-commercial-card">
                <div class="demo-commercial-heading">
                    <span class="demo-commercial-icon" aria-hidden="true">↗</span>
                    <div><small>Automação comercial</small><strong>Oportunidade acompanhada</strong></div>
                </div>
                <div class="demo-lead-card">
                    <span class="demo-lead-badge">Lead da demonstração</span>
                    <h3>Empresa interessada em automação</h3>
                    <p>Origem: demonstração da IA</p>
                    <div class="demo-stage-row"><span>Etapa atual</span><strong data-demo-stage-label>Novo lead</strong></div>
                    <div class="demo-stage-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="16"><span data-demo-stage-progress style="width:16%"></span></div>
                    <p class="demo-stage-reason" data-demo-stage-reason>A conversa acabou de começar.</p>
                </div>
                <div class="demo-feature-list">
                    <span><i>✓</i> Atendimento automático</span>
                    <span><i>✓</i> Identificação de intenção</span>
                    <span><i>✓</i> Sugestão ou movimentação do card</span>
                    <span><i>✓</i> Transferência com contexto</span>
                </div>
                <button class="btn btn-secondary demo-restart" type="button" data-demo-restart>Reiniciar demonstração</button>
            </aside>
        </div>
    </section>
</div>
<script src="<?= View::e(Router::url('/assets/js/login-demo.js?v=36.21.0')) ?>" defer></script>

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
