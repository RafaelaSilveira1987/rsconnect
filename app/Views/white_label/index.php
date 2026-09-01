<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
use App\Services\BrandingService;

$selected = $selected ?? null;
$companies = $companies ?? [];
$previewLogoUrl = BrandingService::assetUrl((string) ($selected['brand_logo_url'] ?? ''));
$hasLogo = $previewLogoUrl !== '';
$previewBranding = $selected ? BrandingService::forTenantId((int) $selected['id']) : BrandingService::defaults();
$previewInitials = (string) ($previewBranding['icon_text'] ?? 'EP');
?>
<style>
    .white-label-upload-thumb,
    .white-label-preview-mark {
        background: linear-gradient(180deg, #f8fbfd 0%, #edf3f8 100%);
        border: 1px solid rgba(20, 100, 152, 0.14);
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.85), 0 10px 22px rgba(15, 23, 42, 0.08);
    }
    .white-label-upload-thumb img,
    .white-label-preview-mark img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        display: block;
        border-radius: 10px;
    }
</style>
<section class="hero-card compact-hero hero-admin white-label-hero">
    <div>
        <span class="eyebrow light">Identidade visual por empresa</span>
        <h2>Logo do cliente</h2>
        <p>Personalize somente a logo. O nome exibido vem automaticamente do cadastro da empresa; cores, textos, favicon e demais elementos permanecem no padrão da RS Connect.</p>
    </div>
    <span class="hero-badge">RS Connect Admin</span>
</section>

<div class="content-grid two-columns white-label-layout white-label-pro-layout">
    <section class="card white-label-config-card">
        <div class="section-heading white-label-company-heading">
            <div>
                <span class="eyebrow">Empresa</span>
                <h2>Selecionar cliente</h2>
            </div>
        </div>

        <?php if ($companies === []): ?>
            <p class="empty-state">Cadastre uma empresa antes de configurar a logo.</p>
        <?php else: ?>
            <form method="get" action="<?= View::e(Router::url('/white-label')) ?>" class="inline-form-panel white-label-company-picker">
                <label class="field white-label-company-field">
                    <span>Cliente</span>
                    <select name="tenant_id" data-auto-submit>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?= (int) $company['id'] ?>" <?= $selected && (int) $selected['id'] === (int) $company['id'] ? 'selected' : '' ?>>
                                <?= View::e($company['name']) ?><?= trim((string) ($company['brand_logo_url'] ?? '')) !== '' ? ' — com logo' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        <?php endif; ?>

        <?php if ($selected): ?>
            <form method="post" action="<?= View::e(Router::url('/white-label/save')) ?>" class="white-label-form" data-white-label-form enctype="multipart/form-data">
                <?= Csrf::input() ?>
                <input type="hidden" name="tenant_id" value="<?= (int) $selected['id'] ?>">

                <div class="form-divider">Logo exibida no painel</div>
                <p class="white-label-section-intro">
                    Envie uma imagem PNG, JPG/JPEG ou WEBP de até 2 MB. Ao salvar uma logo, a personalização é ativada automaticamente para o cliente.
                </p>

                <article class="white-label-upload-card">
                    <div class="white-label-upload-head">
                        <div>
                            <span class="eyebrow">Arquivo principal</span>
                            <strong><?= $hasLogo ? 'Logo atual do cliente' : 'Nenhuma logo personalizada' ?></strong>
                        </div>
                        <span class="white-label-upload-thumb is-horizontal">
                            <?php if ($hasLogo): ?>
                                <img src="<?= View::e($previewLogoUrl) ?>" alt="Logo atual de <?= View::e((string) $selected['name']) ?>">
                            <?php else: ?>
                                <b><?= View::e($previewInitials) ?></b>
                            <?php endif; ?>
                        </span>
                    </div>

                    <label class="field upload-field">
                        <span><?= $hasLogo ? 'Substituir logo' : 'Selecionar logo' ?></span>
                        <input type="file" name="brand_logo_file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                    </label>
                    <small class="field-hint">Dimensão máxima: 4096 × 4096 pixels. Para melhor leitura, prefira uma logo horizontal com fundo transparente.</small>

                    <?php if ($hasLogo): ?>
                        <label class="mini-check">
                            <input type="checkbox" name="remove_logo" value="1">
                            Remover a logo e usar as iniciais da empresa
                        </label>
                    <?php endif; ?>
                </article>

                <div class="notice info" style="margin-top: 18px;">
                    <strong>O que permanece fixo</strong>
                    <p>A paleta, os textos, o favicon, o login, o rodapé e os demais elementos visuais continuam padronizados. O nome usado no painel é o nome cadastrado da empresa.</p>
                </div>

                <div class="form-actions sticky-actions">
                    <button class="button primary" type="submit">Salvar logo</button>
                    <?php if ($hasLogo): ?>
                        <a class="button ghost" target="_blank" rel="noopener" href="<?= View::e(Router::url('/white-label/preview?tenant_id=' . (int) $selected['id'])) ?>">Visualizar aplicação</a>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <aside class="card white-label-preview-card">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Prévia</span>
                <h2>Como será exibido</h2>
            </div>
            <span class="status-pill <?= $hasLogo ? 'success' : 'neutral' ?>"><?= $hasLogo ? 'Logo personalizada' : 'Padrão RS Connect' ?></span>
        </div>

        <div class="white-label-preview-shell">
            <div class="white-label-preview-sidebar">
                <div class="white-label-preview-brand">
                    <span class="white-label-preview-mark">
                        <?php if ($hasLogo): ?>
                            <img src="<?= View::e($previewLogoUrl) ?>" alt="Prévia da logo">
                        <?php else: ?>
                            <b><?= View::e($previewInitials) ?></b>
                        <?php endif; ?>
                    </span>
                    <span><strong><?= View::e((string) ($selected['name'] ?? 'Empresa')) ?></strong><small>Atendimento e Comercial</small></span>
                </div>
                <span class="white-label-preview-line is-active"></span>
                <span class="white-label-preview-line"></span>
                <span class="white-label-preview-line"></span>
            </div>
            <div class="white-label-preview-content">
                <span class="eyebrow">Painel do cliente</span>
                <h3>Nome e logo da empresa no painel</h3>
                <p>O nome é obtido do cadastro e a única imagem configurável é a logo.</p>
                <div class="white-label-preview-metrics">
                    <span></span><span></span><span></span>
                </div>
            </div>
        </div>

        <div class="notice neutral" style="margin-top: 18px;">
            <strong><?= View::e((string) ($selected['name'] ?? 'Selecione um cliente')) ?></strong>
            <p><?= $hasLogo ? 'A logo enviada será usada ao lado do nome cadastrado da empresa.' : 'Enquanto nenhuma logo for enviada, o painel mostra as iniciais e o nome cadastrado da empresa.' ?></p>
        </div>
    </aside>
</div>
