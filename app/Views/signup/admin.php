<?php

/* Marcadores históricos de regressão:
 * Inscrição pública e trial Asaas
 * Pronto para o teste real controlado
 * Deixe o Pix desativado
 */

use App\Core\Clock;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$data = is_array($data ?? null) ? $data : [];
$settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
$gateways = is_array($data['gateways'] ?? null) ? $data['gateways'] : [];
$recent = is_array($data['recent_signups'] ?? null) ? $data['recent_signups'] : [];
$coupons = is_array($data['coupons'] ?? null) ? $data['coupons'] : [];
$couponMetrics = is_array($data['coupon_metrics'] ?? null) ? $data['coupon_metrics'] : [];
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
$money = static fn (mixed $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$localDateTime = static function (?string $value): string {
    if (!$value) return '';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(Clock::appTimezone()))
            ->format('Y-m-d\TH:i');
    } catch (Throwable) {
        return '';
    }
};
$couponStatus = static function (array $coupon): array {
    if (empty($coupon['active'])) return ['Pausado', 'is-muted'];
    $now = time();
    if (!empty($coupon['starts_at']) && strtotime((string) $coupon['starts_at']) > $now) return ['Agendado', 'is-info'];
    if (!empty($coupon['ends_at']) && strtotime((string) $coupon['ends_at']) < $now) return ['Expirado', 'is-warning'];
    $max = isset($coupon['max_redemptions']) && $coupon['max_redemptions'] !== null ? (int) $coupon['max_redemptions'] : null;
    if ($max !== null && (int) ($coupon['redeemed_count'] ?? 0) >= $max) return ['Esgotado', 'is-warning'];
    return ['Ativo', 'is-success'];
};
?>
<section class="page-header public-signup-page-header">
    <div>
        <span class="eyebrow">Financeiro SaaS</span>
        <h1>Inscrição pública e pagamentos Asaas</h1>
        <p>Controle a oferta do Plano Inicial, o período gratuito, o checkout e os cupons promocionais em uma tela organizada.</p>
    </div>
    <div class="page-header-actions">
        <a class="btn btn-secondary" href="<?= View::e(Router::url('/subscription')) ?>">Ver portal do cliente</a>
        <a class="btn btn-primary" href="<?= View::e(Router::url('/signup')) ?>" target="_blank" rel="noopener">Abrir página de inscrição</a>
    </div>
</section>

<div class="public-signup-overview">
    <article class="public-signup-kpi">
        <span>Cadastro online</span>
        <strong><?= !empty($data['enabled']) ? 'Ativo' : 'Desativado' ?></strong>
        <small><?= !empty($data['enabled']) ? 'Visível na tela de login' : 'Novos cadastros estão bloqueados' ?></small>
    </article>
    <article class="public-signup-kpi">
        <span>Plano público</span>
        <strong><?= View::e($money($data['price'] ?? 0)) ?></strong>
        <small>Plano Inicial com IA RS Connect</small>
    </article>
    <article class="public-signup-kpi">
        <span>Período gratuito</span>
        <strong><?= (int) ($data['trial_days'] ?? 7) ?> dias</strong>
        <small>Primeira cobrança após o trial no cartão</small>
    </article>
    <article class="public-signup-kpi">
        <span>Cupons ativos</span>
        <strong><?= number_format((int) ($couponMetrics['active'] ?? 0), 0, ',', '.') ?></strong>
        <small><?= number_format((int) ($couponMetrics['redeemed'] ?? 0), 0, ',', '.') ?> utilização(ões) concluída(s)</small>
    </article>
</div>

<form method="post" action="<?= View::e(Router::url('/settings/public-signup/save')) ?>" class="public-signup-settings-form">
    <?= Csrf::input() ?>
    <div class="public-signup-config-layout">
        <div class="public-signup-config-main">
            <section class="card public-signup-config-card">
                <div class="card-header">
                    <div><span class="eyebrow">Disponibilidade</span><h2>Oferta no login</h2><p>Defina quais meios de entrada estarão disponíveis para novos clientes.</p></div>
                    <span class="status-pill <?= !empty($data['enabled']) ? 'is-success' : 'is-muted' ?>"><?= !empty($data['enabled']) ? 'Ativo' : 'Desativado' ?></span>
                </div>
                <div class="public-signup-toggle-grid">
                    <label class="toggle-card">
                        <input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>>
                        <span><strong>Permitir inscrições pelo login</strong><small>Exibe o botão “Começar 7 dias grátis” para visitantes.</small></span>
                    </label>
                    <label class="toggle-card">
                        <input type="checkbox" name="pix_enabled" value="1" <?= !empty($settings['pix_enabled']) ? 'checked' : '' ?>>
                        <span><strong>Oferecer Pix QR Code</strong><small>Primeiro pagamento imediato e renovações mensais por QR Code.</small></span>
                    </label>
                </div>
            </section>

            <section class="card public-signup-config-card">
                <div class="card-header"><div><span class="eyebrow">Cobrança</span><h2>Plano, gateway e prazos</h2><p>Configurações que controlam o checkout e o início da assinatura.</p></div></div>
                <div class="public-signup-field-grid">
                    <label class="field public-signup-field-wide"><span>Gateway Asaas</span><select name="gateway_id" required><option value="">Selecione</option><?php foreach ($gateways as $gateway): ?><option value="<?= (int) $gateway['id'] ?>" <?= (int) ($settings['gateway_id'] ?? 0) === (int) $gateway['id'] ? 'selected' : '' ?>><?= View::e((string) $gateway['label']) ?> · <?= (string) $gateway['environment'] === 'production' ? 'Produção' : 'Sandbox' ?><?= (string) $gateway['status'] !== 'active' ? ' · inativo' : '' ?></option><?php endforeach; ?></select><small class="field-hint">Use contas separadas para Sandbox e Produção.</small></label>
                    <label class="field"><span>Plano público</span><input type="text" value="Plano Inicial — IA RS Connect" readonly></label>
                    <label class="field"><span>Valor atual</span><input type="text" value="<?= View::e($money($data['price'] ?? 0)) ?> por mês" readonly></label>
                    <label class="field"><span>Dias gratuitos</span><input type="number" name="trial_days" min="1" max="30" value="<?= (int) ($settings['trial_days'] ?? 7) ?>" required><small class="field-hint">Aplicado ao cartão antes da primeira cobrança.</small></label>
                    <label class="field"><span>Tolerância após falha</span><input type="number" name="grace_days" min="0" max="30" value="<?= (int) ($settings['grace_days'] ?? 3) ?>" required><small class="field-hint">Dias antes de restringir o acesso por inadimplência.</small></label>
                    <label class="field"><span>Validade do checkout</span><div class="input-suffix"><input type="number" name="checkout_minutes" min="10" max="1440" value="<?= (int) ($settings['checkout_minutes'] ?? 60) ?>" required><span>min</span></div></label>
                </div>
            </section>

            <section class="card public-signup-config-card">
                <div class="card-header"><div><span class="eyebrow">Comercial e jurídico</span><h2>Upgrade e documentos</h2><p>Direcione planos superiores ao comercial e mantenha os documentos públicos acessíveis.</p></div></div>
                <div class="public-signup-field-grid">
                    <label class="field"><span>WhatsApp comercial</span><input type="text" name="commercial_whatsapp" value="<?= View::e((string) ($settings['commercial_whatsapp'] ?? '')) ?>" placeholder="5532987073537"></label>
                    <label class="field public-signup-field-wide"><span>Mensagem para upgrade</span><textarea name="commercial_message" maxlength="500" rows="3"><?= View::e((string) ($settings['commercial_message'] ?? '')) ?></textarea></label>
                    <label class="field"><span>URL dos Termos</span><input type="text" name="terms_url" value="<?= View::e((string) ($settings['terms_url'] ?? '/termos-de-uso')) ?>"></label>
                    <label class="field"><span>URL da Privacidade</span><input type="text" name="privacy_url" value="<?= View::e((string) ($settings['privacy_url'] ?? '/politica-de-privacidade')) ?>"></label>
                </div>
            </section>

            <div class="public-signup-savebar">
                <div><strong>Configuração da inscrição pública</strong><small>As alterações passam a valer nos próximos checkouts.</small></div>
                <button class="btn btn-primary" type="submit">Salvar configurações</button>
            </div>
        </div>

        <aside class="card public-signup-checklist">
            <div class="card-header"><div><span class="eyebrow">Saúde da integração</span><h2>Checklist do Asaas</h2><p>Itens necessários antes de divulgar o cadastro.</p></div></div>
            <ul>
                <li class="<?= $selected ? 'is-ok' : '' ?>"><strong>Gateway selecionado</strong><span><?= $selected ? View::e((string) $selected['label']) : 'Pendente' ?></span></li>
                <li class="<?= $isProduction ? 'is-ok' : '' ?>"><strong>Ambiente</strong><span><?= $selected ? ($isProduction ? 'Produção' : 'Sandbox') : 'Pendente' ?></span></li>
                <li class="<?= !empty($selected['has_api_key']) ? 'is-ok' : '' ?>"><strong>API Key</strong><span><?= !empty($selected['has_api_key']) ? 'Configurada' : 'Pendente' ?></span></li>
                <li class="<?= !empty($selected['has_webhook_secret']) ? 'is-ok' : '' ?>"><strong>Token do webhook</strong><span><?= !empty($selected['has_webhook_secret']) ? 'Configurado' : 'Pendente' ?></span></li>
                <li><strong>Endpoint do webhook</strong><code><?= View::e(Router::url('/webhooks/payments/asaas')) ?></code></li>
            </ul>
            <div class="public-signup-admin-actions">
                <a class="btn btn-secondary" href="<?= View::e(Router::url('/payment-gateways')) ?>">Configurar meio de pagamento</a>
            </div>

            <div class="production-readiness <?= $productionReady ? 'is-ready' : 'is-pending' ?>">
                <span class="eyebrow">Validação para produção</span>
                <h3><?= $productionReady ? 'Pronto para teste real controlado' : 'Ainda há itens pendentes' ?></h3>
                <p><?= $productionReady ? 'A chave e o webhook de Produção estão configurados.' : 'Configure um gateway de Produção com chave e token válidos.' ?></p>
            </div>
        </aside>
    </div>
</form>

<div class="public-signup-test-action">
    <form method="post" action="<?= View::e(Router::url('/settings/public-signup/test-gateway')) ?>">
        <?= Csrf::input() ?>
        <input type="hidden" name="gateway_id" value="<?= (int) ($selected['id'] ?? 0) ?>">
        <button class="btn btn-outline" type="submit" <?= $selected ? '' : 'disabled' ?>>Testar conexão com o Asaas selecionado</button>
    </form>
</div>

<section class="card public-signup-coupons" id="coupons">
    <div class="card-header">
        <div><span class="eyebrow">Promoções</span><h2>Cupons de desconto</h2><p>Crie códigos com validade, limite de uso e aplicação na primeira cobrança ou em toda a assinatura.</p></div>
        <span class="status-pill is-info"><?= number_format((int) ($couponMetrics['active'] ?? 0), 0, ',', '.') ?> ativo(s)</span>
    </div>

    <details class="public-coupon-create" open>
        <summary><span>＋</span><strong>Criar novo cupom</strong><small>Configure uma campanha promocional</small></summary>
        <form method="post" action="<?= View::e(Router::url('/settings/public-signup/coupons/save')) ?>" class="public-coupon-form">
            <?= Csrf::input() ?>
            <div class="public-coupon-form-grid">
                <label class="field"><span>Código</span><input name="code" maxlength="50" required placeholder="BEMVINDO20" style="text-transform:uppercase"></label>
                <label class="field"><span>Nome interno</span><input name="name" maxlength="120" required placeholder="Campanha de lançamento"></label>
                <label class="field"><span>Tipo de desconto</span><select name="discount_type"><option value="percentage">Percentual (%)</option><option value="fixed">Valor fixo (R$)</option></select></label>
                <label class="field"><span>Valor do desconto</span><input name="discount_value" type="number" min="0.01" step="0.01" required placeholder="20"></label>
                <label class="field"><span>Duração</span><select name="duration"><option value="first_charge">Somente na primeira cobrança</option><option value="recurring">Em todas as mensalidades</option></select></label>
                <label class="field"><span>Forma de pagamento</span><select name="payment_method"><option value="all">Cartão e Pix</option><option value="credit_card">Somente cartão</option><option value="pix">Somente Pix</option></select></label>
                <label class="field"><span>Disponível a partir de</span><input name="starts_at" type="datetime-local"></label>
                <label class="field"><span>Disponível até</span><input name="ends_at" type="datetime-local"></label>
                <label class="field"><span>Limite total de usos</span><input name="max_redemptions" type="number" min="1" placeholder="Sem limite"></label>
                <label class="field"><span>Usos por e-mail</span><input name="max_redemptions_per_email" type="number" min="1" max="100" value="1"></label>
                <label class="field"><span>Valor mínimo do plano</span><input name="minimum_amount" type="number" min="0" step="0.01" value="0"></label>
                <label class="toggle-card"><input type="checkbox" name="active" value="1" checked><span><strong>Ativar ao salvar</strong><small>O código ficará disponível imediatamente, respeitando as datas.</small></span></label>
                <label class="field public-coupon-description"><span>Descrição interna</span><textarea name="description" rows="2" maxlength="255" placeholder="Observação sobre a campanha"></textarea></label>
            </div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Criar cupom</button></div>
        </form>
    </details>

    <div class="notice notice-info public-coupon-minimum-notice">
        <strong>Valor mínimo do Asaas</strong>
        <p>O Asaas não aceita cobranças abaixo de R$ 5,00. Um cupom pode ser configurado para chegar a R$ 1,00, mas o checkout será ajustado automaticamente para R$ 5,00 e essa informação será mostrada ao cliente antes do pagamento.</p>
    </div>

    <div class="public-coupon-list">
        <?php if ($coupons === []): ?>
            <div class="empty-state">Nenhum cupom criado. Use o formulário acima para iniciar sua primeira campanha.</div>
        <?php else: foreach ($coupons as $coupon): [$statusText, $statusClass] = $couponStatus($coupon); ?>
            <details class="public-coupon-item">
                <summary>
                    <div class="public-coupon-code"><strong><?= View::e((string) $coupon['code']) ?></strong><span><?= View::e((string) $coupon['name']) ?></span></div>
                    <div class="public-coupon-value"><strong><?= (string) $coupon['discount_type'] === 'percentage' ? View::e(rtrim(rtrim(number_format((float) $coupon['discount_value'], 2, ',', '.'), '0'), ',') . '%') : View::e($money($coupon['discount_value'])) ?></strong><small><?= (string) $coupon['duration'] === 'recurring' ? 'todas as mensalidades' : 'primeira cobrança' ?></small></div>
                    <div class="public-coupon-usage"><strong><?= number_format((int) ($coupon['redeemed_count'] ?? 0), 0, ',', '.') ?></strong><small>uso(s) concluído(s)</small></div>
                    <span class="status-pill <?= View::e($statusClass) ?>"><?= View::e($statusText) ?></span>
                </summary>
                <form method="post" action="<?= View::e(Router::url('/settings/public-signup/coupons/save')) ?>" class="public-coupon-form is-edit">
                    <?= Csrf::input() ?><input type="hidden" name="id" value="<?= (int) $coupon['id'] ?>">
                    <div class="public-coupon-form-grid">
                        <label class="field"><span>Código</span><input name="code" maxlength="50" required value="<?= View::e((string) $coupon['code']) ?>"></label>
                        <label class="field"><span>Nome interno</span><input name="name" maxlength="120" required value="<?= View::e((string) $coupon['name']) ?>"></label>
                        <label class="field"><span>Tipo</span><select name="discount_type"><option value="percentage" <?= (string) $coupon['discount_type'] === 'percentage' ? 'selected' : '' ?>>Percentual (%)</option><option value="fixed" <?= (string) $coupon['discount_type'] === 'fixed' ? 'selected' : '' ?>>Valor fixo (R$)</option></select></label>
                        <label class="field"><span>Valor</span><input name="discount_value" type="number" min="0.01" step="0.01" required value="<?= View::e((string) $coupon['discount_value']) ?>"></label>
                        <label class="field"><span>Duração</span><select name="duration"><option value="first_charge" <?= (string) $coupon['duration'] === 'first_charge' ? 'selected' : '' ?>>Primeira cobrança</option><option value="recurring" <?= (string) $coupon['duration'] === 'recurring' ? 'selected' : '' ?>>Todas as mensalidades</option></select></label>
                        <label class="field"><span>Pagamento</span><select name="payment_method"><option value="all" <?= (string) $coupon['payment_method'] === 'all' ? 'selected' : '' ?>>Cartão e Pix</option><option value="credit_card" <?= (string) $coupon['payment_method'] === 'credit_card' ? 'selected' : '' ?>>Somente cartão</option><option value="pix" <?= (string) $coupon['payment_method'] === 'pix' ? 'selected' : '' ?>>Somente Pix</option></select></label>
                        <label class="field"><span>Início</span><input name="starts_at" type="datetime-local" value="<?= View::e($localDateTime($coupon['starts_at'] ?? null)) ?>"></label>
                        <label class="field"><span>Fim</span><input name="ends_at" type="datetime-local" value="<?= View::e($localDateTime($coupon['ends_at'] ?? null)) ?>"></label>
                        <label class="field"><span>Limite total</span><input name="max_redemptions" type="number" min="1" value="<?= View::e((string) ($coupon['max_redemptions'] ?? '')) ?>" placeholder="Sem limite"></label>
                        <label class="field"><span>Usos por e-mail</span><input name="max_redemptions_per_email" type="number" min="1" max="100" value="<?= (int) ($coupon['max_redemptions_per_email'] ?? 1) ?>"></label>
                        <label class="field"><span>Valor mínimo</span><input name="minimum_amount" type="number" min="0" step="0.01" value="<?= View::e((string) ($coupon['minimum_amount'] ?? 0)) ?>"></label>
                        <label class="toggle-card"><input type="checkbox" name="active" value="1" <?= !empty($coupon['active']) ? 'checked' : '' ?>><span><strong>Cupom ativo</strong><small>Desmarque para pausar sem perder o histórico.</small></span></label>
                        <label class="field public-coupon-description"><span>Descrição</span><textarea name="description" rows="2" maxlength="255"><?= View::e((string) ($coupon['description'] ?? '')) ?></textarea></label>
                    </div>
                    <div class="public-coupon-edit-footer">
                        <div><strong><?= number_format((int) ($coupon['reserved_count'] ?? 0), 0, ',', '.') ?></strong> checkout(s) em andamento · <strong><?= View::e($money($coupon['total_discount'] ?? 0)) ?></strong> concedidos</div>
                        <button class="btn btn-primary" type="submit">Salvar alterações</button>
                    </div>
                </form>
                <form method="post" action="<?= View::e(Router::url('/settings/public-signup/coupons/toggle')) ?>" class="public-coupon-toggle-form">
                    <?= Csrf::input() ?><input type="hidden" name="id" value="<?= (int) $coupon['id'] ?>"><input type="hidden" name="active" value="<?= !empty($coupon['active']) ? '0' : '1' ?>">
                    <button class="btn btn-quiet" type="submit"><?= !empty($coupon['active']) ? 'Pausar cupom' : 'Reativar cupom' ?></button>
                </form>
            </details>
        <?php endforeach; endif; ?>
    </div>
</section>

<section class="card mt-24">
    <div class="card-header"><div><span class="eyebrow">Acompanhamento</span><h2>Inscrições recentes</h2><p>Checkout, cupom, provisionamento e eventuais falhas.</p></div></div>
    <div class="table-responsive"><table><thead><tr><th>Empresa</th><th>Status</th><th>Pagamento</th><th>Cupom</th><th>Valor</th><th>Conta</th><th>Criado em</th></tr></thead><tbody>
    <?php if ($recent === []): ?><tr><td colspan="7" class="empty-state">Nenhuma inscrição iniciada.</td></tr><?php else: foreach ($recent as $row): ?>
        <tr>
            <td><strong><?= View::e((string) $row['company_name']) ?></strong><small><?= View::e((string) $row['email']) ?></small></td>
            <td><span class="status-pill status-<?= View::e((string) $row['status']) ?>"><?= View::e((string) $row['status']) ?></span><?php if (!empty($row['last_error'])): ?><small title="<?= View::e((string) $row['last_error']) ?>">Passe o mouse para ver o erro</small><?php endif; ?></td>
            <td><?= (string) ($row['payment_method'] ?? 'credit_card') === 'pix' ? 'Pix QR Code' : 'Cartão' ?></td>
            <td><?= !empty($row['coupon_code']) ? '<strong>' . View::e((string) $row['coupon_code']) . '</strong><small>− ' . View::e($money($row['discount_amount'] ?? 0)) . '</small>' : '—' ?></td>
            <td><strong><?= View::e($money($row['amount'] ?? 0)) ?></strong><?php if ((float) ($row['original_amount'] ?? 0) > (float) ($row['amount'] ?? 0)): ?><small>de <?= View::e($money($row['original_amount'])) ?></small><?php endif; ?></td>
            <td><?= !empty($row['tenant_name']) ? View::e((string) $row['tenant_name']) : '—' ?></td>
            <td><?= View::e(date('d/m/Y H:i', strtotime((string) $row['created_at']))) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody></table></div>
</section>
