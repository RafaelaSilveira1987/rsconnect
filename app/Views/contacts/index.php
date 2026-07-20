<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$statusLabels = ['lead' => 'Lead', 'customer' => 'Cliente', 'inactive' => 'Inativo'];
$groupLabels = \App\Services\ConversationFlowService::GROUPS;
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
if (($filters['contact_group'] ?? '') !== '') $queryBase['contact_group'] = $filters['contact_group'];
if (($filters['tenant_id'] ?? 0) > 0) $queryBase['tenant_id'] = (int) $filters['tenant_id'];
$contactsBaseUrl = Router::url('/contacts' . ($queryBase ? '?' . http_build_query($queryBase) : ''));
?>

<div class="page-heading">
    <div>
        <span class="eyebrow">Relacionamento</span>
        <h2>Base de contatos</h2>
        <p>Centralize leads e clientes vindos do WhatsApp ou cadastrados pela equipe.</p>
    </div>
    <?php if ($canManage): ?>
        <button class="btn btn-primary" type="button" data-toggle-panel="contact-create-drawer">+ Novo contato</button>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
<aside id="contact-create-drawer" class="conversation-details conversation-drawer contact-form-drawer" aria-label="Cadastrar novo contato">
    <div class="conversation-drawer-header">
        <div>
            <span class="eyebrow">Novo contato</span>
            <h2>Cadastrar contato</h2>
            <p>Preencha os dados essenciais. As informações complementares podem ser adicionadas depois.</p>
        </div>
        <button class="icon-button drawer-close" type="button" data-close-panel="contact-create-drawer" aria-label="Fechar">×</button>
    </div>

    <form class="contact-drawer-form" method="post" action="<?= View::e(Router::url('/contacts')) ?>">
        <?= Csrf::input() ?>
        <div class="conversation-drawer-body contact-drawer-body">
            <?php if (Auth::isSuperAdmin()): ?>
                <section class="drawer-section contact-drawer-section">
                    <div class="drawer-section-title"><div><span class="eyebrow">Empresa</span><h3>Onde o contato será cadastrado?</h3></div></div>
                    <label class="field"><span>Empresa</span><select name="tenant_id" required data-tenant-select>
                        <option value="">Selecione</option>
                        <?php foreach ($tenants as $tenant): ?>
                            <option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                </section>
            <?php endif; ?>

            <section class="drawer-section contact-drawer-section">
                <div class="drawer-section-title"><div><span class="eyebrow">Dados principais</span><h3>Identificação e contato</h3></div></div>
                <div class="form-grid two contact-drawer-grid">
                    <label class="field"><span>Nome</span><input name="name" maxlength="150" placeholder="Nome do contato"></label>
                    <label class="field"><span>Telefone *</span><input name="phone" inputmode="tel" placeholder="5511999999999" required></label>
                    <label class="field"><span>E-mail</span><input type="email" name="email" placeholder="email@exemplo.com"></label>
                    <label class="field"><span>Empresa do contato</span><input name="company" maxlength="150" placeholder="Empresa ou organização"></label>
                </div>
            </section>

            <section class="drawer-section contact-drawer-section">
                <div class="drawer-section-title"><div><span class="eyebrow">Organização</span><h3>Como este contato será atendido?</h3></div></div>
                <div class="form-grid two contact-drawer-grid">
                    <label class="field"><span>Classificação</span><select name="status">
                        <option value="lead">Lead</option><option value="customer">Cliente</option><option value="inactive">Inativo</option>
                    </select></label>
                    <label class="field"><span>Grupo de atendimento</span><select name="contact_group">
                        <?php foreach ($groupLabels as $value => $label): ?><option value="<?= View::e($value) ?>"><?= View::e($label) ?></option><?php endforeach; ?>
                    </select><small class="field-hint">O grupo ajuda o assistente a aplicar as regras corretas.</small></label>
                    <label class="field contact-drawer-grid-full"><span>Conexão WhatsApp</span><select name="evolution_instance_id" data-instance-select>
                        <option value="">Sem vínculo</option>
                        <?php foreach ($instances as $instance): ?>
                            <option value="<?= (int) $instance['id'] ?>" data-tenant-id="<?= (int) $instance['tenant_id'] ?>">
                                <?= View::e((Auth::isSuperAdmin() ? ($instance['tenant_name'] ?? '') . ' · ' : '') . $instance['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select></label>
                    <label class="field contact-drawer-grid-full"><span>Tags</span><input name="tags" placeholder="novo, indicação, prioridade"></label>
                </div>
            </section>

            <details class="drawer-section contact-drawer-section contact-drawer-optional">
                <summary>Adicionar observações</summary>
                <div class="contact-drawer-optional-body">
                    <label class="field"><span>Notas</span><textarea name="notes" rows="4" placeholder="Informações importantes para a equipe"></textarea></label>
                </div>
            </details>
        </div>
        <div class="contact-drawer-footer">
            <button class="btn btn-quiet" type="button" data-close-panel="contact-create-drawer">Cancelar</button>
            <button class="btn btn-primary" type="submit">Salvar contato</button>
        </div>
    </form>
</aside>
<?php endif; ?>

<form class="filter-bar" method="get" action="<?= View::e(Router::url('/contacts')) ?>">
    <label class="filter-search"><span class="search-icon" aria-hidden="true"></span><input name="search" value="<?= View::e($filters['search'] ?? '') ?>" placeholder="Buscar por nome, telefone, e-mail ou empresa"></label>
    <select name="status" aria-label="Classificação">
        <option value="">Todas as classificações</option>
        <?php foreach ($statusLabels as $value => $label): ?>
            <option value="<?= View::e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="contact_group" aria-label="Grupo de atendimento">
        <option value="">Todos os grupos</option>
        <?php foreach ($groupLabels as $value => $label): ?>
            <option value="<?= View::e($value) ?>" <?= ($filters['contact_group'] ?? '') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
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

<div class="contacts-layout">
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
                        <td><span class="badge badge-<?= View::e($contact['status']) ?>"><?= View::e($statusLabels[$contact['status']] ?? $contact['status']) ?></span><small><?= View::e($groupLabels[$contact['contact_group'] ?? 'unclassified'] ?? 'Não identificado') ?></small></td>
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
</div>

<?php if ($selected): ?>
    <?php $selectedTags = json_decode((string) ($selected['tags_json'] ?? ''), true); ?>
    <aside id="contact-edit-drawer" class="conversation-details conversation-drawer contact-form-drawer is-open" aria-label="Editar contato">
        <div class="conversation-drawer-header">
            <div class="person-cell">
                <span class="soft-avatar large"><?= View::e(mb_strtoupper(mb_substr($selected['name'] ?: $selected['phone'], 0, 1))) ?></span>
                <span><span class="eyebrow">Contato</span><h2><?= View::e($selected['name'] ?: 'Contato sem nome') ?></h2><small><?= View::e($selected['phone']) ?></small></span>
            </div>
            <a class="icon-button drawer-close" href="<?= View::e($contactsBaseUrl) ?>" aria-label="Fechar">×</a>
        </div>

        <form class="contact-drawer-form" method="post" action="<?= View::e(Router::url('/contacts/update')) ?>">
            <?= Csrf::input() ?><input type="hidden" name="contact_id" value="<?= (int) $selected['id'] ?>">
            <div class="conversation-drawer-body contact-drawer-body">
                <section class="drawer-section contact-drawer-section">
                    <div class="drawer-section-title"><div><span class="eyebrow">Dados principais</span><h3>Identificação e contato</h3></div></div>
                    <div class="form-grid two contact-drawer-grid">
                        <label class="field"><span>Nome</span><input name="name" value="<?= View::e($selected['name']) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                        <label class="field"><span>Telefone</span><input name="phone" value="<?= View::e($selected['phone']) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                        <label class="field"><span>E-mail</span><input type="email" name="email" value="<?= View::e($selected['email']) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                        <label class="field"><span>Empresa do contato</span><input name="company" value="<?= View::e($selected['company']) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                    </div>
                </section>

                <section class="drawer-section contact-drawer-section">
                    <div class="drawer-section-title"><div><span class="eyebrow">Organização</span><h3>Classificação do atendimento</h3></div></div>
                    <div class="form-grid two contact-drawer-grid">
                        <label class="field"><span>Classificação</span><select name="status" <?= !$canManage ? 'disabled' : '' ?>>
                            <?php foreach ($statusLabels as $value => $label): ?><option value="<?= View::e($value) ?>" <?= $selected['status'] === $value ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?>
                        </select></label>
                        <label class="field"><span>Grupo de atendimento</span><select name="contact_group" <?= !$canManage ? 'disabled' : '' ?>>
                            <?php foreach ($groupLabels as $value => $label): ?><option value="<?= View::e($value) ?>" <?= ($selected['contact_group'] ?? 'unclassified') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?>
                        </select></label>
                        <label class="field contact-drawer-grid-full"><span>Tags</span><input name="tags" value="<?= View::e(is_array($selectedTags) ? implode(', ', $selectedTags) : '') ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                    </div>
                </section>

                <details class="drawer-section contact-drawer-section contact-drawer-optional" <?= trim((string) ($selected['notes'] ?? '')) !== '' ? 'open' : '' ?>>
                    <summary>Observações e origem</summary>
                    <div class="contact-drawer-optional-body">
                        <label class="field"><span>Notas</span><textarea name="notes" rows="5" <?= !$canManage ? 'readonly' : '' ?>><?= View::e($selected['notes']) ?></textarea></label>
                        <div class="info-strip"><span>Origem</span><strong><?= View::e($selected['instance_name'] ?: 'Cadastro manual') ?></strong></div>
                    </div>
                </details>
            </div>
            <div class="contact-drawer-footer">
                <a class="btn btn-quiet" href="<?= View::e($contactsBaseUrl) ?>">Fechar</a>
                <?php if ($canManage): ?><button class="btn btn-primary" type="submit">Salvar alterações</button><?php endif; ?>
            </div>
        </form>
    </aside>
<?php endif; ?>
