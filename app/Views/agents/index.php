<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$dayLabels = ['mon' => 'Seg', 'tue' => 'Ter', 'wed' => 'Qua', 'thu' => 'Qui', 'fri' => 'Sex', 'sat' => 'Sáb', 'sun' => 'Dom'];
$selectedDays = static function (?string $json): array {
    $decoded = json_decode((string) $json, true);
    return is_array($decoded) ? array_keys($decoded) : ['mon', 'tue', 'wed', 'thu', 'fri'];
};
$firstHours = static function (?string $json): array {
    $decoded = json_decode((string) $json, true);
    if (is_array($decoded)) {
        foreach ($decoded as $ranges) {
            if (isset($ranges[0][0], $ranges[0][1])) {
                return [(string) $ranges[0][0], (string) $ranges[0][1]];
            }
        }
    }
    return ['08:00', '18:00'];
};
$canManage = Auth::can('agents.manage');
?>
<div class="agent-management-page">
    <section class="card agent-list-card">
        <div class="section-heading agent-page-heading">
            <div><span class="eyebrow">Assistentes virtuais</span><h2>Assistentes cadastrados</h2></div>
            <div class="agent-page-actions">
                <span class="badge"><?= count($agents) ?> assistente(s)</span>
                <?php if ($canManage): ?>
                    <button class="btn btn-primary" type="button" data-toggle-panel="agent-create-drawer" <?= !$instances ? 'disabled' : '' ?>>
                        Novo assistente
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canManage && !$instances): ?>
            <div class="message-warning agent-connection-warning">
                A equipe RS Connect precisa preparar uma conexão WhatsApp antes da criação do primeiro assistente.
            </div>
        <?php endif; ?>

        <div class="agent-grid">
            <?php foreach ($agents as $agent): ?>
                <?php [$start, $end] = $firstHours($agent['business_hours_json'] ?? null); $days = $selectedDays($agent['business_hours_json'] ?? null); ?>
                <article class="agent-card">
                    <div class="agent-card-head">
                        <span class="agent-icon agent-icon-bot" aria-hidden="true"></span>
                        <div><h3><?= View::e($agent['name']) ?></h3><p><?= View::e($agent['segment']) ?></p></div>
                    </div>
                    <div class="agent-data">
                        <div><span>Conexão WhatsApp</span><strong><?= View::e($agent['instance_name'] ?? 'Não vinculada') ?></strong></div>
                        <div><span>Modelo de IA</span><strong><?= View::e($agent['credential_model'] ?: $agent['model_name']) ?></strong></div>
                        <div><span>Acesso à IA</span><strong><?= View::e($agent['credential_label'] ?: 'Configuração da RS Connect') ?></strong></div>
                        <div><span>Memória da conversa</span><strong><?= (int) ($agent['max_context_messages'] ?? 12) ?> mensagens</strong></div>
                    </div>
                    <div class="badge-row">
                        <span class="badge badge-<?= View::e($agent['status']) ?>"><?= $agent['status'] === 'active' ? 'Ativo' : 'Inativo' ?></span>
                        <span class="badge <?= (int) ($agent['auto_reply_enabled'] ?? 0) === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) ($agent['auto_reply_enabled'] ?? 0) === 1 ? 'Respostas automáticas' : 'Resposta manual' ?></span>
                        <?php if ((int) $agent['is_default'] === 1): ?><span class="badge">Assistente principal</span><?php endif; ?>
                        <?php if ((int) ($agent['business_hours_enabled'] ?? 0) === 1): ?><span class="badge">Segue horário</span><?php endif; ?>
                    </div>

                    <?php if ($canManage): ?>
                        <details class="agent-prompt agent-prompt-editor">
                            <summary>Editar instruções e informações</summary>
                            <form method="post" action="<?= View::e(Router::url('/agents/prompt')) ?>">
                                <?= Csrf::input() ?>
                                <input type="hidden" name="agent_id" value="<?= (int) $agent['id'] ?>">
                                <label class="field">
                                    <span>Como o assistente deve atender</span>
                                    <textarea class="agent-prompt-textarea" name="system_prompt" rows="16" maxlength="60000" required><?= View::e($agent['system_prompt']) ?></textarea>
                                    <small class="field-hint">Descreva o tom de voz, o que ele pode fazer, o que deve evitar e como conduzir o atendimento.</small>
                                </label>
                                <label class="field">
                                    <span>Informações da empresa</span>
                                    <textarea class="agent-knowledge-textarea" name="knowledge_base" rows="10" maxlength="500000" placeholder="Serviços, horários, links, políticas, respostas frequentes e informações importantes."><?= View::e($agent['knowledge_base'] ?? '') ?></textarea>
                                </label>
                                <div class="agent-prompt-actions">
                                    <span class="muted-text">As mudanças passam a valer nas próximas respostas.</span>
                                    <button class="btn btn-primary" type="submit">Salvar instruções</button>
                                </div>
                            </form>
                        </details>
                    <?php else: ?>
                        <details class="agent-prompt"><summary>Ver instruções e informações</summary><pre><?= View::e($agent['system_prompt']) ?></pre><?php if (!empty($agent['knowledge_base'])): ?><pre><?= View::e($agent['knowledge_base']) ?></pre><?php endif; ?></details>
                    <?php endif; ?>

                    <?php if ($canManage): ?>
                        <form class="agent-actions agent-settings-form" method="post" action="<?= View::e(Router::url('/agents/status')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="agent_id" value="<?= (int) $agent['id'] ?>">
                            <div class="form-grid two">
                                <label class="field compact-field"><span>Disponibilidade do assistente</span><select name="status"><option value="active" <?= $agent['status'] === 'active' ? 'selected' : '' ?>>Ativo</option><option value="inactive" <?= $agent['status'] === 'inactive' ? 'selected' : '' ?>>Inativo</option></select></label>
                                <label class="field compact-field"><span>Mensagens lembradas</span><input type="number" name="max_context_messages" value="<?= (int) ($agent['max_context_messages'] ?? 12) ?>" min="4" max="30"></label>
                            </div>
                            <label class="field compact-field"><span>Palavras que pedem atendimento humano</span><input name="handoff_keywords" value="<?= View::e($agent['handoff_keywords'] ?? '') ?>" placeholder="humano, atendente, pessoa"></label>
                            <div class="form-grid two">
                                <label class="field compact-field"><span>Ao chamar uma pessoa</span><select name="handoff_action"><option value="paused" <?= ($agent['handoff_action'] ?? 'paused') === 'paused' ? 'selected' : '' ?>>Pausar respostas automáticas</option><option value="human" <?= ($agent['handoff_action'] ?? '') === 'human' ? 'selected' : '' ?>>Marcar atendimento humano</option></select></label>
                                <label class="field compact-field"><span>Intervalo mínimo entre respostas (seg.)</span><input type="number" name="cooldown_seconds" value="<?= (int) ($agent['cooldown_seconds'] ?? 15) ?>" min="0" max="3600"></label>
                            </div>
                            <label class="field compact-field"><span>Mensagem ao encaminhar para a equipe</span><input name="human_handoff_message" value="<?= View::e($agent['human_handoff_message'] ?? '') ?>" placeholder="Vou encaminhar você para uma pessoa da equipe."></label>
                            <div class="form-grid two">
                                <label class="field compact-field"><span>Atendimento começa</span><input type="time" name="business_start" value="<?= View::e($start) ?>"></label>
                                <label class="field compact-field"><span>Atendimento termina</span><input type="time" name="business_end" value="<?= View::e($end) ?>"></label>
                            </div>
                            <label class="field compact-field"><span>Fuso horário</span><input name="business_timezone" value="<?= View::e($agent['business_timezone'] ?? 'America/Sao_Paulo') ?>"></label>
                            <div class="weekday-row">
                                <?php foreach ($dayLabels as $dayKey => $label): ?>
                                    <label class="check-field compact-check"><input type="checkbox" name="business_days[]" value="<?= View::e($dayKey) ?>" <?= in_array($dayKey, $days, true) ? 'checked' : '' ?>><span><?= View::e($label) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                            <label class="field compact-field"><span>Mensagem fora do horário</span><input name="after_hours_message" value="<?= View::e($agent['after_hours_message'] ?? '') ?>" placeholder="Estamos fora do horário. Retornaremos em breve."></label>
                            <label class="field compact-field"><span>Integração externa deste assistente</span><input name="n8n_webhook_url" value="<?= View::e($agent['n8n_webhook_url'] ?? '') ?>" placeholder="Preencha somente com orientação da equipe RS Connect"></label>
                            <div class="agent-toggle-grid">
                                <label class="check-field compact-check"><input type="checkbox" name="auto_reply_enabled" value="1" <?= (int) ($agent['auto_reply_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><span>Responder automaticamente</span></label>
                                <label class="check-field compact-check"><input type="checkbox" name="business_hours_enabled" value="1" <?= (int) ($agent['business_hours_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><span>Seguir horário de atendimento</span></label>
                                <label class="check-field compact-check"><input type="checkbox" name="n8n_enabled" value="1" <?= (int) ($agent['n8n_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><span>Usar integração externa</span></label>
                                <label class="check-field compact-check"><input type="checkbox" name="is_default" value="1" <?= (int) $agent['is_default'] === 1 ? 'checked' : '' ?>><span>Assistente principal</span></label>
                            </div>
                            <button class="btn btn-outline" type="submit">Salvar configurações</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$agents): ?><div class="empty-state">Nenhum assistente cadastrado ainda.</div><?php endif; ?>
        </div>
    </section>
</div>

<?php if ($canManage): ?>
<aside class="conversation-details agent-create-drawer" id="agent-create-drawer" aria-label="Cadastrar novo assistente">
    <div class="conversation-drawer-header">
        <div>
            <span class="eyebrow">Novo assistente</span>
            <h2>Criar assistente virtual</h2>
            <p>Preencha primeiro as informações essenciais. As opções técnicas ficam agrupadas no final.</p>
        </div>
        <button class="icon-button drawer-close" type="button" data-close-panel="agent-create-drawer" aria-label="Fechar painel">×</button>
    </div>

    <div class="conversation-drawer-body">
        <form class="drawer-form agent-create-form" method="post" action="<?= View::e(Router::url('/agents')) ?>">
            <?= Csrf::input() ?>

            <section class="drawer-section">
                <div class="drawer-section-title"><div><span class="eyebrow">1. Identificação</span><h3>Quem vai atender?</h3></div></div>
                <label class="field"><span>Conexão WhatsApp</span><select name="instance_id" required><option value="">Selecione o WhatsApp</option><?php foreach ($instances as $instance): ?><option value="<?= (int) $instance['id'] ?>"><?= View::e($instance['name']) ?></option><?php endforeach; ?></select><small class="field-hint">Escolha em qual WhatsApp este assistente responderá.</small></label>
                <label class="field"><span>Nome do assistente</span><input name="name" placeholder="Ex.: Digi, Assistente Comercial" required></label>
                <label class="field"><span>Área de atendimento</span><input name="segment" placeholder="Ex.: vendas, suporte, agendamentos" required><small class="field-hint">Ajuda a identificar a função principal do assistente.</small></label>
            </section>

            <section class="drawer-section">
                <div class="drawer-section-title"><div><span class="eyebrow">2. Atendimento</span><h3>O que ele deve saber e fazer?</h3></div></div>
                <label class="field"><span>Como o assistente deve atender</span><textarea name="system_prompt" rows="9" placeholder="Explique o tom de voz, as etapas do atendimento, o que ele pode fazer e quando deve chamar uma pessoa da equipe." required></textarea></label>
                <label class="field"><span>Informações da empresa</span><textarea name="knowledge_base" rows="7" placeholder="Inclua serviços, horários, perguntas frequentes, regras, links e informações que podem ser usadas nas respostas."></textarea></label>
            </section>

            <section class="drawer-section agent-create-behavior">
                <div class="drawer-section-title"><div><span class="eyebrow">3. Funcionamento</span><h3>Comportamento inicial</h3></div></div>
                <label class="check-field"><input type="checkbox" name="auto_reply_enabled" value="1" checked><span>Responder automaticamente quando a conversa estiver com IA ativa</span></label>
                <label class="check-field"><input type="checkbox" name="is_default" value="1"><span>Usar como assistente principal desta empresa</span></label>
            </section>

            <details class="drawer-section drawer-collapsed-card agent-advanced-settings">
                <summary>
                    <span><span class="eyebrow">Opcional</span><strong>Configurações avançadas</strong><small>Modelo de IA, horários, transferência e integração externa.</small></span>
                    <span class="drawer-chevron"></span>
                </summary>
                <div class="agent-advanced-body">
                    <div class="form-grid two">
                        <label class="field"><span>Serviço de IA</span><select name="model_provider"><option value="openai">OpenAI</option><option value="google">Google Gemini</option><option value="custom">Outro serviço</option></select></label>
                        <label class="field"><span>Estilo das respostas</span><select name="temperature"><option value="0.1">Mais objetivo</option><option value="0.2" selected>Equilibrado</option><option value="0.5">Mais criativo</option><option value="0.8">Bem criativo</option></select></label>
                    </div>
                    <label class="field"><span>Modelo de IA</span><input name="model_name" value="gpt-4o-mini" required><small class="field-hint">A equipe RS Connect pode ajustar este campo conforme a credencial disponível.</small></label>
                    <label class="field"><span>Palavras que pedem atendimento humano</span><input name="handoff_keywords" value="humano, atendente, pessoa, suporte" placeholder="humano, atendente, pessoa"></label>
                    <label class="field"><span>Mensagem ao encaminhar para a equipe</span><input name="human_handoff_message" value="Vou encaminhar você para uma pessoa da nossa equipe. Aguarde um momento, por favor."></label>
                    <input type="hidden" name="handoff_action" value="paused">
                    <div class="form-grid two"><label class="field"><span>Atendimento começa</span><input type="time" name="business_start" value="08:00"></label><label class="field"><span>Atendimento termina</span><input type="time" name="business_end" value="18:00"></label></div>
                    <label class="field"><span>Fuso horário</span><input name="business_timezone" value="America/Sao_Paulo"></label>
                    <div class="weekday-row"><?php foreach ($dayLabels as $dayKey => $label): ?><label class="check-field compact-check"><input type="checkbox" name="business_days[]" value="<?= View::e($dayKey) ?>" <?= in_array($dayKey, ['mon', 'tue', 'wed', 'thu', 'fri'], true) ? 'checked' : '' ?>><span><?= View::e($label) ?></span></label><?php endforeach; ?></div>
                    <label class="field"><span>Mensagem fora do horário</span><input name="after_hours_message" value="Estamos fora do horário de atendimento agora. Assim que retornarmos, nossa equipe responde por aqui."></label>
                    <div class="form-grid two"><label class="field"><span>Mensagens lembradas</span><input type="number" name="max_context_messages" value="12" min="4" max="30"></label><label class="field"><span>Intervalo entre respostas (seg.)</span><input type="number" name="cooldown_seconds" value="15" min="0" max="3600"></label></div>
                    <label class="field"><span>Integração externa</span><input name="n8n_webhook_url" placeholder="Preencha somente com orientação da equipe RS Connect"></label>
                    <label class="check-field"><input type="checkbox" name="business_hours_enabled" value="1"><span>Responder somente no horário configurado</span></label>
                    <label class="check-field"><input type="checkbox" name="n8n_enabled" value="1"><span>Usar integração externa neste assistente</span></label>
                </div>
            </details>

            <div class="drawer-savebar agent-create-savebar">
                <button class="btn btn-primary btn-block" type="submit" <?= !$instances ? 'disabled' : '' ?>>Criar assistente</button>
                <?php if (!$instances): ?><p class="field-hint">A equipe RS Connect precisa preparar uma conexão WhatsApp primeiro.</p><?php endif; ?>
            </div>
        </form>
    </div>
</aside>
<?php endif; ?>
