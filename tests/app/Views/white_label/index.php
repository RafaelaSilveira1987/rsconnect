<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
use App\Services\BrandingService;

$selected = $selected ?? null;
$companies = $companies ?? [];
$active = static fn ($value, $fallback = '') => View::e((string) (($selected[$value] ?? '') !== '' ? $selected[$value] : $fallback));
$raw = static fn ($value, $fallback = '') => (string) (($selected[$value] ?? '') !== '' ? $selected[$value] : $fallback);
$previewName = $raw('brand_name', $selected['name'] ?? 'Empresa cliente');
$previewSubtitle = $raw('brand_subtitle', 'Atendimento e CRM');
$iconText = mb_substr($raw('brand_icon_text', 'CL'), 0, 4);
$primary = $raw('brand_primary_color', '#146498');
$secondary = $raw('brand_secondary_color', '#631b7c');
$accent = $raw('brand_accent_color', '#01c5b6');
$loginBg = $raw('login_background_color', '#07111f');
$loginText = $raw('login_text_color', '#ffffff');
$previewLogoUrl = BrandingService::assetUrl((string) ($selected['brand_logo_url'] ?? ''));
$previewIconUrl = BrandingService::assetUrl((string) ($selected['brand_icon_url'] ?? ''));
$previewFaviconUrl = BrandingService::assetUrl((string) ($selected['brand_favicon_url'] ?? ''));
$logoVariant = $raw('brand_logo_variant', 'horizontal');
$logoBackground = $raw('brand_logo_background', 'light');
?>
<section class="hero-card compact-hero hero-admin">
    <div>
        <span class="eyebrow light">Identidade visual por empresa</span>
        <h2>White label profissional para o painel do cliente</h2>
        <p>Configure login, marca, logo, ícone, favicon, textos, cores e prévia real sem expor configurações técnicas ao cliente.</p>
    </div>
    <span class="hero-badge">RS Connect Admin</span>
</section>

<div class="content-grid two-columns white-label-layout white-label-pro-layout">
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

                <label class="check-field switch-field switch-field-strong">
                    <input type="checkbox" name="white_label_enabled" value="1" <?= (int) ($selected['white_label_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <span><strong>Ativar white label para esta empresa</strong><small>Quando ativo, usuários da empresa veem marca, cores e login personalizados.</small></span>
                </label>

                <div class="form-grid two">
                    <label class="field">
                        <span>Nome exibido no painel</span>
                        <input name="brand_name" value="<?= $active('brand_name', $selected['name'] ?? '') ?>" placeholder="Nome da marca do cliente">
                    </label>
                    <label class="field">
                        <span>Subtítulo do painel</span>
                        <input name="brand_subtitle" value="<?= $active('brand_subtitle', 'Atendimento e CRM') ?>" placeholder="Atendimento digital">
                    </label>
                </div>

                <div class="form-grid three">
                    <label class="field">
                        <span>Tipo de logo</span>
                        <select name="brand_logo_variant">
                            <option value="horizontal" <?= $logoVariant === 'horizontal' ? 'selected' : '' ?>>Logo horizontal</option>
                            <option value="square" <?= $logoVariant === 'square' ? 'selected' : '' ?>>Ícone quadrado</option>
                            <option value="symbol" <?= $logoVariant === 'symbol' ? 'selected' : '' ?>>Símbolo simples</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Fundo da logo</span>
                        <select name="brand_logo_background">
                            <option value="light" <?= $logoBackground === 'light' ? 'selected' : '' ?>>Claro</option>
                            <option value="transparent" <?= $logoBackground === 'transparent' ? 'selected' : '' ?>>Transparente</option>
                            <option value="brand" <?= $logoBackground === 'brand' ? 'selected' : '' ?>>Cor da marca</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Texto fallback do ícone</span>
                        <input name="brand_icon_text" maxlength="4" value="<?= $active('brand_icon_text', 'RS') ?>" placeholder="Ex.: MB">
                    </label>
                </div>

                <div class="form-divider">Arquivos da marca</div>
                <div class="white-label-upload-preview-grid">
                    <label class="field upload-field">
                        <span>Logo principal</span>
                        <input type="file" name="brand_logo_file" accept=".png,.jpg,.jpeg,.webp,.svg,image/png,image/jpeg,image/webp,image/svg+xml">
                        <small class="field-hint">Use a logo horizontal quando existir. Ela aparece no login e prévias.</small>
                        <?php if ($previewLogoUrl !== ''): ?>
                            <label class="mini-check"><input type="checkbox" name="remove_logo" value="1"> Remover logo atual</label>
                        <?php endif; ?>
                    </label>
                    <label class="field upload-field">
                        <span>Ícone reduzido</span>
                        <input type="file" name="brand_icon_file" accept=".png,.jpg,.jpeg,.webp,.svg,image/png,image/jpeg,image/webp,image/svg+xml">
                        <small class="field-hint">Ideal para sidebar, card de login e espaços quadrados.</small>
                        <?php if ($previewIconUrl !== ''): ?>
                            <label class="mini-check"><input type="checkbox" name="remove_icon" value="1"> Remover ícone atual</label>
                        <?php endif; ?>
                    </label>
                    <label class="field upload-field">
                        <span>Favicon</span>
                        <input type="file" name="brand_favicon_file" accept=".png,.jpg,.jpeg,.webp,.svg,.ico,image/png,image/jpeg,image/webp,image/svg+xml,image/x-icon">
                        <small class="field-hint">Ideal: PNG/ICO quadrado para a aba do navegador.</small>
                        <?php if ($previewFaviconUrl !== ''): ?>
                            <label class="mini-check"><input type="checkbox" name="remove_favicon" value="1"> Remover favicon atual</label>
                        <?php endif; ?>
                    </label>
                </div>

                <details class="advanced-fields">
                    <summary>Usar imagens por URL externa</summary>
                    <div class="form-grid three" style="margin-top:12px">
                        <label class="field">
                            <span>URL da logo principal</span>
                            <input name="brand_logo_url" value="<?= $active('brand_logo_url') ?>" placeholder="https://.../logo.png ou /uploads/...">
                        </label>
                        <label class="field">
                            <span>URL do ícone reduzido</span>
                            <input name="brand_icon_url" value="<?= $active('brand_icon_url') ?>" placeholder="https://.../icone.png ou /uploads/...">
                        </label>
                        <label class="field">
                            <span>URL do favicon</span>
                            <input name="brand_favicon_url" value="<?= $active('brand_favicon_url') ?>" placeholder="https://.../favicon.png ou /uploads/...">
                        </label>
                    </div>
                </details>

                <div class="form-divider">Cores</div>
                <div class="form-grid five color-grid">
                    <label class="field color-field">
                        <span>Primária</span>
                        <input type="color" name="brand_primary_color" value="<?= $active('brand_primary_color', '#146498') ?>">
                    </label>
                    <label class="field color-field">
                        <span>Secundária</span>
                        <input type="color" name="brand_secondary_color" value="<?= $active('brand_secondary_color', '#631b7c') ?>">
                    </label>
                    <label class="field color-field">
                        <span>Destaque</span>
                        <input type="color" name="brand_accent_color" value="<?= $active('brand_accent_color', '#01c5b6') ?>">
                    </label>
                    <label class="field color-field">
                        <span>Fundo login</span>
                        <input type="color" name="login_background_color" value="<?= $active('login_background_color', '#07111f') ?>">
                    </label>
                    <label class="field color-field">
                        <span>Texto login</span>
                        <input type="color" name="login_text_color" value="<?= $active('login_text_color', '#ffffff') ?>">
                    </label>
                </div>

                <div class="form-divider">Login personalizado</div>
                <div class="form-grid two">
                    <label class="field">
                        <span>Texto pequeno acima do título</span>
                        <input name="login_eyebrow" value="<?= $active('login_eyebrow', $previewSubtitle) ?>" placeholder="Ex.: Portal do cliente">
                    </label>
                    <label class="field">
                        <span>Texto do botão</span>
                        <input name="login_button_text" value="<?= $active('login_button_text', 'Acessar painel') ?>" placeholder="Acessar painel">
                    </label>
                </div>
                <label class="field">
                    <span>Título da tela de login</span>
                    <input name="login_title" value="<?= $active('login_title') ?>" placeholder="Acesse o painel da sua empresa">
                </label>
                <label class="field">
                    <span>Descrição da tela de login</span>
                    <textarea name="login_subtitle" rows="3" placeholder="Explique de forma curta o que o cliente acessa no painel."><?= $active('login_subtitle') ?></textarea>
                </label>
                <div class="form-grid three">
                    <label class="field"><span>Benefício 1</span><input name="login_benefit_1" value="<?= $active('login_benefit_1', 'Atendimento centralizado') ?>"></label>
                    <label class="field"><span>Benefício 2</span><input name="login_benefit_2" value="<?= $active('login_benefit_2', 'Agenda e CRM integrados') ?>"></label>
                    <label class="field"><span>Benefício 3</span><input name="login_benefit_3" value="<?= $active('login_benefit_3', 'Operação com IA') ?>"></label>
                </div>
                <label class="field">
                    <span>Texto de segurança abaixo do botão</span>
                    <input name="login_security_text" value="<?= $active('login_security_text', 'Ambiente seguro para administradores, equipes e clientes.') ?>">
                </label>

                <div class="form-divider">Acesso e suporte</div>
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
                <label class="field">
                    <span>Texto do rodapé</span>
                    <input name="brand_footer_text" value="<?= $active('brand_footer_text') ?>" placeholder="Ex.: Clínica Mariana">
                </label>

                <label class="check-field switch-field">
                    <input type="checkbox" name="show_powered_by" value="1" <?= (int) ($selected['show_powered_by'] ?? 1) === 1 ? 'checked' : '' ?>>
                    <span>Exibir “Powered by RS Connect” quando não houver texto de rodapé</span>
                </label>

                <div class="form-actions sticky-actions">
                    <a class="btn btn-outline" href="<?= View::e(Router::url('/white-label/preview?tenant_id=' . (int) ($selected['id'] ?? 0))) ?>" target="_blank" rel="noopener">Pré-visualizar login</a>
                    <?php if (($selected['slug'] ?? '') !== ''): ?>
                        <a class="btn btn-outline" href="<?= View::e(Router::url('/login?tenant=' . urlencode((string) $selected['slug']))) ?>" target="_blank" rel="noopener">Abrir login real</a>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="submit">Salvar white label</button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <aside class="stack">
        <section class="card white-label-preview-card" style="--preview-primary: <?= View::e($primary) ?>; --preview-secondary: <?= View::e($secondary) ?>; --preview-accent: <?= View::e($accent) ?>; --preview-login-bg: <?= View::e($loginBg) ?>; --preview-login-text: <?= View::e($loginText) ?>;">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">Prévia</span>
                    <h2>Painel e login</h2>
                </div>
                <span class="badge <?= (int) ($selected['white_label_enabled'] ?? 0) === 1 ? 'badge-active' : 'badge-pending' ?>">
                    <?= (int) ($selected['white_label_enabled'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?>
                </span>
            </div>
            <div class="brand-preview-shell pro-preview-shell">
                <div class="brand-preview-sidebar">
                    <?php if (($previewIconUrl ?: $previewLogoUrl) !== ''): ?>
                        <span class="brand-image-shell is-<?= View::e($logoVariant) ?> bg-<?= View::e($logoBackground) ?> preview-brand-image">
                            <img src="<?= View::e($previewIconUrl ?: $previewLogoUrl) ?>" alt="Logo" class="brand-preview-logo">
                        </span>
                    <?php else: ?>
                        <span class="brand-preview-mark"><?= View::e($iconText) ?></span>
                    <?php endif; ?>
                    <div>
                        <strong><?= View::e($previewName) ?></strong>
                        <small><?= View::e($previewSubtitle) ?></small>
                    </div>
                </div>
                <div class="brand-preview-login">
                    <span class="preview-window-bar"></span>
                    <strong><?= View::e($raw('login_title', 'Acesse o painel da ' . $previewName)) ?></strong>
                    <p><?= View::e($raw('login_subtitle', 'Gerencie sua operação em ambiente seguro e personalizado.')) ?></p>
                    <i></i><i></i>
                    <button type="button"><?= View::e($raw('login_button_text', 'Acessar painel')) ?></button>
                </div>
            </div>
            <p class="field-hint">Para testar o login real fora da sessão atual, use uma janela anônima ou acesse pelo link com <strong>?tenant=slug-da-empresa</strong>.</p>
        </section>

        <section class="card">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">Checklist visual</span>
                    <h2>Para finalizar o white label</h2>
                </div>
            </div>
            <ul class="steps white-label-steps">
                <li class="is-done"><span>1</span><div><strong>Logo principal</strong><small>Use horizontal para login e cabeçalhos.</small></div></li>
                <li class="is-done"><span>2</span><div><strong>Ícone reduzido</strong><small>Use quadrado para sidebar e card de acesso.</small></div></li>
                <li class="is-done"><span>3</span><div><strong>Textos do login</strong><small>Título, descrição, botão e benefícios por cliente.</small></div></li>
                <li class="is-done"><span>4</span><div><strong>Cores e favicon</strong><small>Identidade aplicada no painel e aba do navegador.</small></div></li>
            </ul>
        </section>
    </aside>
</div>
