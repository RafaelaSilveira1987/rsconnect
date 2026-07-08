<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
?>
<div class="hero-card compact-hero">
    <div>
        <span class="eyebrow light">Perfil empresarial</span>
        <h2><?= View::e($company['name']) ?></h2>
        <p>Esses dados identificam a empresa dentro do RS Connect e serão usados nos módulos de atendimento e automação.</p>
    </div>
    <?php if (Auth::isSuperAdmin()): ?><a class="btn btn-light" href="<?= View::e(Router::url('/companies')) ?>">Voltar às empresas</a><?php endif; ?>
</div>

<form class="card form-card-wide" method="post" action="<?= View::e(Router::url('/company-settings')) ?>">
    <?= Csrf::input() ?>
    <input type="hidden" name="tenant_id" value="<?= (int) $company['id'] ?>">
    <div class="section-heading">
        <div><span class="eyebrow">Dados cadastrais</span><h2>Informações da empresa</h2></div>
        <span class="badge badge-<?= View::e($company['status']) ?>"><?= View::e(ucfirst($company['status'])) ?></span>
    </div>

    <div class="form-grid two">
        <label class="field"><span>Nome de exibição</span><input name="name" value="<?= View::e($company['name']) ?>" required></label>
        <label class="field"><span>Razão social</span><input name="legal_name" value="<?= View::e($company['legal_name'] ?? '') ?>"></label>
        <label class="field"><span>CNPJ/CPF</span><input name="document" value="<?= View::e($company['document'] ?? '') ?>"></label>
        <label class="field"><span>Segmento</span><input name="segment" value="<?= View::e($company['segment'] ?? '') ?>" placeholder="Clínica, comércio, imobiliária..."></label>
        <label class="field"><span>E-mail comercial</span><input type="email" name="email" value="<?= View::e($company['email'] ?? '') ?>"></label>
        <label class="field"><span>Telefone</span><input name="phone" value="<?= View::e($company['phone'] ?? '') ?>"></label>
    </div>
    <label class="field"><span>Site</span><input type="url" name="website" value="<?= View::e($company['website'] ?? '') ?>" placeholder="https://empresa.com.br"></label>

    <div class="readonly-grid">
        <div><span>Slug</span><strong><?= View::e($company['slug']) ?></strong></div>
        <div><span>Plano</span><strong><?= View::e(ucfirst($company['plan'])) ?></strong></div>
        <div><span>Onboarding</span><strong><?= $company['onboarding_completed_at'] ? 'Concluído' : 'Etapa ' . (int) $company['onboarding_step'] . '/3' ?></strong></div>
    </div>

    <?php if (Auth::can('company.manage')): ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar alterações</button></div>
    <?php endif; ?>
</form>
