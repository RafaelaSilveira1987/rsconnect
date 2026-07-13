<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
?>
<div class="login-grid login-grid-pro">
    <section class="login-hero login-hero-pro">
        <span class="brand-large"><b>RS</b> Connect</span>
        <div class="login-hero-content">
            <span class="eyebrow light">Atendimento, CRM e automação</span>
            <h1>Controle sua operação de WhatsApp em uma plataforma profissional.</h1>
            <p>Multiempresa, agentes de IA, agenda, CRM, cobrança, n8n e atendimento humano trabalhando juntos.</p>
            <div class="login-feature-grid">
                <span>WhatsApp + Evolution API</span>
                <span>IA com regras comerciais</span>
                <span>CRM e agenda integrados</span>
            </div>
        </div>
        <small>RS Automação Digital</small>
    </section>

    <section class="login-panel login-panel-pro">
        <form class="card login-card login-card-pro" method="post" action="<?= View::e(Router::url('/login')) ?>">
            <?= Csrf::input() ?>
            <div class="card-heading login-card-heading">
                <span class="brand-mark">RS</span>
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

            <button class="btn btn-primary btn-block" type="submit">Acessar RS Connect</button>
            <p class="form-hint">Ambiente seguro para administradores, equipes e clientes.</p>
        </form>
    </section>
</div>
