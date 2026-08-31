<?php

use App\Core\Router;
use App\Core\View;

$checkout = is_array($checkout ?? null) ? $checkout : [];
$checkoutUrl = (string) ($checkout['checkout_url'] ?? '');
$companyName = (string) ($checkout['company_name'] ?? 'sua empresa');
$encodedUrl = json_encode(
    $checkoutUrl,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<div class="signup-status-page">
    <section class="signup-status-card signup-checkout-bridge" aria-live="polite">
        <a class="signup-brand signup-brand-centered" href="<?= View::e(Router::url('/login')) ?>">
            <span>RS</span><strong>RS Connect</strong>
        </a>

        <div class="signup-status-loader" aria-hidden="true"></div>
        <h1>Abrindo o checkout seguro</h1>
        <p>O cadastro de <strong><?= View::e($companyName) ?></strong> foi iniciado. Você será direcionado ao ambiente do Asaas para informar endereço e cartão.</p>

        <a id="asaas-checkout-link" class="signup-status-primary" href="<?= View::e($checkoutUrl) ?>" rel="noopener noreferrer">
            Continuar para o Asaas
        </a>
        <p id="checkout-redirect-help" class="signup-checkout-help">Caso a página não abra automaticamente, use o botão acima.</p>
        <a class="signup-checkout-back" href="<?= View::e(Router::url('/signup')) ?>">Voltar ao cadastro</a>

        <noscript>
            <p class="signup-checkout-noscript">O redirecionamento automático exige JavaScript. Clique em “Continuar para o Asaas”.</p>
        </noscript>
    </section>
</div>
<script>
(() => {
    const target = <?= is_string($encodedUrl) ? $encodedUrl : "''" ?>;
    const help = document.getElementById('checkout-redirect-help');
    if (!target) {
        if (help) help.textContent = 'Não foi possível abrir o checkout. Volte e tente novamente.';
        return;
    }

    window.setTimeout(() => {
        window.location.replace(target);
    }, 250);

    window.setTimeout(() => {
        if (help) help.textContent = 'O navegador não redirecionou automaticamente. Clique em “Continuar para o Asaas”.';
    }, 3000);
})();
</script>
