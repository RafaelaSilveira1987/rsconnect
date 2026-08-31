<?php
// Compatibilidade histórica: Inscrição pública e trial Asaas

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$data = is_array($data ?? null) ? $data : [];
$settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
$gateways = is_array($data['gateways'] ?? null) ? $data['gateways'] : [];
$recent = is_array($data['recent_signups'] ?? null) ? $data['recent_signups'] : [];
$selected = null;
foreach ($gateways as $gateway) {
    if ((int) ($gateway['id'] ?? 0) === (int) ($settings['gateway_id'] ?? 0)) {
        $selected = $gateway;
        break;
    }
}
$isProduction = (string) ($selected['environment'] ?? '') === 'production';
$productionReady = $isProduction
    && !empty($selected['has_api_key'])
    && !empty($selected['has_webhook_secret'])
    && (string) ($selected['status'] ?? '') === 'active';
?>
<section class="page-header">
    <div><span class="eyebrow">Financeiro SaaS</span><h1>Inscrição pública e pagamentos Asaas</h1><p>Disponibilize somente o Plano Inicial no login, com cartão recorrente ou Pix QR Code.</p></div>
    <a class="btn btn-secondary" href="<?= View::e(Router::url('/signup')) ?>" target="_blank" rel="noopener">Abrir página de inscrição</a>
</section>

<div class="settings-grid public-signup-admin-grid">
    <section class="card">
        <div class="card-header"><div><h2>Configuração do cadastro online</h2><p>Planos superiores continuam direcionados ao comercial.</p></div><span class="status-pill <?= !empty($data['enabled']) ? 'is-success' : 'is-muted' ?>"><?= !empty($data['enabled']) ? 'Ativo' : 'Desativado' ?></span></div>
        <form method="post" action="<?= View::e(Router::url('/settings/public-signup/save')) ?>" class="form-grid">
            <?= Csrf::input() ?>
            <label class="toggle-card form-span-2">
                <input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>>
                <span><strong>Permitir inscrições pelo login</strong><small>O botão “Começar 7 dias grátis” ficará visível para visitantes.</small></span>
            </label>
            <label class="toggle-card form-span-2">
                <input type="checkbox" name="pix_enabled" value="1" <?= !empty($settings['pix_enabled']) ? 'checked' : '' ?>>
                <span><strong>Oferecer Pix QR Code</strong><small>O Pix cobra a primeira mensalidade imediatamente e adiciona os dias gratuitos ao primeiro ciclo. Renovações são pagas por QR Code.</small></span>
            </label>
            <label><span>Gateway Asaas</span><select name="gateway_id" required><option value="">Selecione</option><?php foreach ($gateways as $gateway): ?><option value="<?= (int) $gateway['id'] ?>" <?= (int) ($settings['gateway_id'] ?? 0) === (int) $gateway['id'] ? 'selected' : '' ?>><?= View::e((string) $gateway['label']) ?> — <?= View::e((string) $gateway['environment']) ?><?= (string) $gateway['status'] !== 'active' ? ' (inativo)' : '' ?></option><?php endforeach; ?></select></label>
            <label><span>Plano público</span><input type="text" value="Plano Inicial — IA RS Connect" readonly></label>
            <label><span>Dias gratuitos</span><input type="number" name="trial_days" min="1" max="30" value="<?= (int) ($settings['trial_days'] ?? 7) ?>" required></label>
            <label><span>Tolerância após falha</span><input type="number" name="grace_days" min="0" max="30" value="<?= (int) ($settings['grace_days'] ?? 3) ?>" required></label>
            <label><span>Validade do checkout (minutos)</span><input type="number" name="checkout_minutes" min="10" max="1440" value="<?= (int) ($settings['checkout_minutes'] ?? 60) ?>" required></label>
            <label><span>WhatsApp comercial</span><input type="text" name="commercial_whatsapp" value="<?= View::e((string) ($settings['commercial_whatsapp'] ?? '')) ?>" placeholder="5532987073537"></label>
            <label class="form-span-2"><span>Mensagem para upgrade</span><input type="text" name="commercial_message" maxlength="500" value="<?= View::e((string) ($settings['commercial_message'] ?? '')) ?>"></label>
            <label><span>URL dos Termos</span><input type="text" name="terms_url" value="<?= View::e((string) ($settings['terms_url'] ?? '/termos-de-uso')) ?>"></label>
            <label><span>URL da Privacidade</span><input type="text" name="privacy_url" value="<?= View::e((string) ($settings['privacy_url'] ?? '/politica-de-privacidade')) ?>"></label>
            <div class="form-actions form-span-2"><button class="btn btn-primary" type="submit">Salvar configurações</button></div>
        </form>
    </section>

    <aside class="card public-signup-checklist">
        <div class="card-header"><div><h2>Checklist do Asaas</h2><p>Itens necessários antes de ativar.</p></div></div>
        <ul>
            <li class="<?= $selected ? 'is-ok' : '' ?>"><strong>Gateway selecionado</strong><span><?= $selected ? View::e((string) $selected['label']) : 'Pendente' ?></span></li>
            <li class="<?= $isProduction ? 'is-ok' : '' ?>"><strong>Ambiente</strong><span><?= $selected ? ($isProduction ? 'Produção' : 'Sandbox') : 'Pendente' ?></span></li>
            <li class="<?= !empty($selected['has_api_key']) ? 'is-ok' : '' ?>"><strong>API Key</strong><span><?= !empty($selected['has_api_key']) ? 'Configurada' : 'Pendente' ?></span></li>
            <li class="<?= !empty($selected['has_webhook_secret']) ? 'is-ok' : '' ?>"><strong>Token do webhook</strong><span><?= !empty($selected['has_webhook_secret']) ? 'Configurado' : 'Pendente' ?></span></li>
            <li><strong>Endpoint</strong><code><?= View::e(Router::url('/webhooks/payments/asaas')) ?></code></li>
        </ul>
        <div class="public-signup-admin-actions">
            <a class="btn btn-secondary" href="<?= View::e(Router::url('/payment-gateways')) ?>">Configurar meio de pagamento</a>
            <form method="post" action="<?= View::e(Router::url('/settings/public-signup/test-gateway')) ?>">
                <?= Csrf::input() ?>
                <input type="hidden" name="gateway_id" value="<?= (int) ($selected['id'] ?? 0) ?>">
                <button class="btn btn-outline" type="submit" <?= $selected ? '' : 'disabled' ?>>Testar conexão com o Asaas</button>
            </form>
        </div>

        <div class="production-readiness <?= $productionReady ? 'is-ready' : 'is-pending' ?>">
            <span class="eyebrow">Validação para produção</span>
            <h3><?= $productionReady ? 'Pronto para o teste real controlado' : 'Ainda há itens pendentes' ?></h3>
            <p><?= $productionReady
                ? 'A chave e o webhook de Produção estão configurados. Faça uma inscrição real controlada antes de divulgar o cadastro.'
                : 'Crie um gateway separado para Produção, configure a chave e o token do webhook e valide a conexão.' ?></p>
            <ol>
                <li>Deixe o Pix desativado durante o primeiro teste real.</li>
                <li>Use um e-mail e documento que ainda não existam no RS Connect.</li>
                <li>Conclua o cartão no Checkout do Asaas e aguarde o webhook.</li>
                <li>Confirme a criação da empresa, o status de teste e a primeira cobrança em sete dias.</li>
            </ol>
        </div>
    </aside>
</div>

<section class="card mt-24">
    <div class="card-header"><div><h2>Inscrições recentes</h2><p>Acompanhe checkout, provisionamento e eventuais falhas.</p></div></div>
    <div class="table-responsive"><table><thead><tr><th>Empresa</th><th>Responsável</th><th>Status</th><th>Pagamento</th><th>Período inicial</th><th>Conta</th><th>Criado em</th></tr></thead><tbody>
    <?php if ($recent === []): ?><tr><td colspan="7" class="empty-state">Nenhuma inscrição iniciada.</td></tr><?php else: foreach ($recent as $row): ?>
        <tr><td><strong><?= View::e((string) $row['company_name']) ?></strong><small><?= View::e((string) $row['email']) ?></small></td><td><?= View::e((string) $row['responsible_name']) ?></td><td><span class="status-pill status-<?= View::e((string) $row['status']) ?>"><?= View::e((string) $row['status']) ?></span><?php if (!empty($row['last_error'])): ?><small title="<?= View::e((string) $row['last_error']) ?>">Ver erro no título</small><?php endif; ?></td><td><?= (string) ($row['payment_method'] ?? 'credit_card') === 'pix' ? 'Pix QR Code' : 'Cartão' ?></td><td><?= (string) ($row['payment_method'] ?? 'credit_card') === 'pix' ? ((int) ($row['bonus_days'] ?? 0) . ' dias adicionais') : View::e(date('d/m/Y', strtotime((string) $row['trial_ends_at']))) ?></td><td><?= !empty($row['tenant_name']) ? View::e((string) $row['tenant_name']) : '—' ?></td><td><?= View::e(date('d/m/Y H:i', strtotime((string) $row['created_at']))) ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody></table></div>
</section>
