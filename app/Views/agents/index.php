<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
?>
<div class="content-grid management-layout">
    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Configuração de IA</span><h2>Agentes cadastrados</h2></div>
            <span class="badge"><?= count($agents) ?> agente(s)</span>
        </div>
        <div class="agent-grid">
            <?php foreach ($agents as $agent): ?>
                <article class="agent-card">
                    <div class="agent-card-head">
                        <span class="agent-icon">✦</span>
                        <div><h3><?= View::e($agent['name']) ?></h3><p><?= View::e($agent['segment']) ?></p></div>
                    </div>
                    <div class="agent-data">
                        <div><span>Instância</span><strong><?= View::e($agent['instance_name'] ?? 'Não vinculada') ?></strong></div>
                        <div><span>Modelo</span><strong><?= View::e($agent['model_name']) ?></strong></div>
                        <div><span>Temperatura</span><strong><?= View::e($agent['temperature']) ?></strong></div>
                        <div><span>Contexto</span><strong><?= (int) ($agent['max_context_messages'] ?? 12) ?> msg</strong></div>
                    </div>
                    <div class="badge-row">
                        <span class="badge badge-<?= View::e($agent['status']) ?>"><?= $agent['status'] === 'active' ? 'Ativo' : 'Inativo' ?></span>
                        <span class="badge <?= (int) ($agent['auto_reply_enabled'] ?? 0) === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) ($agent['auto_reply_enabled'] ?? 0) === 1 ? 'Auto-resposta ON' : 'Auto-resposta OFF' ?></span>
                        <?php if ((int) $agent['is_default'] === 1): ?><span class="badge">Padrão</span><?php endif; ?>
                        <?php if ((int) ($agent['n8n_enabled'] ?? 0) === 1): ?><span class="badge">n8n</span><?php endif; ?>
                    </div>
                    <details class="agent-prompt"><summary>Ver prompt</summary><pre><?= View::e($agent['system_prompt']) ?></pre></details>
                    <?php if (Auth::can('agents.manage')): ?>
                        <form class="agent-actions agent-settings-form" method="post" action="<?= View::e(Router::url('/agents/status')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="agent_id" value="<?= (int) $agent['id'] ?>">
                            <div class="form-grid two">
                                <label class="field compact-field"><span>Status</span><select name="status"><option value="active" <?= $agent['status'] === 'active' ? 'selected' : '' ?>>Ativo</option><option value="inactive" <?= $agent['status'] === 'inactive' ? 'selected' : '' ?>>Inativo</option></select></label>
                                <label class="field compact-field"><span>Contexto</span><input type="number" name="max_context_messages" value="<?= (int) ($agent['max_context_messages'] ?? 12) ?>" min="4" max="30"></label>
                            </div>
                            <label class="field compact-field"><span>Palavras para transferir ao humano</span><input name="handoff_keywords" value="<?= View::e($agent['handoff_keywords'] ?? '') ?>" placeholder="humano, atendente, pessoa"></label>
                            <label class="field compact-field"><span>Webhook n8n deste agente</span><input name="n8n_webhook_url" value="<?= View::e($agent['n8n_webhook_url'] ?? '') ?>" placeholder="https://n8n.../webhook/..."></label>
                            <div class="agent-toggle-grid">
                                <label class="check-field compact-check"><input type="checkbox" name="auto_reply_enabled" value="1" <?= (int) ($agent['auto_reply_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><span>Responder automaticamente</span></label>
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
                <div class="form-grid two"><label class="field"><span>Provedor</span><select name="model_provider"><option value="openai">OpenAI</option><option value="google">Google Gemini</option><option value="anthropic">Anthropic em breve</option><option value="custom">Custom em breve</option></select></label><label class="field"><span>Temperatura</span><input type="number" name="temperature" value="0.2" min="0" max="1" step="0.1"></label></div>
                <label class="field"><span>Modelo</span><input name="model_name" value="gpt-4o-mini" required><small class="field-hint">Para OpenAI, use um modelo disponível no seu projeto, por exemplo gpt-4o-mini.</small></label>
                <label class="field"><span>Prompt</span><textarea name="system_prompt" rows="7" placeholder="Defina função, tom, limites e processo de atendimento." required></textarea></label>
                <label class="field"><span>Base de conhecimento</span><textarea name="knowledge_base" rows="5" placeholder="Serviços, horários, links, regras, preços permitidos e limites do atendimento."></textarea></label>
                <label class="field"><span>Palavras para transferir</span><input name="handoff_keywords" value="humano, atendente, pessoa, suporte" placeholder="humano, atendente, pessoa"></label>
                <div class="form-grid two"><label class="field"><span>Mensagens de contexto</span><input type="number" name="max_context_messages" value="12" min="4" max="30"></label><label class="field"><span>Webhook n8n</span><input name="n8n_webhook_url" placeholder="Opcional"></label></div>
                <label class="check-field"><input type="checkbox" name="auto_reply_enabled" value="1"><span>Responder automaticamente quando a conversa estiver em IA ativa</span></label>
                <label class="check-field"><input type="checkbox" name="n8n_enabled" value="1"><span>Enviar eventos deste agente para n8n</span></label>
                <label class="check-field"><input type="checkbox" name="is_default" value="1"><span>Definir como agente padrão</span></label>
                <button class="btn btn-primary btn-block" type="submit" <?= !$instances ? 'disabled' : '' ?>>Salvar agente</button>
                <?php if (!$instances): ?><p class="field-hint">Cadastre uma instância antes de criar o agente.</p><?php endif; ?>
            </form>
        </aside>
    <?php endif; ?>
</div>
