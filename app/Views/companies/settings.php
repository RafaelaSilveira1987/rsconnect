<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$statusLabels = ['active' => 'Ativa', 'inactive' => 'Inativa', 'suspended' => 'Suspensa'];
$statusLabel = $statusLabels[$company['status'] ?? 'active'] ?? 'Ativa';
$canManageCompany = Auth::can('company.manage');
$profileFields = [
    $company['name'] ?? '', $company['segment'] ?? '', $company['email'] ?? '', $company['phone'] ?? '',
    $company['website'] ?? '', $company['company_about'] ?? '', $company['company_services'] ?? '',
    $company['company_differentials'] ?? '', $company['company_business_hours'] ?? '',
];
$filledProfile = count(array_filter($profileFields, static fn ($value): bool => trim((string) $value) !== ''));
$profilePercent = (int) round(($filledProfile / max(1, count($profileFields))) * 100);
?>
<?php if (Auth::isSuperAdmin()): ?>

<div class="hero-card compact-hero">
    <div>
        <span class="eyebrow light">Perfil empresarial</span>
        <h2><?= View::e($company['name']) ?></h2>
        <p>Esses dados identificam a empresa dentro do RS Connect e serão usados nos módulos de atendimento e automação.</p>
    </div>
    <?php if (Auth::isSuperAdmin()): ?><div class="hero-actions"><a class="btn btn-light" href="#company-module-settings">Menus do cliente</a><a class="btn btn-light" href="<?= View::e(Router::url('/companies')) ?>">Voltar às empresas</a></div><?php endif; ?>
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
            <label class="field"><span>Mensagem com horários alternativos</span><textarea name="pre_schedule_availability_options_message" rows="5"><?= View::e($preScheduleSettings['availability_options_message'] ?? '') ?></textarea><small>Use {{opcoes}} para inserir a lista real retornada pelo Google.</small></label>
            <label class="field"><span>Mensagem após o cliente escolher</span><textarea name="pre_schedule_slot_selected_message" rows="4"><?= View::e($preScheduleSettings['slot_selected_message'] ?? '') ?></textarea><small>Disponível: {{data}}, {{hora}}, {{inicio}}, {{nome}} e {{modalidade}}.</small></label>
            <label class="field"><span>Mensagem quando não houver horários</span><textarea name="pre_schedule_no_availability_message" rows="3"><?= View::e($preScheduleSettings['no_availability_message'] ?? '') ?></textarea></label>
            <label class="field"><span>Mensagem quando a escolha não for identificada</span><textarea name="pre_schedule_invalid_slot_message" rows="3"><?= View::e($preScheduleSettings['invalid_slot_message'] ?? '') ?></textarea></label>
        </div>
        <p class="form-help">Você pode usar variáveis nas mensagens: <code>{{nome}}</code>, <code>{{data}}</code>, <code>{{hora}}</code>, <code>{{local}}</code>, <code>{{modalidade}}</code>, <code>{{dia_preferido}}</code> e <code>{{horario_preferido}}</code>.</p>
    </section>

    <section class="settings-block admin-client-menu-settings" id="company-module-settings">
        <input type="hidden" name="module_settings_submitted" value="1">
        <div class="section-heading compact">
            <div>
                <span class="eyebrow">Menus do cliente</span>
                <h2>Escolha o que a empresa verá e poderá acessar</h2>
                <p><strong>Mostrar no menu</strong> controla a navegação. <strong>Permitir acesso</strong> também bloqueia a abertura direta do módulo.</p>
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

<?php else: ?>
<?php $accountSection = 'company'; require __DIR__ . '/_account_tabs.php'; ?>
<form class="client-company-profile" method="post" action="<?= View::e(Router::url('/company-settings')) ?>">
    <?= Csrf::input() ?>
    <input type="hidden" name="tenant_id" value="<?= (int) $company['id'] ?>">

    <section class="client-company-hero">
        <div class="client-company-hero-copy">
            <span class="eyebrow">Perfil da empresa</span>
            <div class="client-company-title-row">
                <h1><?= View::e($company['name']) ?></h1>
                <span class="badge badge-<?= View::e($company['status']) ?>"><?= View::e($statusLabel) ?></span>
            </div>
            <p>Mantenha estas informações atualizadas. Elas identificam sua empresa e ajudam os assistentes a responder com mais contexto.</p>
            <div class="client-profile-progress" aria-label="Perfil <?= $profilePercent ?>% preenchido">
                <span><i style="width: <?= $profilePercent ?>%"></i></span>
                <small><?= $profilePercent ?>% do perfil preenchido</small>
            </div>
        </div>
        <?php if ($canManageCompany): ?><button class="btn btn-primary client-company-save-top" type="submit">Salvar alterações</button><?php endif; ?>
    </section>

    <div class="client-company-grid">
        <section class="card client-profile-card">
            <div class="client-profile-card-heading">
                <span class="client-profile-icon" aria-hidden="true">01</span>
                <div><span class="eyebrow">Identificação</span><h2>Informações principais</h2><p>Dados usados para identificar sua empresa no sistema.</p></div>
            </div>
            <label class="field company-display-name-field">
                <span>Nome de exibição</span>
                <input name="name" value="<?= View::e($company['name']) ?>" required>
                <small class="field-hint">Nome usado nas telas, relatórios e comunicações do RS Connect.</small>
            </label>

            <div class="master-data-panel" aria-label="Dados cadastrais protegidos">
                <div class="master-data-panel-head">
                    <div>
                        <span class="eyebrow">Cadastro RS</span>
                        <strong>Dados cadastrais</strong>
                        <small>Informações oficiais vinculadas à sua empresa.</small>
                    </div>
                    <span class="master-data-status">Somente leitura</span>
                </div>
                <div class="master-data-summary">
                    <div class="master-data-item">
                        <span>Razão social</span>
                        <strong><?= View::e($company['legal_name'] ?? 'Não informado') ?></strong>
                    </div>
                    <div class="master-data-item">
                        <span>CNPJ ou CPF</span>
                        <strong><?= View::e($company['document'] ?? 'Não informado') ?></strong>
                    </div>
                    <div class="master-data-item">
                        <span>Segmento</span>
                        <strong><?= View::e($company['segment'] ?? 'Não informado') ?></strong>
                    </div>
                </div>
                <p class="master-data-help">Para corrigir uma dessas informações, solicite a alteração à equipe RS.</p>
            </div>
        </section>

        <section class="card client-profile-card">
            <div class="client-profile-card-heading">
                <span class="client-profile-icon" aria-hidden="true">02</span>
                <div><span class="eyebrow">Contato</span><h2>Como encontrar sua empresa</h2><p>Informações comerciais usadas pela equipe e pelos assistentes.</p></div>
            </div>
            <div class="form-grid two">
                <label class="field"><span>E-mail comercial</span><input type="email" name="email" value="<?= View::e($company['email'] ?? '') ?>" placeholder="contato@empresa.com.br"></label>
                <label class="field"><span>Telefone</span><input name="phone" value="<?= View::e($company['phone'] ?? '') ?>" placeholder="(00) 0000-0000"></label>
                <label class="field"><span>WhatsApp comercial</span><input name="commercial_whatsapp" value="<?= View::e($company['commercial_whatsapp'] ?? '') ?>" placeholder="(00) 00000-0000"></label>
                <label class="field"><span>Instagram</span><input name="instagram" value="<?= View::e($company['instagram'] ?? '') ?>" placeholder="@suaempresa ou link do perfil"></label>
            </div>
            <label class="field"><span>Site</span><input type="url" name="website" value="<?= View::e($company['website'] ?? '') ?>" placeholder="https://empresa.com.br"></label>
        </section>

        <section class="card client-profile-card client-profile-card-wide">
            <div class="client-profile-card-heading">
                <span class="client-profile-icon" aria-hidden="true">03</span>
                <div><span class="eyebrow">Localização</span><h2>Endereço da empresa</h2><p>Preencha quando o atendimento ou serviço depender de localização.</p></div>
            </div>
            <div class="client-address-grid" data-company-address>
                <label class="field client-address-cep">
                    <span>CEP</span>
                    <div class="postal-code-control">
                        <input name="postal_code" value="<?= View::e($company['postal_code'] ?? '') ?>" placeholder="00000-000" inputmode="numeric" maxlength="9" autocomplete="postal-code" data-cep-input>
                        <span class="cep-lookup-indicator" aria-hidden="true"></span>
                    </div>
                    <small class="field-hint cep-lookup-status" data-cep-status>Digite o CEP para preencher o endereço automaticamente.</small>
                </label>
                <label class="field client-address-line"><span>Rua ou avenida</span><input name="address_line" value="<?= View::e($company['address_line'] ?? '') ?>" autocomplete="address-line1"></label>
                <label class="field client-address-number"><span>Número</span><input name="address_number" value="<?= View::e($company['address_number'] ?? '') ?>" autocomplete="address-line2"></label>
                <label class="field client-address-complement"><span>Complemento</span><input name="address_complement" value="<?= View::e($company['address_complement'] ?? '') ?>"></label>
                <label class="field client-address-district"><span>Bairro</span><input name="district" value="<?= View::e($company['district'] ?? '') ?>"></label>
                <label class="field client-address-city"><span>Cidade</span><input name="city" value="<?= View::e($company['city'] ?? '') ?>" autocomplete="address-level2"></label>
                <label class="field client-address-state"><span>Estado</span><input name="state" value="<?= View::e($company['state'] ?? '') ?>" placeholder="Ex.: MG" maxlength="2" autocomplete="address-level1"></label>
            </div>
        </section>

        <section class="card client-profile-card client-profile-card-wide client-ai-profile-card">
            <div class="client-profile-card-heading">
                <span class="client-profile-icon" aria-hidden="true">04</span>
                <div><span class="eyebrow">Atendimento</span><h2>Informações usadas pelos assistentes</h2><p>Quanto mais claras forem estas informações, mais contextualizadas serão as respostas.</p></div>
            </div>
            <div class="form-grid two client-ai-profile-grid">
                <label class="field"><span>Sobre a empresa</span><textarea name="company_about" rows="6" placeholder="Conte de forma simples quem é a empresa, há quanto tempo atua e quem atende."><?= View::e($company['company_about'] ?? '') ?></textarea></label>
                <label class="field"><span>Principais serviços ou produtos</span><textarea name="company_services" rows="6" placeholder="Liste os serviços, produtos, especialidades ou soluções oferecidas."><?= View::e($company['company_services'] ?? '') ?></textarea></label>
                <label class="field"><span>Diferenciais</span><textarea name="company_differentials" rows="5" placeholder="Ex.: atendimento personalizado, entrega rápida, equipe especializada."><?= View::e($company['company_differentials'] ?? '') ?></textarea></label>
                <label class="field"><span>Horário de atendimento</span><textarea name="company_business_hours" rows="5" placeholder="Ex.: segunda a sexta, das 8h às 18h; sábado, das 8h às 12h."><?= View::e($company['company_business_hours'] ?? '') ?></textarea></label>
            </div>
            <label class="field"><span>Observações importantes</span><textarea name="company_notes" rows="4" placeholder="Políticas, limitações, links, instruções ou informações que precisam ser consideradas no atendimento."><?= View::e($company['company_notes'] ?? '') ?></textarea></label>
            <div class="client-ai-profile-note"><strong>Como isso ajuda?</strong><span>Ao criar um novo assistente, estas informações serão usadas como base inicial e poderão ser revisadas antes da ativação.</span></div>
        </section>
    </div>

    <details class="card client-settings-accordion">
        <summary><span><span class="eyebrow">Agenda</span><strong>Pré-agendamento e mensagens</strong><small>Regras para registrar, aprovar e comunicar horários.</small></span><span class="drawer-chevron"></span></summary>
        <div class="client-settings-accordion-body">
            <div class="settings-toggle-grid">
                <label class="switch-card"><input type="checkbox" name="pre_schedule_enabled" value="1" <?= !empty($preScheduleSettings['enabled']) ? 'checked' : '' ?>><span><strong>Usar pré-agendamento</strong><small>Registra preferências de dia e horário durante a conversa.</small></span></label>
                <label class="switch-card"><input type="checkbox" name="pre_schedule_require_human_approval" value="1" <?= !empty($preScheduleSettings['require_human_approval']) ? 'checked' : '' ?>><span><strong>Exigir aprovação da equipe</strong><small>Nenhum horário é confirmado sem revisão humana.</small></span></label>
                <label class="switch-card"><input type="checkbox" name="pre_schedule_ai_can_suggest_slots" value="1" <?= !empty($preScheduleSettings['ai_can_suggest_slots']) ? 'checked' : '' ?>><span><strong>Assistente pode sugerir horários</strong><small>Apresenta opções sem confirmar sozinho.</small></span></label>
                <label class="switch-card"><input type="checkbox" name="pre_schedule_ai_can_confirm" value="1" <?= !empty($preScheduleSettings['ai_can_confirm']) ? 'checked' : '' ?>><span><strong>Assistente pode confirmar sozinho</strong><small>Use apenas quando a operação não exigir revisão.</small></span></label>
                <label class="switch-card"><input type="checkbox" name="pre_schedule_send_approval_message" value="1" <?= !empty($preScheduleSettings['send_approval_message']) ? 'checked' : '' ?>><span><strong>Enviar confirmação pelo WhatsApp</strong><small>Envia a mensagem quando a equipe aprovar o horário.</small></span></label>
            </div>
            <div class="form-grid two">
                <label class="field"><span>Duração padrão em minutos</span><input type="number" min="15" max="240" name="pre_schedule_default_duration_minutes" value="<?= (int) ($preScheduleSettings['default_duration_minutes'] ?? 50) ?>"></label>
                <label class="field"><span>Mensagem ao registrar preferência</span><input name="pre_schedule_default_message" value="<?= View::e($preScheduleSettings['default_message'] ?? '') ?>"></label>
                <label class="field"><span>Mensagem para pedir dia e horário</span><textarea name="pre_schedule_collect_message" rows="3"><?= View::e($preScheduleSettings['collect_message'] ?? '') ?></textarea></label>
                <label class="field"><span>Mensagem após aprovação</span><textarea name="pre_schedule_approved_message" rows="3"><?= View::e($preScheduleSettings['approved_message'] ?? '') ?></textarea></label>
                <label class="field"><span>Mensagem quando o horário não for aceito</span><textarea name="pre_schedule_rejected_message" rows="3"><?= View::e($preScheduleSettings['rejected_message'] ?? '') ?></textarea></label>
                <label class="field"><span>Mensagem para remarcar</span><textarea name="pre_schedule_reschedule_message" rows="3"><?= View::e($preScheduleSettings['reschedule_message'] ?? '') ?></textarea></label>
                <label class="field"><span>Mensagem com horários alternativos</span><textarea name="pre_schedule_availability_options_message" rows="5"><?= View::e($preScheduleSettings['availability_options_message'] ?? '') ?></textarea><small>Use {{opcoes}} para inserir a lista real retornada pelo Google.</small></label>
                <label class="field"><span>Mensagem após o cliente escolher</span><textarea name="pre_schedule_slot_selected_message" rows="4"><?= View::e($preScheduleSettings['slot_selected_message'] ?? '') ?></textarea><small>Disponível: {{data}}, {{hora}}, {{inicio}}, {{nome}} e {{modalidade}}.</small></label>
                <label class="field"><span>Mensagem quando não houver horários</span><textarea name="pre_schedule_no_availability_message" rows="3"><?= View::e($preScheduleSettings['no_availability_message'] ?? '') ?></textarea></label>
                <label class="field"><span>Mensagem quando a escolha não for identificada</span><textarea name="pre_schedule_invalid_slot_message" rows="3"><?= View::e($preScheduleSettings['invalid_slot_message'] ?? '') ?></textarea></label>
            </div>
        </div>
    </details>

    <details class="card client-settings-accordion client-menu-preferences" id="my-menu-settings">
        <summary><span><span class="eyebrow">Meu menu</span><strong>Organizar módulos visíveis</strong><small>Escolha quais atalhos sua equipe verá na navegação.</small></span><span class="drawer-chevron"></span></summary>
        <div class="client-settings-accordion-body">
            <input type="hidden" name="module_settings_submitted" value="1">
            <div class="client-menu-preferences-note">
                <strong>Isso altera apenas a organização do menu.</strong>
                <span>As permissões e os módulos contratados continuam sendo definidos pela RS Connect. Itens obrigatórios não podem ser ocultados.</span>
            </div>
            <div class="client-menu-preferences-grid">
                <?php foreach (($availableModules ?? []) as $moduleKey => $module): ?>
                    <?php
                    if (in_array($moduleKey, ['company_settings', 'users', 'permissions', 'subscription', 'privacy', 'notifications'], true)) { continue; }
                    $isVisible = (bool) (($moduleSettings[$moduleKey]['is_visible'] ?? null) ?? ($module['default_visible'] ?? true));
                    $isEnabled = (bool) (($moduleSettings[$moduleKey]['is_enabled'] ?? null) ?? ($module['default_enabled'] ?? true));
                    $locked = in_array($moduleKey, ['dashboard', 'company_settings'], true);
                    if (!$isEnabled && !$locked) { continue; }
                    ?>
                    <label class="client-menu-option <?= $isVisible ? 'is-visible' : '' ?> <?= $locked ? 'is-locked' : '' ?>">
                        <input type="checkbox" name="module_visible[]" value="<?= View::e($moduleKey) ?>" <?= $isVisible ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
                        <span><strong><?= View::e($module['label']) ?></strong><small><?= View::e($module['description']) ?></small></span>
                        <em><?= $locked ? 'Obrigatório' : 'Mostrar' ?></em>
                        <?php if ($locked): ?><input type="hidden" name="module_visible[]" value="<?= View::e($moduleKey) ?>"><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </details>

    <details class="card client-settings-accordion client-account-details">
        <summary><span><span class="eyebrow">Conta</span><strong>Informações do plano</strong><small>Dados internos da sua conta no RS Connect.</small></span><span class="drawer-chevron"></span></summary>
        <div class="client-settings-accordion-body readonly-grid">
            <div><span>Identificador</span><strong><?= View::e($company['slug']) ?></strong></div>
            <div><span>Plano</span><strong><?= View::e(ucfirst($company['plan'])) ?></strong></div>
            <div><span>Primeiros passos</span><strong><?= $company['onboarding_completed_at'] ? 'Concluídos' : 'Etapa ' . (int) $company['onboarding_step'] . '/3' ?></strong></div>
        </div>
    </details>

    <?php if ($canManageCompany): ?>
        <div class="client-company-savebar">
            <a class="btn btn-quiet" href="<?= View::e(Router::url('/company-settings')) ?>">Cancelar alterações</a>
            <div><span>Revise os dados antes de salvar.</span><button class="btn btn-primary" type="submit">Salvar alterações</button></div>
        </div>
    <?php endif; ?>
</form>
<?php endif; ?>
<?php if (!Auth::isSuperAdmin()): ?>
<script src="<?= View::e(Router::url('/assets/js/company-settings.js?v=36.5.5')) ?>" defer></script>
<?php endif; ?>
