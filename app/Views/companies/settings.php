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


    <section class="settings-block">
        <div class="section-heading compact">
            <div>
                <span class="eyebrow">Pré-agendamento</span>
                <h2>Agenda com aprovação humana</h2>
                <p>Quando ativo, a IA pode registrar a preferência de dia/horário do lead e enviar para aprovação antes de confirmar.</p>
            </div>
            <span class="badge <?= !empty($preScheduleSettings['enabled']) ? 'badge-active' : 'badge-pending' ?>"><?= !empty($preScheduleSettings['enabled']) ? 'Ativo' : 'Desativado' ?></span>
        </div>
        <div class="settings-toggle-grid">
            <label class="switch-card">
                <input type="checkbox" name="pre_schedule_enabled" value="1" <?= !empty($preScheduleSettings['enabled']) ? 'checked' : '' ?>>
                <span><strong>Usar pré-agendamento</strong><small>Cria solicitações na agenda a partir da intenção detectada na conversa.</small></span>
            </label>
            <label class="switch-card">
                <input type="checkbox" name="pre_schedule_require_human_approval" value="1" <?= !empty($preScheduleSettings['require_human_approval']) ? 'checked' : '' ?>>
                <span><strong>Exigir aprovação humana</strong><small>Recomendado para psicologia, saúde, consultorias e serviços com agenda sensível.</small></span>
            </label>
            <label class="switch-card">
                <input type="checkbox" name="pre_schedule_ai_can_suggest_slots" value="1" <?= !empty($preScheduleSettings['ai_can_suggest_slots']) ? 'checked' : '' ?>>
                <span><strong>IA pode sugerir disponibilidade</strong><small>Permite que a IA registre opções aproximadas, sem confirmar.</small></span>
            </label>
            <label class="switch-card">
                <input type="checkbox" name="pre_schedule_ai_can_confirm" value="1" <?= !empty($preScheduleSettings['ai_can_confirm']) ? 'checked' : '' ?>>
                <span><strong>IA pode confirmar sozinha</strong><small>Use apenas em negócios onde não há necessidade de validação humana.</small></span>
            </label>
            <label class="switch-card">
                <input type="checkbox" name="pre_schedule_send_approval_message" value="1" <?= !empty($preScheduleSettings['send_approval_message']) ? 'checked' : '' ?>>
                <span><strong>Enviar mensagem ao aprovar</strong><small>Ao clicar em Aprovar/Confirmar na agenda, o RS Connect envia a confirmação pelo WhatsApp.</small></span>
            </label>
        </div>
        <div class="form-grid two">
            <label class="field"><span>Duração padrão</span><input type="number" min="15" max="240" name="pre_schedule_default_duration_minutes" value="<?= (int) ($preScheduleSettings['default_duration_minutes'] ?? 50) ?>"></label>
            <label class="field"><span>Mensagem quando registrar preferência</span><input name="pre_schedule_default_message" value="<?= View::e($preScheduleSettings['default_message'] ?? '') ?>"></label>
        </div>
        <div class="form-grid two">
            <label class="field"><span>Mensagem para coletar dia/horário</span><textarea name="pre_schedule_collect_message" rows="3"><?= View::e($preScheduleSettings['collect_message'] ?? '') ?></textarea></label>
            <label class="field"><span>Mensagem após aprovação</span><textarea name="pre_schedule_approved_message" rows="3"><?= View::e($preScheduleSettings['approved_message'] ?? '') ?></textarea></label>
            <label class="field"><span>Mensagem ao recusar horário</span><textarea name="pre_schedule_rejected_message" rows="3"><?= View::e($preScheduleSettings['rejected_message'] ?? '') ?></textarea></label>
            <label class="field"><span>Mensagem ao remarcar</span><textarea name="pre_schedule_reschedule_message" rows="3"><?= View::e($preScheduleSettings['reschedule_message'] ?? '') ?></textarea></label>
        </div>
        <p class="form-help">Você pode usar variáveis nas mensagens: <code>{{nome}}</code>, <code>{{data}}</code>, <code>{{hora}}</code>, <code>{{local}}</code>, <code>{{modalidade}}</code>, <code>{{dia_preferido}}</code> e <code>{{horario_preferido}}</code>.</p>
    </section>

    <section class="settings-block">
        <div class="section-heading compact">
            <div>
                <span class="eyebrow">Módulos e menus</span>
                <h2>Exibir ou ocultar recursos da empresa</h2>
                <p>Controle o que aparece no menu e bloqueie o acesso direto às rotas dos módulos desativados.</p>
            </div>
        </div>
        <div class="module-settings-grid">
            <?php foreach (($availableModules ?? []) as $moduleKey => $module): ?>
                <?php
                $isVisible = (bool) (($moduleSettings[$moduleKey]['is_visible'] ?? null) ?? ($module['default_visible'] ?? true));
                $isEnabled = (bool) (($moduleSettings[$moduleKey]['is_enabled'] ?? null) ?? ($module['default_enabled'] ?? true));
                $locked = in_array($moduleKey, ['dashboard', 'company_settings'], true);
                ?>
                <article class="module-setting-card <?= $isEnabled ? 'is-enabled' : 'is-disabled' ?>">
                    <div>
                        <strong><?= View::e($module['label']) ?></strong>
                        <small><?= View::e($module['description']) ?></small>
                    </div>
                    <div class="module-setting-actions">
                        <label><input type="checkbox" name="module_visible[]" value="<?= View::e($moduleKey) ?>" <?= $isVisible ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>> Menu</label>
                        <label><input type="checkbox" name="module_enabled[]" value="<?= View::e($moduleKey) ?>" <?= $isEnabled ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>> Acesso</label>
                        <?php if ($locked): ?>
                            <input type="hidden" name="module_visible[]" value="<?= View::e($moduleKey) ?>">
                            <input type="hidden" name="module_enabled[]" value="<?= View::e($moduleKey) ?>">
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if (Auth::can('company.manage')): ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar alterações</button></div>
    <?php endif; ?>
</form>
