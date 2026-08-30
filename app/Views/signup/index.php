<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$offer = is_array($offer ?? null) ? $offer : [];
$plan = is_array($offer['plan'] ?? null) ? $offer['plan'] : [];
$old = is_array($_SESSION['public_signup_old'] ?? null) ? $_SESSION['public_signup_old'] : [];
unset($_SESSION['public_signup_old']);
$price = (float) ($offer['price'] ?? 99);
$trialDays = (int) ($offer['trial_days'] ?? 7);
$features = is_array($offer['features'] ?? null) ? $offer['features'] : [];
$limits = is_array($offer['limits'] ?? null) ? $offer['limits'] : [];
$commercialUrl = (string) ($offer['commercial_url'] ?? '');
?>
<div class="signup-page">
    <header class="signup-topbar">
        <a class="signup-brand" href="<?= View::e(Router::url('/login')) ?>" aria-label="Voltar para o login">
            <span>RS</span><strong>RS Connect</strong>
        </a>
        <p>Já possui conta? <a href="<?= View::e(Router::url('/login')) ?>">Entrar</a></p>
    </header>

    <main class="signup-shell">
        <section class="signup-copy">
            <span class="signup-eyebrow">Teste gratuito com cartão seguro</span>
            <h1>Comece a organizar seu atendimento em poucos minutos.</h1>
            <p>Cadastre sua empresa, confirme o cartão no ambiente seguro do Asaas e use o Plano Inicial por <?= View::e($trialDays) ?> dias sem cobrança.</p>

            <article class="signup-plan-card">
                <div class="signup-plan-head">
                    <div><small>Plano disponível no cadastro online</small><h2>Plano Inicial</h2></div>
                    <span>Mais escolhido para começar</span>
                </div>
                <div class="signup-price"><strong>R$ <?= View::e(number_format($price, 2, ',', '.')) ?></strong><span>/mês após o teste</span></div>
                <ul>
                    <li>1 canal de atendimento</li>
                    <li>Até <?= View::e((string) ($limits['users'] ?? 3)) ?> usuários</li>
                    <li>1 assistente com IA RS Connect</li>
                    <li>CRM, agenda e notificações</li>
                    <?php foreach (array_slice($features, 0, 3) as $feature): ?>
                        <li><?= View::e((string) $feature) ?></li>
                    <?php endforeach; ?>
                </ul>
                <div class="signup-trial-summary">
                    <span>Hoje</span><strong>R$ 0,00</strong>
                    <small>A primeira cobrança será feita <?= View::e($trialDays) ?> dias após a conclusão do checkout.</small>
                </div>
            </article>

            <aside class="signup-upgrade-note">
                <strong>Precisa de mais canais ou recursos?</strong>
                <p>Os planos Profissional e Empresarial são configurados com acompanhamento comercial.</p>
                <?php if ($commercialUrl !== ''): ?>
                    <a href="<?= View::e($commercialUrl) ?>" target="_blank" rel="noopener noreferrer">Falar com o comercial</a>
                <?php endif; ?>
            </aside>
        </section>

        <section class="signup-form-card">
            <div class="signup-steps" aria-label="Etapas do cadastro">
                <span class="is-active">1 <small>Dados</small></span><i></i>
                <span>2 <small>Cartão</small></span><i></i>
                <span>3 <small>Conta</small></span>
            </div>
            <header>
                <h2>Crie sua conta</h2>
                <p>Os dados do cartão serão preenchidos diretamente no checkout do Asaas.</p>
            </header>

            <form method="post" action="<?= View::e(Router::url('/signup')) ?>" class="signup-form" autocomplete="on">
                <?= Csrf::input() ?>
                <label class="signup-field signup-field-wide">
                    <span>Nome da empresa *</span>
                    <input type="text" name="company_name" maxlength="150" required value="<?= View::e($old['company_name'] ?? '') ?>" placeholder="Ex.: Clínica Horizonte">
                </label>
                <label class="signup-field signup-field-wide">
                    <span>Razão social <small>(opcional)</small></span>
                    <input type="text" name="legal_name" maxlength="190" value="<?= View::e($old['legal_name'] ?? '') ?>" placeholder="Nome empresarial">
                </label>
                <label class="signup-field signup-field-wide">
                    <span>Responsável pela conta *</span>
                    <input type="text" name="responsible_name" maxlength="150" required value="<?= View::e($old['responsible_name'] ?? '') ?>" placeholder="Nome completo">
                </label>
                <label class="signup-field">
                    <span>CPF ou CNPJ *</span>
                    <input type="text" name="document" inputmode="numeric" maxlength="18" required value="<?= View::e($old['document'] ?? '') ?>" placeholder="000.000.000-00">
                </label>
                <label class="signup-field">
                    <span>WhatsApp *</span>
                    <input type="tel" name="phone" maxlength="20" required value="<?= View::e($old['phone'] ?? '') ?>" placeholder="(32) 99999-9999">
                </label>
                <label class="signup-field signup-field-wide">
                    <span>E-mail de acesso *</span>
                    <input type="email" name="email" maxlength="190" required value="<?= View::e($old['email'] ?? '') ?>" placeholder="voce@empresa.com.br">
                </label>
                <label class="signup-field">
                    <span>Senha *</span>
                    <input type="password" name="password" minlength="8" required autocomplete="new-password" placeholder="Mínimo 8 caracteres">
                </label>
                <label class="signup-field">
                    <span>Confirmar senha *</span>
                    <input type="password" name="password_confirmation" minlength="8" required autocomplete="new-password" placeholder="Repita a senha">
                </label>

                <div class="signup-consents signup-field-wide">
                    <label><input type="checkbox" name="accept_terms" value="1" required><span>Li e aceito os <a href="<?= View::e((string) $offer['terms_url']) ?>" target="_blank" rel="noopener">Termos de Uso</a>.</span></label>
                    <label><input type="checkbox" name="accept_privacy" value="1" required><span>Li e aceito a <a href="<?= View::e((string) $offer['privacy_url']) ?>" target="_blank" rel="noopener">Política de Privacidade</a>.</span></label>
                </div>

                <button class="signup-submit" type="submit">
                    Continuar para o cartão
                    <span aria-hidden="true">→</span>
                </button>
                <p class="signup-secure-note">🔒 O RS Connect não armazena o número completo nem o código de segurança do cartão.</p>
            </form>
        </section>
    </main>
</div>
