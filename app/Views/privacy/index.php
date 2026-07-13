<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$metrics = $metrics ?? [];
$settings = $settings ?? [];
$requests = $requests ?? [];
$companies = $companies ?? [];
$contacts = $contacts ?? [];
$acceptances = $acceptances ?? [];
$selectedTenantId = (int) ($selectedTenantId ?? 0);
$requestLabel = static fn (string $type): string => match ($type) {
    'export' => 'Exportação de dados',
    'delete' => 'Exclusão de dados',
    'anonymize' => 'Anonimização',
    'consent_review' => 'Revisão de consentimento',
    default => 'Outro pedido',
};
$statusLabel = static fn (string $status): string => match ($status) {
    'open' => 'Aberta',
    'processing' => 'Em análise',
    'completed' => 'Concluída',
    'rejected' => 'Recusada',
    default => $status,
};
$statusBadge = static fn (string $status): string => match ($status) {
    'completed' => 'badge-success',
    'rejected' => 'badge-danger',
    'processing' => 'badge-warning',
    default => 'badge-info',
};
?>

<section class="hero-card privacy-hero-clean">
    <div>
        <span class="eyebrow">Governança de dados</span>
        <h2>Privacidade, solicitações LGPD e aceite da empresa.</h2>
        <p>Controle políticas, termos, aceite dos usuários vinculados à empresa e solicitações de exportação, exclusão ou anonimização de dados.</p>
    </div>
    <div class="hero-actions privacy-hero-actions">
        <span class="badge badge-info">Versão <?= View::e($settings['policy_version'] ?? 'v1') ?></span>
        <span class="badge <?= !empty($settings['require_company_acceptance']) ? 'badge-success' : 'badge-warning' ?>">Aceite <?= !empty($settings['require_company_acceptance']) ? 'obrigatório' : 'opcional' ?></span>
    </div>
</section>

<div class="report-kpi-grid privacy-kpis">
    <article class="card report-kpi"><span>Solicitações abertas</span><strong><?= (int) ($metrics['open_requests'] ?? 0) ?></strong><small>Pedidos em aberto ou análise</small></article>
    <article class="card report-kpi"><span>Solicitações concluídas</span><strong><?= (int) ($metrics['completed_requests'] ?? 0) ?></strong><small>Histórico processado</small></article>
    <article class="card report-kpi"><span>Aceites registrados</span><strong><?= (int) ($metrics['acceptances'] ?? 0) ?></strong><small>Usuários vinculados</small></article>
    <article class="card report-kpi"><span>Consentimentos</span><strong><?= (int) ($metrics['consents'] ?? 0) ?></strong><small>Registros formais</small></article>
</div>

<?php if (Auth::isSuperAdmin()): ?>
    <section class="card privacy-company-filter">
        <div class="section-heading compact">
            <div><span class="eyebrow">Empresas</span><h2>Escopo de análise</h2></div>
        </div>
        <form class="filter-bar" method="get" action="<?= View::e(Router::url('/privacy')) ?>">
            <select name="tenant_id" onchange="this.form.submit()">
                <option value="0">Visão geral de todas as empresas</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?= (int) $company['id'] ?>" <?= $selectedTenantId === (int) $company['id'] ? 'selected' : '' ?>><?= View::e($company['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <a class="btn btn-quiet" href="<?= View::e(Router::url('/privacy')) ?>">Limpar</a>
        </form>
    </section>
<?php endif; ?>

<div class="report-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading compact">
            <div><span class="eyebrow">Configuração</span><h2>Política e termo de aceite</h2></div>
        </div>
        <?php if (Auth::isSuperAdmin() && !$selectedTenantId): ?>
            <div class="empty-state">Selecione uma empresa acima para editar a política, o termo de aceite e o contato responsável.</div>
        <?php else: ?>
            <form method="post" action="<?= View::e(Router::url('/privacy/settings/save')) ?>" class="privacy-settings-form">
                <?= Csrf::input() ?>
                <input type="hidden" name="tenant_id" value="<?= Auth::isSuperAdmin() ? $selectedTenantId : (int) Auth::tenantId() ?>">
                <div class="settings-toggle-grid single">
                    <label class="switch-card">
                        <input type="checkbox" name="require_company_acceptance" value="1" <?= !empty($settings['require_company_acceptance']) ? 'checked' : '' ?>>
                        <span><strong>Exigir aceite dos usuários da empresa</strong><small>Ao entrar no painel, usuários vinculados precisam aceitar a versão atual para continuar.</small></span>
                    </label>
                </div>
                <div class="form-grid two">
                    <label class="field"><span>Versão da política</span><input name="policy_version" value="<?= View::e($settings['policy_version'] ?? 'v1') ?>" placeholder="v1, 2026.07, etc."></label>
                    <label class="field"><span>Prazo de retenção de dados em dias</span><input type="number" min="30" max="3650" name="retention_days" value="<?= (int) ($settings['retention_days'] ?? 365) ?>"></label>
                    <label class="field"><span>Nome do responsável/DPO</span><input name="dpo_name" value="<?= View::e($settings['dpo_name'] ?? '') ?>"></label>
                    <label class="field"><span>E-mail do responsável/DPO</span><input type="email" name="dpo_email" value="<?= View::e($settings['dpo_email'] ?? '') ?>"></label>
                    <label class="field"><span>Título da política</span><input name="privacy_policy_title" value="<?= View::e($settings['privacy_policy_title'] ?? 'Política de Privacidade') ?>"></label>
                    <label class="field"><span>Título do termo</span><input name="terms_title" value="<?= View::e($settings['terms_title'] ?? 'Termos de Uso e Tratamento de Dados') ?>"></label>
                </div>
                <div class="form-grid two">
                    <label class="field"><span>Texto da política de privacidade</span><textarea name="privacy_policy_text" rows="9"><?= View::e($settings['privacy_policy_text'] ?? '') ?></textarea></label>
                    <label class="field"><span>Texto do termo de aceite da empresa</span><textarea name="terms_text" rows="9"><?= View::e($settings['terms_text'] ?? '') ?></textarea></label>
                </div>
                <div class="settings-toggle-grid single">
                    <label class="switch-card"><input type="checkbox" name="allow_export_requests" value="1" <?= !empty($settings['allow_export_requests']) ? 'checked' : '' ?>><span><strong>Permitir pedidos de exportação</strong><small>Libera registro de solicitações para entregar dados do titular.</small></span></label>
                    <label class="switch-card"><input type="checkbox" name="allow_delete_requests" value="1" <?= !empty($settings['allow_delete_requests']) ? 'checked' : '' ?>><span><strong>Permitir pedidos de exclusão/anonimização</strong><small>Libera fluxo de análise para remover ou anonimizar dados pessoais.</small></span></label>
                </div>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar configurações LGPD</button></div>
            </form>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="section-heading compact"><div><span class="eyebrow">Aceites</span><h2>Usuários que aceitaram</h2></div></div>
        <div class="security-list privacy-acceptance-list">
            <?php foreach ($acceptances as $acceptance): ?>
                <div class="security-row">
                    <div>
                        <strong><?= View::e($acceptance['user_name'] ?? 'Usuário') ?></strong>
                        <small><?= View::e($acceptance['email'] ?? '') ?> · <?= View::e($acceptance['policy_version'] ?? '') ?> · <?= View::e($acceptance['accepted_at'] ?? '') ?></small>
                    </div>
                    <span class="badge badge-success">Aceito</span>
                </div>
            <?php endforeach; ?>
            <?php if (!$acceptances): ?><div class="empty-state">Nenhum aceite registrado para este escopo.</div><?php endif; ?>
        </div>
    </section>
</div>

<div class="report-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading compact"><div><span class="eyebrow">Solicitações</span><h2>Registrar pedido LGPD</h2></div></div>
        <?php if (Auth::isSuperAdmin() && !$selectedTenantId): ?>
            <div class="empty-state">Selecione uma empresa para registrar solicitação de titular.</div>
        <?php else: ?>
            <form method="post" action="<?= View::e(Router::url('/privacy/requests/create')) ?>">
                <?= Csrf::input() ?>
                <input type="hidden" name="tenant_id" value="<?= Auth::isSuperAdmin() ? $selectedTenantId : (int) Auth::tenantId() ?>">
                <div class="form-grid two">
                    <label class="field"><span>Contato relacionado</span><select name="contact_id"><option value="">Selecionar contato, se existir</option><?php foreach ($contacts as $contact): ?><option value="<?= (int) $contact['id'] ?>"><?= View::e(($contact['name'] ?: 'Sem nome') . ' · ' . $contact['phone']) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span>Tipo de solicitação</span><select name="request_type"><option value="export">Exportação de dados</option><option value="anonymize">Anonimização</option><option value="delete">Exclusão</option><option value="consent_review">Revisão de consentimento</option><option value="other">Outro</option></select></label>
                    <label class="field"><span>Nome do solicitante</span><input name="requester_name"></label>
                    <label class="field"><span>E-mail</span><input type="email" name="requester_email"></label>
                    <label class="field"><span>Telefone</span><input name="requester_phone"></label>
                </div>
                <label class="field"><span>Observações</span><textarea name="notes" rows="4" placeholder="Descreva o pedido, origem do contato e próximos passos."></textarea></label>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Registrar solicitação</button></div>
            </form>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="section-heading compact"><div><span class="eyebrow">Exportação</span><h2>Exportar dados de contato</h2></div></div>
        <?php if (!$contacts): ?>
            <div class="empty-state">Nenhum contato disponível no escopo selecionado.</div>
        <?php else: ?>
            <form method="get" action="<?= View::e(Router::url('/privacy/export-contact')) ?>" class="privacy-export-form">
                <?php if (Auth::isSuperAdmin()): ?><input type="hidden" name="tenant_id" value="<?= $selectedTenantId ?>"><?php endif; ?>
                <label class="field"><span>Contato</span><select name="contact_id" required><?php foreach ($contacts as $contact): ?><option value="<?= (int) $contact['id'] ?>"><?= View::e(($contact['name'] ?: 'Sem nome') . ' · ' . $contact['phone']) ?></option><?php endforeach; ?></select></label>
                <button class="btn btn-secondary" type="submit">Baixar JSON dos dados</button>
            </form>
        <?php endif; ?>
        <p class="form-help">A exportação reúne cadastro, conversas e mensagens vinculadas ao contato dentro da empresa selecionada.</p>
    </section>
</div>

<section class="card" style="margin-top:16px">
    <div class="section-heading compact"><div><span class="eyebrow">Histórico</span><h2>Solicitações registradas</h2></div></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Data</th><th>Empresa</th><th>Solicitante</th><th>Tipo</th><th>Status</th><th>Resposta</th><th>Ações</th></tr></thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td><?= View::e($request['requested_at'] ?? '') ?></td>
                        <td><?= View::e($request['tenant_name'] ?? '') ?></td>
                        <td><?= View::e($request['requester_name'] ?: ($request['contact_name'] ?? 'Não informado')) ?><br><small><?= View::e($request['requester_email'] ?? $request['requester_phone'] ?? '') ?></small></td>
                        <td><?= View::e($requestLabel((string) ($request['request_type'] ?? 'other'))) ?></td>
                        <td><span class="badge <?= $statusBadge((string) ($request['status'] ?? 'open')) ?>"><?= View::e($statusLabel((string) ($request['status'] ?? 'open'))) ?></span></td>
                        <td><?= View::e($request['response_summary'] ?? '') ?></td>
                        <td>
                            <form method="post" action="<?= View::e(Router::url('/privacy/requests/update')) ?>" class="inline-status-form">
                                <?= Csrf::input() ?>
                                <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                <?php if (Auth::isSuperAdmin()): ?><input type="hidden" name="tenant_id" value="<?= (int) $request['tenant_id'] ?>"><?php endif; ?>
                                <select name="status"><option value="processing">Em análise</option><option value="completed">Concluída</option><option value="rejected">Recusada</option></select>
                                <input name="response_summary" placeholder="Resumo da resposta">
                                <button class="btn btn-small btn-outline" type="submit">Atualizar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$requests): ?><tr><td colspan="7"><div class="empty-state">Nenhuma solicitação registrada.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if (Auth::isSuperAdmin() && !$selectedTenantId): ?>
<section class="card" style="margin-top:16px">
    <div class="section-heading compact"><div><span class="eyebrow">Empresas</span><h2>Situação LGPD por empresa</h2></div></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Empresa</th><th>Versão</th><th>Aceite</th><th>Retenção</th><th>Aceites</th><th>Pedidos abertos</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($companies as $company): ?>
                    <tr>
                        <td><strong><?= View::e($company['name']) ?></strong><br><small><?= View::e($company['email'] ?? '') ?></small></td>
                        <td><?= View::e($company['policy_version'] ?? 'sem política') ?></td>
                        <td><span class="badge <?= !empty($company['require_company_acceptance']) ? 'badge-success' : 'badge-warning' ?>"><?= !empty($company['require_company_acceptance']) ? 'Obrigatório' : 'Opcional' ?></span></td>
                        <td><?= (int) ($company['retention_days'] ?? 0) ?> dias</td>
                        <td><?= (int) ($company['acceptances_count'] ?? 0) ?></td>
                        <td><?= (int) ($company['open_requests_count'] ?? 0) ?></td>
                        <td><a class="btn btn-small btn-outline" href="<?= View::e(Router::url('/privacy?tenant_id=' . (int) $company['id'])) ?>">Gerenciar</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
