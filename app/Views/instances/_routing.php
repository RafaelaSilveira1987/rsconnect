<?php
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$instance = is_array($instance ?? null) ? $instance : [];
$allAgents = is_array($allAgents ?? null) ? $allAgents : [];
$bindings = is_array($bindings ?? null) ? $bindings : [];
$canRoute = $canRoute ?? false;
$bindingByAgent = [];
foreach ($bindings as $binding) {
    $bindingByAgent[(int) ($binding['agent_id'] ?? 0)] = $binding;
}
$primary = 0;
foreach ($bindings as $binding) {
    if ((int) ($binding['is_primary'] ?? 0) === 1) {
        $primary = (int) ($binding['agent_id'] ?? 0);
        break;
    }
}
?>
<div class="channel-routing-summary">
    <?php if ($bindings): ?>
        <div class="channel-agent-chips">
            <?php foreach ($bindings as $binding): ?>
                <span class="channel-agent-chip <?= (int) ($binding['is_primary'] ?? 0) === 1 ? 'is-primary' : '' ?>">
                    <strong><?= View::e((string) ($binding['name'] ?? $binding['agent_name'] ?? 'Assistente')) ?></strong>
                    <small><?= (int) ($binding['is_primary'] ?? 0) === 1 ? 'Principal' : 'Especialista' ?></small>
                </span>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="channel-routing-empty">Nenhum assistente foi vinculado a este canal ainda.</p>
    <?php endif; ?>
</div>

<?php if ($canRoute): ?>
<details class="channel-routing-editor">
    <summary>
        <span><strong>Agentes e roteamento</strong><small>Defina quem atende neste número e quando usar cada especialista.</small></span>
        <span class="drawer-chevron"></span>
    </summary>
    <form method="post" action="<?= View::e(Router::url('/instances/routing')) ?>" class="channel-routing-form">
        <?= Csrf::input() ?>
        <input type="hidden" name="instance_id" value="<?= (int) ($instance['id'] ?? 0) ?>">
        <div class="channel-routing-help">
            <strong>Como funciona</strong>
            <span>O principal recebe novas conversas. Se a primeira mensagem contiver uma palavra de roteamento de um especialista, a conversa é direcionada a ele e permanece com esse agente. Também é possível deixar o canal sem agente para atendimento exclusivamente humano.</span>
        </div>
        <div class="channel-routing-agents">
            <?php foreach ($allAgents as $agent): ?>
                <?php
                $agentId = (int) ($agent['id'] ?? 0);
                $binding = $bindingByAgent[$agentId] ?? null;
                $checked = $binding !== null;
                $isPrimary = $primary === $agentId;
                ?>
                <section class="channel-routing-agent <?= $checked ? 'is-linked' : '' ?>">
                    <div class="channel-routing-agent-head">
                        <label class="check-field compact-check">
                            <input type="checkbox" name="agent_ids[]" value="<?= $agentId ?>" <?= $checked ? 'checked' : '' ?>>
                            <span><strong><?= View::e((string) ($agent['name'] ?? 'Assistente')) ?></strong><small><?= View::e((string) ($agent['segment'] ?? 'Atendimento')) ?></small></span>
                        </label>
                        <label class="channel-primary-radio" title="Agente principal deste WhatsApp">
                            <input type="radio" name="primary_agent_id" value="<?= $agentId ?>" <?= $isPrimary ? 'checked' : '' ?>>
                            <span>Principal</span>
                        </label>
                    </div>
                    <div class="channel-routing-agent-fields">
                        <label class="field">
                            <span>Palavras de roteamento</span>
                            <input name="routing_keywords[<?= $agentId ?>]" value="<?= View::e((string) ($binding['routing_keywords'] ?? '')) ?>" placeholder="Ex.: agendar, remarcar, horário, consulta">
                            <small class="field-hint">Opcional. Use vírgulas. Sem palavras, o agente só recebe conversas quando for principal ou escolhido manualmente.</small>
                        </label>
                        <label class="field compact-field channel-priority-field">
                            <span>Prioridade</span>
                            <input type="number" name="priority[<?= $agentId ?>]" min="1" max="999" value="<?= (int) ($binding['priority'] ?? ($isPrimary ? 200 : 100)) ?>">
                        </label>
                    </div>
                </section>
            <?php endforeach; ?>
            <?php if (!$allAgents): ?><div class="empty-state">Cadastre um assistente antes de configurar o roteamento.</div><?php endif; ?>
        </div>
        <div class="channel-routing-actions">
            <a class="btn btn-quiet" href="<?= View::e(Router::url('/agents')) ?>">Abrir assistentes</a>
            <button class="btn btn-primary" type="submit" <?= !$allAgents ? 'disabled' : '' ?>>Salvar roteamento</button>
        </div>
    </form>
</details>
<?php endif; ?>
