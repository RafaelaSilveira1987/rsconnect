<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
?>
<div class="content-grid management-layout">
    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Clientes do SaaS</span><h2>Empresas cadastradas</h2></div>
            <span class="badge"><?= count($companies) ?> empresa(s)</span>
        </div>

        <div class="company-list">
            <?php foreach ($companies as $company): ?>
                <article class="company-card">
                    <div class="company-card-main">
                        <span class="company-avatar"><?= View::e(mb_strtoupper(mb_substr($company['name'], 0, 2))) ?></span>
                        <div>
                            <h3><?= View::e($company['name']) ?></h3>
                            <p><?= View::e($company['email'] ?: 'E-mail comercial não informado') ?></p>
                            <div class="inline-meta">
                                <span><?= (int) $company['users_count'] ?> usuário(s)</span>
                                <span><?= (int) $company['instances_count'] ?> instância(s)</span>
                                <span><?= (int) $company['agents_count'] ?> agente(s)</span>
                            </div>
                        </div>
                    </div>

                    <div class="company-card-actions">
                        <div class="badge-row">
                            <span class="badge"><?= View::e(ucfirst($company['plan'])) ?></span>
                            <span class="badge badge-<?= View::e($company['status']) ?>"><?= View::e(ucfirst($company['status'])) ?></span>
                            <?php if ($company['onboarding_completed_at']): ?>
                                <span class="badge badge-active">Onboarding concluído</span>
                            <?php else: ?>
                                <span class="badge badge-pending">Etapa <?= (int) $company['onboarding_step'] ?>/3</span>
                            <?php endif; ?>
                        </div>
                        <div class="action-row">
                            <a class="btn btn-soft" href="<?= View::e(Router::url('/company-settings?id=' . (int) $company['id'])) ?>">Editar dados</a>
                            <details class="compact-details">
                                <summary class="btn btn-outline">Plano e status</summary>
                                <form class="popover-form" method="post" action="<?= View::e(Router::url('/companies/status')) ?>">
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="tenant_id" value="<?= (int) $company['id'] ?>">
                                    <label class="field"><span>Plano</span>
                                        <select name="plan">
                                            <?php foreach (['starter' => 'Starter', 'pro' => 'Pro', 'business' => 'Business', 'custom' => 'Custom'] as $value => $label): ?>
                                                <option value="<?= $value ?>" <?= $company['plan'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="field"><span>Status</span>
                                        <select name="status">
                                            <?php foreach (['active' => 'Ativa', 'inactive' => 'Inativa', 'suspended' => 'Suspensa'] as $value => $label): ?>
                                                <option value="<?= $value ?>" <?= $company['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button class="btn btn-primary btn-block" type="submit">Atualizar</button>
                                </form>
                            </details>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$companies): ?><div class="empty-state">Nenhuma empresa cadastrada.</div><?php endif; ?>
        </div>
    </section>

    <aside class="stack">
        <form class="card sticky-card" method="post" action="<?= View::e(Router::url('/companies')) ?>">
            <?= Csrf::input() ?>
            <div class="section-heading"><div><span class="eyebrow">Novo cliente</span><h2>Cadastrar empresa</h2></div></div>

            <label class="field"><span>Nome da empresa</span><input name="name" placeholder="Clínica Exemplo" required></label>
            <label class="field"><span>Razão social</span><input name="legal_name" placeholder="Clínica Exemplo Ltda."></label>
            <div class="form-grid two">
                <label class="field"><span>CNPJ/CPF</span><input name="document" placeholder="00.000.000/0001-00"></label>
                <label class="field"><span>Plano</span><select name="plan"><option value="starter">Starter</option><option value="pro">Pro</option><option value="business">Business</option><option value="custom">Custom</option></select></label>
            </div>
            <label class="field"><span>E-mail comercial</span><input type="email" name="email" placeholder="contato@empresa.com"></label>
            <div class="form-grid two">
                <label class="field"><span>Telefone</span><input name="phone" placeholder="(11) 99999-9999"></label>
                <label class="field"><span>Segmento</span><input name="segment" placeholder="Clínica, comércio, serviços..."></label>
            </div>

            <div class="form-divider"><span>Administrador do cliente</span></div>
            <label class="field"><span>Nome</span><input name="owner_name" placeholder="Responsável pela conta" required></label>
            <label class="field"><span>E-mail de acesso</span><input type="email" name="owner_email" placeholder="admin@empresa.com" required></label>
            <label class="field"><span>Senha inicial</span><input type="password" name="owner_password" minlength="8" placeholder="Mínimo de 8 caracteres" required></label>

            <button class="btn btn-primary btn-block" type="submit">Criar empresa e acesso</button>
        </form>
    </aside>
</div>
