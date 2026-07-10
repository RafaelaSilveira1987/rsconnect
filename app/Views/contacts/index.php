<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$statusLabels = ['lead' => 'Lead', 'customer' => 'Cliente', 'inactive' => 'Inativo'];
$formatDate = static function (?string $value): string {
    if (!$value) {
        return 'Sem interação';
    }
    try {
        return (new DateTime($value))->format('d/m/Y H:i');
    } catch (Throwable) {
        return $value;
    }
};
$queryBase = [];
if (($filters['search'] ?? '') !== '') $queryBase['search'] = $filters['search'];
if (($filters['status'] ?? '') !== '') $queryBase['status'] = $filters['status'];
if (($filters['tenant_id'] ?? 0) > 0) $queryBase['tenant_id'] = (int) $filters['tenant_id'];
?>

<div class="page-heading">
    <div>
        <span class="eyebrow">Relacionamento</span>
        <h2>Base de contatos</h2>
        <p>Centralize leads e clientes vindos do WhatsApp ou cadastrados pela equipe.</p>
    </div>
    <?php if ($canManage): ?>
        <details class="action-popover">
            <summary class="btn btn-primary">+ Novo contato</summary>
            <form class="popover-panel form-stack" method="post" action="<?= View::e(Router::url('/contacts')) ?>">
                <?= Csrf::input() ?>
                <strong>Cadastrar contato</strong>
                <?php if (Auth::isSuperAdmin()): ?>
                    <label class="field"><span>Empresa</span><select name="tenant_id" required data-tenant-select>
                        <option value="">Selecione</option>
                        <?php foreach ($tenants as $tenant): ?>
                            <option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <?php endif; ?>
                <div class="form-grid two">
                    <label class="field"><span>Nome</span><input name="name" maxlength="150"></label>
                    <label class="field"><span>Telefone *</span><input name="phone" inputmode="tel" placeholder="5511999999999" required></label>
                </div>
                <div class="form-grid two">
                    <label class="field"><span>E-mail</span><input type="email" name="email"></label>
                    <label class="field"><span>Empresa do contato</span><input name="company" maxlength="150"></label>
                </div>
                <div class="form-grid two">
                    <label class="field"><span>Classificação</span><select name="status">
                        <option value="lead">Lead</option><option value="customer">Cliente</option><option value="inactive">Inativo</option>
                    </select></label>
                    <label class="field"><span>Instância</span><select name="evolution_instance_id" data-instance-select>
                        <option value="">Sem vínculo</option>
                        <?php foreach ($instances as $instance): ?>
                            <option value="<?= (int) $instance['id'] ?>" data-tenant-id="<?= (int) $instance['tenant_id'] ?>">
                                <?= View::e((Auth::isSuperAdmin() ? ($instance['tenant_name'] ?? '') . ' · ' : '') . $instance['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select></label>
                </div>
                <label class="field"><span>Tags</span><input name="tags" placeholder="novo, indicação, prioridade"></label>
                <label class="field"><span>Notas</span><textarea name="notes" rows="3"></textarea></label>
                <button class="btn btn-primary" type="submit">Salvar contato</button>
            </form>
        </details>
    <?php endif; ?>
</div>

<form class="filter-bar" method="get" action="<?= View::e(Router::url('/contacts')) ?>">
    <label class="filter-search"><span class="search-icon" aria-hidden="true"></span><input name="search" value="<?= View::e($filters['search'] ?? '') ?>" placeholder="Buscar por nome, telefone, e-mail ou empresa"></label>
    <select name="status" aria-label="Classificação">
        <option value="">Todas as classificações</option>
        <?php foreach ($statusLabels as $value => $label): ?>
            <option value="<?= View::e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if (Auth::isSuperAdmin()): ?>
        <select name="tenant_id" aria-label="Empresa">
            <option value="">Todas as empresas</option>
            <?php foreach ($tenants as $tenant): ?>
                <option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <button class="btn btn-secondary" type="submit">Filtrar</button>
    <a class="btn btn-quiet" href="<?= View::e(Router::url('/contacts')) ?>">Limpar</a>
</form>

<div class="contacts-layout<?= $selected ? ' has-detail' : '' ?>">
    <section class="card data-card">
        <div class="section-heading compact">
            <div><span class="eyebrow">Cadastro</span><h2><?= count($contacts) ?> contatos</h2></div>
        </div>
        <div class="table-wrap">
            <table class="clean-table">
                <thead><tr><th>Contato</th><th>Classificação</th><th>Relacionamento</th><th>Última interação</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($contacts as $contact): ?>
                    <?php
                    $params = $queryBase;
                    $params['contact_id'] = (int) $contact['id'];
                    $tags = json_decode((string) ($contact['tags_json'] ?? ''), true);
                    ?>
                    <tr class="<?= $selected && (int) $selected['id'] === (int) $contact['id'] ? 'is-selected' : '' ?>">
                        <td>
                            <div class="person-cell">
                                <span class="soft-avatar"><?= View::e(mb_strtoupper(mb_substr($contact['name'] ?: $contact['phone'], 0, 1))) ?></span>
                                <span><strong><?= View::e($contact['name'] ?: 'Contato sem nome') ?></strong><small><?= View::e($contact['phone']) ?><?= $contact['company'] ? ' · ' . View::e($contact['company']) : '' ?></small></span>
                            </div>
                        </td>
                        <td><span class="badge badge-<?= View::e($contact['status']) ?>"><?= View::e($statusLabels[$contact['status']] ?? $contact['status']) ?></span></td>
                        <td><strong><?= (int) $contact['conversations_count'] ?> conversas</strong><small><?= (int) $contact['leads_count'] ?> negócios<?= Auth::isSuperAdmin() ? ' · ' . View::e($contact['tenant_name']) : '' ?></small></td>
                        <td><span><?= View::e($formatDate($contact['last_interaction_at'])) ?></span><?php if (is_array($tags) && $tags): ?><small><?= View::e(implode(' · ', array_slice($tags, 0, 3))) ?></small><?php endif; ?></td>
                        <td><a class="btn btn-small btn-quiet" href="<?= View::e(Router::url('/contacts?' . http_build_query($params))) ?>">Ver</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$contacts): ?><tr><td colspan="5" class="empty-state">Nenhum contato encontrado.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($selected): ?>
        <?php $selectedTags = json_decode((string) ($selected['tags_json'] ?? ''), true); ?>
        <aside class="card detail-card">
            <div class="detail-header">
                <div class="person-cell">
                    <span class="soft-avatar large"><?= View::e(mb_strtoupper(mb_substr($selected['name'] ?: $selected['phone'], 0, 1))) ?></span>
                    <span><strong><?= View::e($selected['name'] ?: 'Contato sem nome') ?></strong><small><?= View::e($selected['phone']) ?></small></span>
                </div>
                <a class="icon-close" href="<?= View::e(Router::url('/contacts?' . http_build_query($queryBase))) ?>" aria-label="Fechar">×</a>
            </div>

            <form class="form-stack" method="post" action="<?= View::e(Router::url('/contacts/update')) ?>">
                <?= Csrf::input() ?><input type="hidden" name="contact_id" value="<?= (int) $selected['id'] ?>">
                <label class="field"><span>Nome</span><input name="name" value="<?= View::e($selected['name']) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                <label class="field"><span>Telefone</span><input name="phone" value="<?= View::e($selected['phone']) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                <label class="field"><span>E-mail</span><input type="email" name="email" value="<?= View::e($selected['email']) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                <label class="field"><span>Empresa do contato</span><input name="company" value="<?= View::e($selected['company']) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                <label class="field"><span>Classificação</span><select name="status" <?= !$canManage ? 'disabled' : '' ?>>
                    <?php foreach ($statusLabels as $value => $label): ?><option value="<?= View::e($value) ?>" <?= $selected['status'] === $value ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?>
                </select></label>
                <label class="field"><span>Tags</span><input name="tags" value="<?= View::e(is_array($selectedTags) ? implode(', ', $selectedTags) : '') ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                <label class="field"><span>Notas</span><textarea name="notes" rows="5" <?= !$canManage ? 'readonly' : '' ?>><?= View::e($selected['notes']) ?></textarea></label>
                <div class="info-strip"><span>Origem</span><strong><?= View::e($selected['instance_name'] ?: 'Cadastro manual') ?></strong></div>
                <?php if ($canManage): ?><button class="btn btn-primary" type="submit">Salvar alterações</button><?php endif; ?>
            </form>
        </aside>
    <?php endif; ?>
</div>
