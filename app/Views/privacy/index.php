<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

if (Auth::isSuperAdmin()) {
    require __DIR__ . '/admin.php';
    return;
}

$metrics = $metrics ?? [];
$settings = $settings ?? [];
$requests = $requests ?? [];
$contacts = $contacts ?? [];
$acceptances = $acceptances ?? [];
$canManage = Auth::can('privacy.manage');

$requestLabel = static fn (string $type): string => match ($type) {
    'export' => 'Cópia dos dados',
    'delete' => 'Exclusão dos dados',
    'anonymize' => 'Anonimização',
    'consent_review' => 'Revisão de consentimento',
    default => 'Outro pedido',
};
$statusLabel = static fn (string $status): string => match ($status) {
    'open' => 'Recebida',
    'processing' => 'Em análise',
    'completed' => 'Concluída',
    'rejected' => 'Recusada',
    default => ucfirst($status),
};
$statusBadge = static fn (string $status): string => match ($status) {
    'completed' => 'badge-success',
    'rejected' => 'badge-danger',
    'processing' => 'badge-warning',
    default => 'badge-info',
};
$policyVersion = (string) ($settings['policy_version'] ?? 'v1');
$retentionDays = (int) ($settings['retention_days'] ?? 365);
$dpoName = trim((string) ($settings['dpo_name'] ?? ''));
$dpoEmail = trim((string) ($settings['dpo_email'] ?? ''));
$acceptanceRequired = !empty($settings['require_company_acceptance']);
$allowExport = !empty($settings['allow_export_requests']);
$allowDelete = !empty($settings['allow_delete_requests']);
?>
<?php $accountSection = 'privacy'; require __DIR__ . '/../companies/_account_tabs.php'; ?>

<section class="client-privacy-hero card">
    <div class="client-privacy-hero-copy">
        <span class="eyebrow">Privacidade e proteção de dados</span>
        <h2>Organize os cuidados com os dados da sua empresa.</h2>
        <p>Consulte a política vigente, acompanhe os aceites da equipe e registre solicitações de titulares com um fluxo simples.</p>
        <div class="client-privacy-hero-tags">
            <span class="badge badge-info">Política <?= View::e($policyVersion) ?></span>
            <span class="badge <?= $acceptanceRequired ? 'badge-success' : 'badge-warning' ?>"><?= $acceptanceRequired ? 'Aceite da equipe ativado' : 'Aceite da equipe opcional' ?></span>
        </div>
    </div>
    <div class="client-privacy-shield" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
    </div>
</section>

<div class="client-privacy-overview">
    <article class="client-privacy-kpi">
        <span>Pedidos em andamento</span>
        <strong><?= (int) ($metrics['open_requests'] ?? 0) ?></strong>
        <small>Recebidos ou em análise</small>
    </article>
    <article class="client-privacy-kpi">
        <span>Aceites da equipe</span>
        <strong><?= (int) ($metrics['acceptances'] ?? count($acceptances)) ?></strong>
        <small>Confirmações registradas</small>
    </article>
    <article class="client-privacy-kpi">
        <span>Prazo de retenção</span>
        <strong><?= $retentionDays ?> dias</strong>
        <small>Período definido pela empresa</small>
    </article>
    <article class="client-privacy-kpi">
        <span>Responsável</span>
        <strong class="client-privacy-kpi-name"><?= View::e($dpoName !== '' ? $dpoName : 'Não informado') ?></strong>
        <small><?= View::e($dpoEmail !== '' ? $dpoEmail : 'Adicione um contato de privacidade') ?></small>
    </article>
</div>

<div class="client-privacy-layout">
    <section class="card client-privacy-main-card">
        <div class="section-heading client-section-heading">
            <div>
                <span class="eyebrow">Configurações da empresa</span>
                <h2>Política, termos e solicitações</h2>
                <p>Defina como sua equipe confirma os termos e quais pedidos podem ser registrados.</p>
            </div>
            <?php if (!$canManage): ?><span class="badge">Somente leitura</span><?php endif; ?>
        </div>

        <?php if ($canManage): ?>
            <form method="post" action="<?= View::e(Router::url('/privacy/settings/save')) ?>" class="client-privacy-settings-form">
                <?= Csrf::input() ?>
                <input type="hidden" name="tenant_id" value="<?= (int) Auth::tenantId() ?>">

                <details class="client-settings-section" open>
                    <summary>
                        <span class="client-settings-step">1</span>
                        <span><strong>Regras principais</strong><small>Aceite da equipe, versão e tempo de retenção.</small></span>
                    </summary>
                    <div class="client-settings-content">
                        <label class="client-toggle-card">
                            <input type="checkbox" name="require_company_acceptance" value="1" <?= $acceptanceRequired ? 'checked' : '' ?>>
                            <span><strong>Solicitar aceite da equipe</strong><small>Ao entrar, cada usuário confirma a versão atual da política e dos termos.</small></span>
                        </label>
                        <div class="form-grid two">
                            <label class="field"><span>Versão atual</span><input name="policy_version" value="<?= View::e($policyVersion) ?>" placeholder="Ex.: 2026.07"></label>
                            <label class="field"><span>Guardar dados por</span><div class="field-with-suffix"><input type="number" min="30" max="3650" name="retention_days" value="<?= $retentionDays ?>"><span>dias</span></div></label>
                        </div>
                    </div>
                </details>

                <details class="client-settings-section">
                    <summary>
                        <span class="client-settings-step">2</span>
                        <span><strong>Contato de privacidade</strong><small>Quem deve receber dúvidas e solicitações sobre dados.</small></span>
                    </summary>
                    <div class="client-settings-content form-grid two">
                        <label class="field"><span>Nome do responsável</span><input name="dpo_name" value="<?= View::e($dpoName) ?>" placeholder="Ex.: Responsável administrativo"></label>
                        <label class="field"><span>E-mail para contato</span><input type="email" name="dpo_email" value="<?= View::e($dpoEmail) ?>" placeholder="privacidade@suaempresa.com.br"></label>
                    </div>
                </details>

                <details class="client-settings-section">
                    <summary>
                        <span class="client-settings-step">3</span>
                        <span><strong>Política e termos</strong><small>Textos apresentados à equipe para leitura e aceite.</small></span>
                    </summary>
                    <div class="client-settings-content">
                        <div class="form-grid two">
                            <label class="field"><span>Título da política</span><input name="privacy_policy_title" value="<?= View::e($settings['privacy_policy_title'] ?? 'Política de Privacidade') ?>"></label>
                            <label class="field"><span>Título dos termos</span><input name="terms_title" value="<?= View::e($settings['terms_title'] ?? 'Termos de Uso e Tratamento de Dados') ?>"></label>
                        </div>
                        <div class="form-grid two client-privacy-text-grid">
                            <label class="field"><span>Texto da política de privacidade</span><textarea name="privacy_policy_text" rows="10" placeholder="Explique quais dados são coletados, por que são usados e como são protegidos."><?= View::e($settings['privacy_policy_text'] ?? '') ?></textarea></label>
                            <label class="field"><span>Texto dos termos de uso</span><textarea name="terms_text" rows="10" placeholder="Explique as responsabilidades da empresa e dos usuários."><?= View::e($settings['terms_text'] ?? '') ?></textarea></label>
                        </div>
                    </div>
                </details>

                <details class="client-settings-section">
                    <summary>
                        <span class="client-settings-step">4</span>
                        <span><strong>Pedidos de titulares</strong><small>Escolha quais solicitações podem ser registradas.</small></span>
                    </summary>
                    <div class="client-settings-content client-request-options">
                        <label class="client-toggle-card">
                            <input type="checkbox" name="allow_export_requests" value="1" <?= $allowExport ? 'checked' : '' ?>>
                            <span><strong>Permitir cópia dos dados</strong><small>Registra pedidos para reunir e entregar os dados vinculados a um contato.</small></span>
                        </label>
                        <label class="client-toggle-card">
                            <input type="checkbox" name="allow_delete_requests" value="1" <?= $allowDelete ? 'checked' : '' ?>>
                            <span><strong>Permitir exclusão ou anonimização</strong><small>Registra pedidos que precisam ser avaliados antes de remover informações pessoais.</small></span>
                        </label>
                    </div>
                </details>

                <div class="client-form-footer">
                    <p>Ao alterar a versão ou os textos, a equipe poderá precisar aceitar novamente.</p>
                    <button class="btn btn-primary" type="submit">Salvar configurações</button>
                </div>
            </form>
        <?php else: ?>
            <div class="client-readonly-sections">
                <article><span>Política atual</span><strong><?= View::e($settings['privacy_policy_title'] ?? 'Política de Privacidade') ?></strong><small>Versão <?= View::e($policyVersion) ?></small></article>
                <article><span>Termos atuais</span><strong><?= View::e($settings['terms_title'] ?? 'Termos de uso') ?></strong><small><?= $acceptanceRequired ? 'Aceite obrigatório para a equipe' : 'Aceite opcional' ?></small></article>
                <article><span>Solicitações disponíveis</span><strong><?= $allowExport || $allowDelete ? 'Ativadas' : 'Não ativadas' ?></strong><small>Consulte o administrador da empresa para alterações.</small></article>
            </div>
        <?php endif; ?>
    </section>

    <aside class="client-privacy-side">
        <section class="card client-privacy-side-card">
            <div class="section-heading compact"><div><span class="eyebrow">Equipe</span><h2>Aceites registrados</h2></div><span class="badge"><?= count($acceptances) ?></span></div>
            <div class="client-acceptance-list">
                <?php foreach (array_slice($acceptances, 0, 8) as $acceptance): ?>
                    <div class="client-acceptance-item">
                        <span class="client-acceptance-avatar"><?= View::e(mb_strtoupper(mb_substr((string) ($acceptance['user_name'] ?? 'U'), 0, 1))) ?></span>
                        <div><strong><?= View::e($acceptance['user_name'] ?? 'Usuário') ?></strong><small><?= View::e($acceptance['accepted_at'] ?? '') ?> · <?= View::e($acceptance['policy_version'] ?? '') ?></small></div>
                        <span class="client-status-dot" title="Aceito"></span>
                    </div>
                <?php endforeach; ?>
                <?php if (!$acceptances): ?><div class="empty-state compact">Nenhum aceite registrado até o momento.</div><?php endif; ?>
            </div>
        </section>

        <section class="card client-privacy-side-card client-privacy-help-card">
            <span class="client-side-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 1 1 5.8 1c-.7 1-1.9 1.4-2.4 2.5"/><path d="M12 17h.01"/></svg></span>
            <div><strong>Precisa orientar sua equipe?</strong><p>A política explica o uso dos dados. Os termos registram o compromisso dos usuários com essas regras.</p></div>
        </section>
    </aside>
</div>

<?php if ($canManage): ?>
<div class="client-privacy-actions-grid">
    <section class="card client-privacy-action-card">
        <div class="client-action-card-head">
            <span class="client-action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg></span>
            <div><span class="eyebrow">Nova solicitação</span><h2>Registrar pedido de um titular</h2><p>Use quando uma pessoa pedir acesso, revisão ou exclusão dos próprios dados.</p></div>
        </div>
        <form method="post" action="<?= View::e(Router::url('/privacy/requests/create')) ?>" class="client-request-form">
            <?= Csrf::input() ?>
            <input type="hidden" name="tenant_id" value="<?= (int) Auth::tenantId() ?>">
            <div class="form-grid two">
                <label class="field"><span>Contato relacionado</span><select name="contact_id"><option value="">Não vincular a um contato</option><?php foreach ($contacts as $contact): ?><option value="<?= (int) $contact['id'] ?>"><?= View::e(($contact['name'] ?: 'Sem nome') . ' · ' . $contact['phone']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>O que foi solicitado?</span><select name="request_type"><option value="export">Cópia dos dados</option><option value="anonymize">Anonimização</option><option value="delete">Exclusão dos dados</option><option value="consent_review">Revisão de consentimento</option><option value="other">Outro pedido</option></select></label>
                <label class="field"><span>Nome da pessoa</span><input name="requester_name" placeholder="Nome completo"></label>
                <label class="field"><span>E-mail</span><input type="email" name="requester_email" placeholder="contato@exemplo.com"></label>
                <label class="field"><span>Telefone</span><input name="requester_phone" placeholder="(00) 00000-0000"></label>
            </div>
            <label class="field"><span>Detalhes do pedido</span><textarea name="notes" rows="4" placeholder="Informe como o pedido chegou e o que precisa ser analisado."></textarea></label>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Registrar pedido</button></div>
        </form>
    </section>

    <section class="card client-privacy-action-card">
        <div class="client-action-card-head">
            <span class="client-action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg></span>
            <div><span class="eyebrow">Cópia dos dados</span><h2>Exportar informações de um contato</h2><p>Gere um arquivo com cadastro, conversas e mensagens vinculadas à pessoa.</p></div>
        </div>
        <?php if ($contacts): ?>
            <form method="get" action="<?= View::e(Router::url('/privacy/export-contact')) ?>" class="client-export-form">
                <label class="field"><span>Escolha o contato</span><select name="contact_id" required><?php foreach ($contacts as $contact): ?><option value="<?= (int) $contact['id'] ?>"><?= View::e(($contact['name'] ?: 'Sem nome') . ' · ' . $contact['phone']) ?></option><?php endforeach; ?></select></label>
                <button class="btn btn-secondary" type="submit">Baixar arquivo</button>
            </form>
        <?php else: ?>
            <div class="empty-state">Nenhum contato disponível para exportação.</div>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>

<section class="card client-privacy-history">
    <div class="section-heading client-section-heading">
        <div><span class="eyebrow">Acompanhamento</span><h2>Histórico de solicitações</h2><p>Veja o andamento e registre uma resposta quando o pedido for concluído.</p></div>
        <span class="badge"><?= count($requests) ?> registro(s)</span>
    </div>
    <div class="client-request-history-list">
        <?php foreach ($requests as $request): ?>
            <article class="client-request-history-item">
                <div class="client-request-history-main">
                    <span class="client-request-type"><?= View::e($requestLabel((string) ($request['request_type'] ?? 'other'))) ?></span>
                    <strong><?= View::e($request['requester_name'] ?: ($request['contact_name'] ?? 'Solicitante não informado')) ?></strong>
                    <small><?= View::e($request['requested_at'] ?? '') ?><?= !empty($request['requester_email']) ? ' · ' . View::e($request['requester_email']) : '' ?></small>
                    <?php if (!empty($request['response_summary'])): ?><p><?= View::e($request['response_summary']) ?></p><?php endif; ?>
                </div>
                <div class="client-request-history-status"><span class="badge <?= $statusBadge((string) ($request['status'] ?? 'open')) ?>"><?= View::e($statusLabel((string) ($request['status'] ?? 'open'))) ?></span></div>
                <?php if ($canManage && ($request['status'] ?? '') !== 'completed'): ?>
                    <form method="post" action="<?= View::e(Router::url('/privacy/requests/update')) ?>" class="client-request-update-form">
                        <?= Csrf::input() ?>
                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                        <select name="status"><option value="processing">Em análise</option><option value="completed">Concluída</option><option value="rejected">Recusada</option></select>
                        <input name="response_summary" placeholder="Resumo da resposta">
                        <button class="btn btn-small btn-outline" type="submit">Atualizar</button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$requests): ?><div class="empty-state">Nenhuma solicitação registrada. Os novos pedidos aparecerão aqui.</div><?php endif; ?>
    </div>
</section>
