<?php

use App\Core\Router;
use App\Core\View;

$status = (string) ($signup['status'] ?? 'not_found');
$email = (string) ($signup['email'] ?? '');
$isReady = $status === 'provisioned';
$isClosed = in_array($status, ['cancelled', 'expired'], true) || in_array((string) ($callbackState ?? ''), ['cancelled', 'expired'], true);
$paymentMethod = (string) ($signup['payment_method'] ?? 'credit_card');
$isPix = $paymentMethod === 'pix';
$bonusDays = max(0, (int) ($signup['bonus_days'] ?? 0));
$nextPixBilling = $isPix && !empty($signup['first_charge_at'])
    ? (new DateTimeImmutable((string) $signup['first_charge_at']))->modify('+1 month +' . $bonusDays . ' days')->format('d/m/Y')
    : '';
?>
<div class="signup-status-page" data-signup-status-root data-token="<?= View::e((string) ($token ?? '')) ?>" data-ready="<?= $isReady ? '1' : '0' ?>">
    <section class="signup-status-card">
        <a class="signup-brand signup-brand-centered" href="<?= View::e(Router::url('/login')) ?>"><span>RS</span><strong>RS Connect</strong></a>
        <?php if (!$signup): ?>
            <div class="signup-status-icon is-error">!</div>
            <h1>Não encontramos esta inscrição</h1>
            <p>O link pode ter expirado ou estar incompleto.</p>
            <a class="signup-status-primary" href="<?= View::e(Router::url('/signup')) ?>">Iniciar novo cadastro</a>
        <?php elseif ($isReady): ?>
            <div class="signup-status-icon is-success">✓</div>
            <h1>Sua conta está pronta!</h1>
            <p><?= $isPix ? 'O pagamento Pix foi confirmado e o Plano Inicial foi liberado.' : 'O Plano Inicial foi liberado com o período gratuito.' ?> Use <strong><?= View::e($email) ?></strong> para entrar.</p>
            <div class="signup-status-details">
                <?php if ($isPix): ?>
                    <span>Pagamento <strong>Pix confirmado</strong></span>
                    <span>Próxima renovação <strong><?= View::e($nextPixBilling) ?></strong></span>
                <?php else: ?>
                    <span>Teste gratuito até <strong><?= View::e(date('d/m/Y', strtotime((string) $signup['trial_ends_at']))) ?></strong></span>
                    <span>Primeira cobrança em <strong><?= View::e(date('d/m/Y', strtotime((string) $signup['first_charge_at']))) ?></strong></span>
                <?php endif; ?>
            </div>
            <a class="signup-status-primary" href="<?= View::e(Router::url('/login')) ?>">Entrar no RS Connect</a>
        <?php elseif ($isClosed): ?>
            <div class="signup-status-icon is-warning">×</div>
            <h1><?= $status === 'expired' || $callbackState === 'expired' ? 'O checkout expirou' : 'Cadastro não concluído' ?></h1>
            <p>Nenhuma conta foi ativada e nenhuma cobrança foi iniciada.</p>
            <a class="signup-status-primary" href="<?= View::e(Router::url('/signup')) ?>">Tentar novamente</a>
        <?php else: ?>
            <div class="signup-status-loader" aria-hidden="true"></div>
            <h1 data-signup-status-title>Estamos confirmando seu cadastro</h1>
            <p data-signup-status-message><?= $isPix ? 'Estamos aguardando a confirmação do pagamento Pix pelo Asaas para criar sua conta.' : 'O checkout foi concluído. Estamos aguardando a confirmação segura do Asaas para criar sua conta.' ?></p>
            <div class="signup-status-details">
                <span>E-mail <strong><?= View::e($email) ?></strong></span>
                <span>Plano <strong>Inicial</strong></span>
            </div>
            <a class="signup-status-primary" href="<?= View::e(Router::url('/login')) ?>" data-signup-login-link hidden>Entrar no RS Connect</a>
            <small>Esta página será atualizada automaticamente.</small>
        <?php endif; ?>
    </section>
</div>
<?php if ($signup && !$isReady && !$isClosed): ?>
<script>
(() => {
    const root = document.querySelector('[data-signup-status-root]');
    if (!root) return;
    const token = root.dataset.token || '';
    const title = root.querySelector('[data-signup-status-title]');
    const message = root.querySelector('[data-signup-status-message]');
    const login = root.querySelector('[data-signup-login-link]');
    let attempts = 0;
    const check = async () => {
        attempts += 1;
        try {
            const response = await fetch(<?= json_encode(Router::url('/signup/status')) ?> + '?token=' + encodeURIComponent(token), {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            const data = await response.json();
            if (data.ready) {
                if (title) title.textContent = 'Sua conta está pronta!';
                if (message) message.textContent = data.payment_method === 'pix' ? 'O pagamento Pix foi confirmado. Você já pode entrar no RS Connect.' : 'O período gratuito foi ativado. Você já pode entrar no RS Connect.';
                if (login) { login.hidden = false; login.href = data.login_url; }
                return;
            }
            if (['failed', 'cancelled', 'expired'].includes(data.status)) {
                if (title) title.textContent = 'Não foi possível concluir automaticamente';
                if (message) message.textContent = data.last_error || 'Revise o checkout ou tente novamente.';
                return;
            }
        } catch (_) {}
        if (attempts < 40) window.setTimeout(check, 3000);
    };
    window.setTimeout(check, 1500);
})();
</script>
<?php endif; ?>
