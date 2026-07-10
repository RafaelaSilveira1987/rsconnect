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
?>
<div class="content-grid management-layout">
    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Configuração de IA</span><h2>Agentes cadastrados</h2></div>
            <span class="badge"><?= count($agents) ?> agente(s)</span>
        </div>
        <div class="agent-grid">
            <?php foreach ($agents as $agent): ?>
                <?php [$start, $end] = $firstHours($agent['business_hours_json'] ?? null); $days = $selectedDays($agent['business_hours_json'] ?? null); ?>
                <article class="agent-card">
                    <div class="agent-card-head">
                        <span class="agent-icon agent-icon-bot" aria-hidden="true"></span>
                        <div><h3><?= View::e($agent['name']) ?></h3><p><?= View::e($agent['segment']) ?></p></div>
                    </div>
                    <div class="agent-data">
                        <div><span>Instância</span><strong><?= View::e($agent['instance_name'] ?? 'Não vinculada') ?></strong></div>
                        <div><span>Modelo</span><strong><?= View::e($agent['credential_model'] ?: $agent['model_name']) ?></strong></div>
                        <div><span>Credencial</span><strong><?= View::e($agent['credential_label'] ?: 'Global RS/.env') ?></strong></div>
                        <div><span>Contexto</span><strong><?= (int) ($agent['max_context_messages'] ?? 12) ?> msg</strong></div>
                    </div>
                    <div class="badge-row">
                        <span class="badge badge-<?= View::e($agent['status']) ?>"><?= $agent['status'] === 'active' ? 'Ativo' : 'Inativo' ?></span>
                        <span class="badge <?= (int) ($agent['auto_reply_enabled'] ?? 0) === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) ($agent['auto_reply_enabled'] ?? 0) === 1 ? 'Auto-resposta ON' : 'Auto-resposta OFF' ?></span>
                        <?php if ((int) $agent['is_default'] === 1): ?><span class="badge">Padrão</span><?php endif; ?>
                        <?php if ((int) ($agent['business_hours_enabled'] ?? 0) === 1): ?><span class="badge">Horário</span><?php endif; ?>
                    </div>
                    <details class="agent-prompt"><summary>Ver prompt/base</summary><pre><?= View::e($agent['system_prompt']) ?></pre><?php if (!empty($agent['knowledge_base'])): ?><pre><?= View::e($agent['knowledge_base']) ?></pre><?php endif; ?></details>
                    <?php if (Auth::can('agents.manage')): ?>
                        <form class="agent-actions agent-settings-form" method="post" action="<?= View::e(Router::url('/agents/status')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="agent_id" value="<?= (int) $agent['id'] ?>">
                            <div class="form-grid two">
                                <label class="field compact-field"><span>Status</span><select name="status"><option value="active" <?= $agent['status'] === 'active' ? 'selected' : '' ?>>Ativo</option><option value="inactive" <?= $agent['status'] === 'inactive' ? 'selected' : '' ?>>Inativo</option></select></label>
                                <label class="field compact-field"><span>Contexto</span><input type="number" name="max_context_messages" value="<?= (int) ($agent['max_context_messages'] ?? 12) ?>" min="4" max="30"></label>
                            </div>
                            <label class="field compact-field"><span>Palavras para transferir ao humano</span><input name="handoff_keywords" value="<?= View::e($agent['handoff_keywords'] ?? '') ?>" placeholder="humano, atendente, pessoa"></label>
                            <div class="form-grid two">
                                <label class="field compact-field"><span>Ação de transferência</span><select name="handoff_action"><option value="paused" <?= ($agent['handoff_action'] ?? 'paused') === 'paused' ? 'selected' : '' ?>>Pausar IA</option><option value="human" <?= ($agent['handoff_action'] ?? '') === 'human' ? 'selected' : '' ?>>Assumir humano</option></select></label>
                                <label class="field compact-field"><span>Cooldown anti-loop (seg.)</span><input type="number" name="cooldown_seconds" value="<?= (int) ($agent['cooldown_seconds'] ?? 15) ?>" min="0" max="3600"></label>
                            </div>
                            <label class="field compact-field"><span>Mensagem ao transferir</span><input name="human_handoff_message" value="<?= View::e($agent['human_handoff_message'] ?? '') ?>" placeholder="Vou encaminhar você para uma pessoa da equipe."></label>
                            <div class="form-grid two">
                                <label class="field compact-field"><span>Início</span><input type="time" name="business_start" value="<?= View::e($start) ?>"></label>
                                <label class="field compact-field"><span>Fim</span><input type="time" name="business_end" value="<?= View::e($end) ?>"></label>
                            </div>
                            <label class="field compact-field"><span>Fuso</span><input name="business_timezone" value="<?= View::e($agent['business_timezone'] ?? 'America/Sao_Paulo') ?>"></label>
                            <div class="weekday-row">
                                <?php foreach ($dayLabels as $dayKey => $label): ?>
                                    <label class="check-field compact-check"><input type="checkbox" name="business_days[]" value="<?= View::e($dayKey) ?>" <?= in_array($dayKey, $days, true) ? 'checked' : '' ?>><span><?= View::e($label) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                            <label class="field compact-field"><span>Mensagem fora do horário</span><input name="after_hours_message" value="<?= View::e($agent['after_hours_message'] ?? '') ?>" placeholder="Estamos fora do horário. Retornaremos em breve."></label>
                            <label class="field compact-field"><span>Webhook n8n deste agente</span><input name="n8n_webhook_url" value="<?= View::e($agent['n8n_webhook_url'] ?? '') ?>" placeholder="https://n8n.../webhook/..."></label>
                            <div class="agent-toggle-grid">
                                <label class="check-field compact-check"><input type="checkbox" name="auto_reply_enabled" value="1" <?= (int) ($agent['auto_reply_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><span>Responder automaticamente</span></label>
                                <label class="check-field compact-check"><input type="checkbox" name="business_hours_enabled" value="1" <?= (int) ($agent['business_hours_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><span>Respeitar horário</span></label>
                                <label class="check-field compact-check"><input type="checkbox" name="n8n_enabled" value="1" <?= (int) ($agent['n8n_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><span>Disparar n8n</span></label>
                                <label class="check-field compact-check"><input type="checkbox" name="is_default" value="1" <?= (int) $agent['is_default'] === 1 ? 'checked' : '' ?>><span>Padrão</span></label>
                            </div>
                            <button class="btn btn-outline" type="submit">Atualizar automação</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$agents): ?><div class="empty-state">Nenhum agente cadastrado. Conclua o onboarding ou use o formulário ao lado.</div><?php endif; ?>
        </div>
    </section>

    <?php if (Auth::can('agents.manage')): ?>
        <aside class="stack">
            <form class="card sticky-card" method="post" action="<?= View::e(Router::url('/agents')) ?>">
                <?= Csrf::input() ?>
                <div class="section-heading"><div><span class="eyebrow">Novo perfil</span><h2>Cadastrar agente</h2></div></div>
                <label class="field"><span>Instância</span><select name="instance_id" required><option value="">Selecione</option><?php foreach ($instances as $instance): ?><option value="<?= (int) $instance['id'] ?>"><?= View::e($instance['name']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Nome</span><input name="name" placeholder="Assistente Comercial" required></label>
                <label class="field"><span>Segmento</span><input name="segment" placeholder="Comercial, suporte, agendamento..." required></label>
                <div class="form-grid two"><label class="field"><span>Provedor</span><select name="model_provider"><option value="openai">OpenAI</option><option value="google">Google Gemini</option><option value="custom">Custom</option></select></label><label class="field"><span>Temperatura</span><input type="number" name="temperature" value="0.2" min="0" max="1" step="0.1"></label></div>
                <label class="field"><span>Modelo</span><input name="model_name" value="gpt-4o-mini" required><small class="field-hint">A API Key pode ser global da RS ou configurada pelo Super Admin em Credenciais de IA.</small></label>
                <label class="field"><span>Prompt</span><textarea name="system_prompt" rows="7" placeholder="Defina função, tom, limites e processo de atendimento." required></textarea></label>
                <label class="field"><span>Base de conhecimento</span><textarea name="knowledge_base" rows="5" placeholder="Serviços, horários, links, regras, preços permitidos e limites do atendimento."></textarea></label>
                <label class="field"><span>Palavras para transferir</span><input name="handoff_keywords" value="humano, atendente, pessoa, suporte" placeholder="humano, atendente, pessoa"></label>
                <label class="field"><span>Mensagem ao transferir</span><input name="human_handoff_message" value="Vou encaminhar você para uma pessoa da nossa equipe. Aguarde um momento, por favor."></label>
                <div class="form-grid two"><label class="field"><span>Início</span><input type="time" name="business_start" value="08:00"></label><label class="field"><span>Fim</span><input type="time" name="business_end" value="18:00"></label></div>
                <label class="field"><span>Fuso</span><input name="business_timezone" value="America/Sao_Paulo"></label>
                <div class="weekday-row"><?php foreach ($dayLabels as $dayKey => $label): ?><label class="check-field compact-check"><input type="checkbox" name="business_days[]" value="<?= View::e($dayKey) ?>" <?= in_array($dayKey, ['mon', 'tue', 'wed', 'thu', 'fri'], true) ? 'checked' : '' ?>><span><?= View::e($label) ?></span></label><?php endforeach; ?></div>
                <label class="field"><span>Mensagem fora do horário</span><input name="after_hours_message" value="Estamos fora do horário de atendimento agora. Assim que retornarmos, nossa equipe te responde por aqui."></label>
                <div class="form-grid two"><label class="field"><span>Mensagens de contexto</span><input type="number" name="max_context_messages" value="12" min="4" max="30"></label><label class="field"><span>Cooldown anti-loop</span><input type="number" name="cooldown_seconds" value="15" min="0" max="3600"></label></div>
                <label class="field"><span>Webhook n8n</span><input name="n8n_webhook_url" placeholder="Opcional"></label>
                <label class="check-field"><input type="checkbox" name="auto_reply_enabled" value="1"><span>Responder automaticamente quando a conversa estiver em IA ativa</span></label>
                <label class="check-field"><input type="checkbox" name="business_hours_enabled" value="1"><span>Respeitar horário de atendimento</span></label>
                <label class="check-field"><input type="checkbox" name="n8n_enabled" value="1"><span>Enviar eventos deste agente para n8n</span></label>
                <label class="check-field"><input type="checkbox" name="is_default" value="1"><span>Definir como agente padrão</span></label>
                <button class="btn btn-primary btn-block" type="submit" <?= !$instances ? 'disabled' : '' ?>>Salvar agente</button>
                <?php if (!$instances): ?><p class="field-hint">Cadastre uma instância antes de criar o agente.</p><?php endif; ?>
            </form>
        </aside>
    <?php endif; ?>
</div>
