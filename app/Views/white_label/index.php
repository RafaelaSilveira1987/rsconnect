<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
use App\Services\BrandingService;

$selected = $selected ?? null;
$companies = $companies ?? [];
$active = static fn ($value, $fallback = '') => View::e((string) (($selected[$value] ?? '') !== '' ? $selected[$value] : $fallback));
$previewName = (string) (($selected['brand_name'] ?? '') ?: ($selected['name'] ?? 'Empresa cliente'));
$previewSubtitle = (string) (($selected['brand_subtitle'] ?? '') ?: 'Atendimento e CRM');
$iconText = mb_substr((string) (($selected['brand_icon_text'] ?? '') ?: 'CL'), 0, 4);
$primary = (string) (($selected['brand_primary_color'] ?? '') ?: '#146498');
$secondary = (string) (($selected['brand_secondary_color'] ?? '') ?: '#631b7c');
$accent = (string) (($selected['brand_accent_color'] ?? '') ?: '#01c5b6');
$previewLogoUrl = BrandingService::assetUrl((string) ($selected['brand_logo_url'] ?? ''));
$previewFaviconUrl = BrandingService::assetUrl((string) ($selected['brand_favicon_url'] ?? ''));
?>
<section class="hero-card compact-hero hero-admin">
    <div>
        <span class="eyebrow light">Identidade visual por empresa</span>
        <h2>White label básico para o painel do cliente</h2>
        <p>Configure nome, marca, cores, tela de login e domínio personalizado sem expor configurações técnicas ao cliente.</p>
    </div>
    <span class="hero-badge">RS Connect Admin</span>
</section>

<div class="content-grid two-columns white-label-layout">
    <section class="card">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Empresa</span>
                <h2>Selecionar cliente</h2>
            </div>
        </div>

        <form method="get" action="<?= View::e(Router::url('/white-label')) ?>" class="inline-form-panel">
            <label class="field" style="margin:0">
                <span>Cliente</span>
                <select name="tenant_id" onchange="this.form.submit()">
                    <?php foreach ($companies as $company): ?>
                        <option value="<?= (int) $company['id'] ?>" <?= $selected && (int) $selected['id'] === (int) $company['id'] ? 'selected' : '' ?>>
                            <?= View::e($company['name']) ?><?= (int) ($company['white_label_enabled'] ?? 0) === 1 ? ' — ativo' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>

        <?php if (!$selected): ?>
            <p class="empty-state">Cadastre uma empresa antes de configurar white label.</p>
        <?php else: ?>
            <form method="post" action="<?= View::e(Router::url('/white-label/save')) ?>" class="white-label-form" enctype="multipart/form-data">
                <?= Csrf::input() ?>
                <input type="hidden" name="tenant_id" value="<?= (int) $selected['id'] ?>">

                <div class="form-divider">Identidade do painel</div>

                <label class="check-field switch-field">
                    <input type="checkbox" name="white_label_enabled" value="1" <?= (int) ($selected['white_label_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <span>Ativar white label para esta empresa</span>
                </label>

                <div class="form-grid two">
                    <label class="field">
                        <span>Nome exibido no painel</span>
                        <input name="brand_name" value="<?= $active('brand_name', $selected['name'] ?? '') ?>" placeholder="Nome da marca do cliente">
                    </label>
                    <label class="field">
                        <span>Subtítulo</span>
                        <input name="brand_subtitle" value="<?= $active('brand_subtitle', 'Atendimento e CRM') ?>" placeholder="Atendimento digital">
                    </label>
                </div>

                <div class="form-grid two">
                    <label class="field">
                        <span>Texto do ícone</span>
                        <input name="brand_icon_text" maxlength="4" value="<?= $active('brand_icon_text', 'RS') ?>" placeholder="Ex.: MB">
                    </label>
                    <label class="field">
                        <span>Texto do rodapé</span>
                        <input name="brand_footer_text" value="<?= $active('brand_footer_text') ?>" placeholder="Ex.: Clínica Mariana">
                    </label>
                </div>

                <div class="form-grid two">
                    <label class="field upload-field">
                        <span>Logo por arquivo</span>
                        <input type="file" name="brand_logo_file" accept=".png,.jpg,.jpeg,.webp,.svg,image/png,image/jpeg,image/webp,image/svg+xml">
                        <small class="field-hint">PNG, JPG, WEBP ou SVG até 2MB. Ao enviar, substitui a URL atual.</small>
                    </label>
                    <label class="field upload-field">
                        <span>Favicon por arquivo</span>
                        <input type="file" name="brand_favicon_file" accept=".png,.jpg,.jpeg,.webp,.svg,.ico,image/png,image/jpeg,image/webp,image/svg+xml,image/x-icon">
                        <small class="field-hint">Ideal: PNG/ICO quadrado. Ao enviar, substitui a URL atual.</small>
                    </label>
                </div>

                <details class="advanced-fields">
                    <summary>Usar imagem por URL externa</summary>
                    <div class="form-grid two" style="margin-top:12px">
                        <label class="field">
                            <span>URL da logo</span>
                            <input name="brand_logo_url" value="<?= $active('brand_logo_url') ?>" placeholder="https://.../logo.png ou /uploads/...">
                        </label>
                        <label class="field">
                            <span>URL do favicon</span>
                            <input name="brand_favicon_url" value="<?= $active('brand_favicon_url') ?>" placeholder="https://.../favicon.png ou /uploads/...">
                        </label>
                    </div>
                </details>

                <div class="form-divider">Cores</div>
                <div class="form-grid three color-grid">
                    <label class="field color-field">
                        <span>Cor primária</span>
                        <input type="color" name="brand_primary_color" value="<?= $active('brand_primary_color', '#146498') ?>">
                    </label>
                    <label class="field color-field">
                        <span>Cor secundária</span>
                        <input type="color" name="brand_secondary_color" value="<?= $active('brand_secondary_color', '#631b7c') ?>">
                    </label>
                    <label class="field color-field">
                        <span>Cor de destaque</span>
                        <input type="color" name="brand_accent_color" value="<?= $active('brand_accent_color', '#01c5b6') ?>">
                    </label>
                </div>

                <div class="form-divider">Login e acesso</div>
                <label class="field">
                    <span>Título da tela de login</span>
                    <input name="login_title" value="<?= $active('login_title') ?>" placeholder="Acesse sua central de atendimento">
                </label>
                <label class="field">
                    <span>Subtítulo da tela de login</span>
                    <textarea name="login_subtitle" rows="3" placeholder="Explique de forma curta o que o cliente acessa no painel."><?= $active('login_subtitle') ?></textarea>
                </label>
                <div class="form-grid two">
                    <label class="field">
                        <span>Domínio personalizado</span>
                        <input name="custom_domain" value="<?= $active('custom_domain') ?>" placeholder="painel.cliente.com.br">
                    </label>
                    <label class="field">
                        <span>E-mail de suporte</span>
                        <input type="email" name="support_email" value="<?= $active('support_email') ?>" placeholder="suporte@cliente.com.br">
                    </label>
                </div>

                <label class="check-field switch-field">
                    <input type="checkbox" name="show_powered_by" value="1" <?= (int) ($selected['show_powered_by'] ?? 1) === 1 ? 'checked' : '' ?>>
                    <span>Exibir “Powered by RS Connect” no rodapé</span>
                </label>

                <div class="form-actions">
                    <a class="btn btn-outline" href="<?= View::e(Router::url('/white-label/preview?tenant_id=' . (int) ($selected['id'] ?? 0))) ?>" target="_blank">Pré-visualizar login</a>
                    <button class="btn btn-primary" type="submit">Salvar white label</button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <aside class="stack">
        <section class="card white-label-preview-card" style="--preview-primary: <?= View::e($primary) ?>; --preview-secondary: <?= View::e($secondary) ?>; --preview-accent: <?= View::e($accent) ?>;">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">Prévia</span>
                    <h2>Painel do cliente</h2>
                </div>
                <span class="badge <?= (int) ($selected['white_label_enabled'] ?? 0) === 1 ? 'badge-active' : 'badge-pending' ?>">
                    <?= (int) ($selected['white_label_enabled'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?>
                </span>
            </div>
            <div class="brand-preview-shell">
                <div class="brand-preview-sidebar">
                    <?php if ($previewLogoUrl !== ''): ?>
                        <img src="<?= View::e($previewLogoUrl) ?>" alt="Logo" class="brand-preview-logo">
                    <?php else: ?>
                        <span class="brand-preview-mark"><?= View::e($iconText) ?></span>
                    <?php endif; ?>
                    <div>
                        <strong><?= View::e($previewName) ?></strong>
                        <small><?= View::e($previewSubtitle) ?></small>
                    </div>
                </div>
                <div class="brand-preview-content">
                    <span></span><span></span><span></span>
                    <div class="brand-preview-card-mini"></div>
                    <div class="brand-preview-line"></div>
                    <div class="brand-preview-line short"></div>
                </div>
            </div>
            <p class="field-hint">A prévia abre sem passar pelo bloqueio de usuário logado. No cliente real, a identidade aparece para usuários da empresa e também pode funcionar por domínio personalizado.</p>
        </section>

        <section class="card">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">O que este ZIP entrega</span>
                    <h2>White label básico</h2>
                </div>
            </div>
            <ul class="steps white-label-steps">
                <li class="is-done"><span>1</span><div><strong>Marca no painel</strong><small>Nome, ícone ou logo e subtítulo.</small></div></li>
                <li class="is-done"><span>2</span><div><strong>Cores do cliente</strong><small>Primária, secundária e destaque.</small></div></li>
                <li class="is-done"><span>3</span><div><strong>Login personalizado</strong><small>Texto e identidade por slug ou domínio.</small></div></li>
                <li class="is-done"><span>4</span><div><strong>Controle RS</strong><small>Somente Super Admin edita.</small></div></li>
            </ul>
        </section>
    </aside>
</div>
