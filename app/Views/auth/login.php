<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
?>
<div class="login-grid">
    <section class="login-hero">
        <span class="brand-large"><b>RS</b> Connect</span>
        <div>
            <span class="eyebrow light">Centralize. Automatize. Cresça.</span>
            <h1>Atendimento inteligente em uma única plataforma.</h1>
            <p>Base SaaS multiempresa para conectar WhatsApp, automações, IA e operação humana.</p>
        </div>
        <small>ZIP 01 · Fundação SaaS</small>
    </section>

    <section class="login-panel">
        <form class="card login-card" method="post" action="<?= View::e(Router::url('/login')) ?>">
            <?= Csrf::input() ?>
            <div class="card-heading">
                <span class="brand-mark">RS</span>
                <div><h2>Acessar o painel</h2><p>Use suas credenciais do RS Connect.</p></div>
            </div>

            <label class="field">
                <span>E-mail</span>
                <input type="email" name="email" autocomplete="email" placeholder="voce@empresa.com" required>
            </label>

            <label class="field">
                <span>Senha</span>
                <input type="password" name="password" autocomplete="current-password" placeholder="••••••••" required>
            </label>

            <button class="btn btn-primary btn-block" type="submit">Entrar no RS Connect</button>
            <p class="form-hint">Demo: admin@rsconnect.local / Admin@123</p>
        </form>
    </section>
</div>
