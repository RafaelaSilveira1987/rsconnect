<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
?>
<section class="privacy-accept-card card">
    <div class="privacy-accept-head">
        <span class="eyebrow">Privacidade e LGPD</span>
        <h2><?= View::e($settings['terms_title'] ?? 'Termos de Uso e Tratamento de Dados') ?></h2>
        <p>Para continuar usando o painel da sua empresa, confirme a ciência sobre os termos de uso, política de privacidade e tratamento de dados no RS Connect.</p>
    </div>

    <div class="privacy-document-grid">
        <article class="privacy-document-box">
            <h3><?= View::e($settings['privacy_policy_title'] ?? 'Política de Privacidade') ?></h3>
            <div class="privacy-document-text"><?= nl2br(View::e($settings['privacy_policy_text'] ?? '')) ?></div>
        </article>
        <article class="privacy-document-box">
            <h3><?= View::e($settings['terms_title'] ?? 'Termos de Uso e Tratamento de Dados') ?></h3>
            <div class="privacy-document-text"><?= nl2br(View::e($settings['terms_text'] ?? '')) ?></div>
        </article>
    </div>

    <form method="post" action="<?= View::e(Router::url('/privacy/accept')) ?>" class="privacy-accept-form">
        <?= Csrf::input() ?>
        <label class="switch-card privacy-accept-checkbox">
            <input type="checkbox" name="accept_terms" value="1" required>
            <span>
                <strong>Li e estou ciente dos termos</strong>
                <small>Meu aceite ficará registrado com data, usuário, versão da política e informações técnicas de auditoria.</small>
            </span>
        </label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Aceitar e continuar</button>
            <a class="btn btn-quiet" href="<?= View::e(Router::url('/logout')) ?>" onclick="event.preventDefault(); document.getElementById('privacyLogout').submit();">Sair</a>
        </div>
    </form>
    <form id="privacyLogout" method="post" action="<?= View::e(Router::url('/logout')) ?>"><?= Csrf::input() ?></form>
</section>
