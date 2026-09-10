<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$tenants = is_array($tenants ?? null) ? $tenants : [];
$agents = is_array($agents ?? null) ? $agents : [];
$scenarios = is_array($scenarios ?? null) ? $scenarios : [];
$runs = is_array($runs ?? null) ? $runs : [];
$selectedTenantId = (int) ($selectedTenantId ?? 0);
$selectedAgentId = (int) ($selectedAgentId ?? 0);
$migrationReady = !empty($migrationReady);
$sourceConversationId = (int) ($sourceConversationId ?? 0);
$labVersion = (string) ($labVersion ?? '36.29.4');
$csrfToken = Csrf::token();
$selectedAgent = null;
foreach ($agents as $agentRow) {
    if ((int) ($agentRow['id'] ?? 0) === $selectedAgentId) {
        $selectedAgent = $agentRow;
        break;
    }
}
$statusLabel = static fn (string $status): string => match ($status) {
    'passed' => 'Aprovado',
    'failed' => 'Falhou',
    'error' => 'Erro',
    'running' => 'Executando',
    default => 'Ainda não testado',
};
?>
<style>
.agent-lab-page{display:grid;gap:22px}.agent-lab-hero{display:flex;justify-content:space-between;gap:20px;align-items:flex-start}.agent-lab-hero h2{margin:4px 0 8px}.agent-lab-selector{display:grid;grid-template-columns:minmax(220px,1fr) minmax(280px,1.2fr) auto;gap:12px;align-items:end;min-width:min(720px,100%)}.agent-lab-select-form{margin:0;min-width:0}.agent-lab-select-form .field{margin:0}.agent-lab-select-form select{width:100%;min-width:0}.agent-lab-select-hint{display:block;margin-top:5px;color:var(--muted,#64748b);font-size:.78rem}.agent-lab-version{display:inline-flex;margin-left:8px;padding:3px 8px;border-radius:999px;background:#eef6ff;color:#24598a;font-size:.75rem;font-weight:700;vertical-align:middle}.agent-lab-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);gap:18px}.agent-lab-chat{min-height:560px;display:flex;flex-direction:column}.agent-lab-chat-window{flex:1;min-height:380px;max-height:580px;overflow:auto;border:1px solid var(--border,#dbe4ee);border-radius:18px;padding:18px;background:linear-gradient(180deg,#f8fbfd,#fff);display:flex;flex-direction:column;gap:12px}.agent-lab-bubble{max-width:82%;padding:12px 14px;border-radius:15px;line-height:1.45;white-space:pre-wrap;word-break:break-word}.agent-lab-bubble.user{margin-left:auto;background:#e9f6f3;border-bottom-right-radius:5px}.agent-lab-bubble.assistant{margin-right:auto;background:#eef3f8;border-bottom-left-radius:5px}.agent-lab-bubble.system{max-width:100%;background:#fff8df;border:1px solid #f0df9f;font-size:.92rem}.agent-lab-composer{display:grid;grid-template-columns:1fr auto;gap:10px;margin-top:12px}.agent-lab-composer textarea{min-height:74px;resize:vertical}.agent-lab-mode{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}.agent-lab-mode label{display:flex;gap:8px;align-items:flex-start;border:1px solid var(--border,#dbe4ee);border-radius:14px;padding:10px 12px;cursor:pointer;flex:1;min-width:220px}.agent-lab-mode small{display:block;color:var(--muted,#64748b);margin-top:2px}.agent-lab-diagnostics{display:grid;gap:12px}.agent-lab-diagnostic-card{border:1px solid var(--border,#dbe4ee);border-radius:16px;padding:14px;background:#fff}.agent-lab-diagnostic-card strong{display:block;margin-bottom:7px}.agent-lab-pill{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;background:#edf2f7;font-size:.82rem;font-weight:700}.agent-lab-pill.ok{background:#dcfce7;color:#166534}.agent-lab-pill.block{background:#fee2e2;color:#991b1b}.agent-lab-pill.warn{background:#fef3c7;color:#92400e}.agent-lab-data{display:grid;gap:6px;margin-top:10px}.agent-lab-data div{display:flex;justify-content:space-between;gap:15px;border-bottom:1px dashed #e2e8f0;padding-bottom:6px}.agent-lab-data span{color:#64748b}.agent-lab-scenarios{display:grid;gap:12px}.agent-lab-scenario{border:1px solid var(--border,#dbe4ee);border-radius:16px;padding:15px;display:grid;grid-template-columns:1fr auto;gap:14px;align-items:center}.agent-lab-scenario h4{margin:0 0 4px}.agent-lab-scenario p{margin:0;color:#64748b}.agent-lab-scenario-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.agent-lab-history{overflow:auto}.agent-lab-history table{width:100%;border-collapse:collapse}.agent-lab-history th,.agent-lab-history td{text-align:left;padding:10px;border-bottom:1px solid #e5edf4;white-space:nowrap}.agent-lab-warning{padding:14px;border:1px solid #f1d68a;background:#fff8dd;border-radius:14px}.agent-lab-empty{padding:28px;text-align:center;color:#64748b;border:1px dashed #cbd5e1;border-radius:16px}.agent-lab-inline-form{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.agent-lab-inline-form .field{min-width:220px}.agent-lab-meta{font-size:.82rem;color:#64748b;margin-top:5px}.agent-lab-loader{display:none}.agent-lab-loader.is-visible{display:inline-flex}@media(max-width:1050px){.agent-lab-hero{flex-direction:column}.agent-lab-selector{min-width:0;width:100%}.agent-lab-grid{grid-template-columns:1fr}}@media(max-width:720px){.agent-lab-selector{grid-template-columns:1fr}.agent-lab-scenario{grid-template-columns:1fr}.agent-lab-composer{grid-template-columns:1fr}.agent-lab-bubble{max-width:92%}}
</style>

<div class="agent-lab-page" data-agent-lab data-simulate-url="<?= View::e(Router::url('/agent-tests/simulate')) ?>" data-agent-list-url="<?= View::e(Router::url('/agent-tests/agents')) ?>" data-page-url="<?= View::e(Router::url('/agent-tests')) ?>" data-server-tenant-id="<?= $selectedTenantId ?>" data-server-agent-id="<?= $selectedAgentId ?>" data-csrf="<?= View::e($csrfToken) ?>">
    <section class="card">
        <div class="agent-lab-hero">
            <div>
                <span class="eyebrow">Homologação dos assistentes</span><span class="agent-lab-version">Lab <?= View::e($labVersion) ?></span>
                <h2>Laboratório de assistentes</h2>
                <p>Converse com o assistente sem enviar nada ao WhatsApp. O laboratório mostra a resposta, as informações coletadas e quais regras foram aplicadas.</p>
            </div>
            <div class="agent-lab-selector" aria-label="Selecionar empresa e assistente">
                <form class="agent-lab-select-form" method="get" action="<?= View::e(Router::url('/agent-tests')) ?>" autocomplete="off" onsubmit="return false;">
                    <label class="field"><span>Empresa</span><select name="tenant_id" required autocomplete="off" data-lab-tenant-select data-server-value="<?= $selectedTenantId ?>">
                        <?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>" <?= $selectedTenantId === (int) $tenant['id'] ? 'selected' : '' ?>>#<?= (int) $tenant['id'] ?> · <?= View::e((string) $tenant['name']) ?></option><?php endforeach; ?>
                    </select><small class="agent-lab-select-hint">Empresa carregada pelo servidor: #<?= $selectedTenantId ?>. Ao trocar, a lista de assistentes é recarregada do banco.</small></label>
                </form>
                <form class="agent-lab-select-form" method="get" action="<?= View::e(Router::url('/agent-tests')) ?>" autocomplete="off" onsubmit="return false;">
                    <label class="field"><span>Assistente</span><select name="agent_id" required autocomplete="off" data-lab-agent-select data-server-value="<?= $selectedAgentId ?>" <?= $agents === [] ? 'disabled' : '' ?>>
                        <?php foreach ($agents as $agent):
                            $agentStatus = (string) ($agent['status'] ?? '');
                            $statusSuffix = $agentStatus === 'active' ? ' · ativo' : ($agentStatus !== '' ? ' · ' . $agentStatus : '');
                        ?><option value="<?= (int) $agent['id'] ?>" <?= $selectedAgentId === (int) $agent['id'] ? 'selected' : '' ?>>#<?= (int) $agent['id'] ?> · <?= View::e((string) $agent['name']) ?> · <?= View::e((string) $agent['model_name']) ?><?= View::e($statusSuffix) ?></option><?php endforeach; ?>
                    </select><small class="agent-lab-select-hint"><?php if (count($agents) > 1): ?><?= count($agents) ?> assistentes encontrados na empresa #<?= $selectedTenantId ?>.<?php elseif (count($agents) === 1): ?>1 assistente encontrado na empresa #<?= $selectedTenantId ?>: #<?= $selectedAgentId ?>.<?php else: ?>Nenhum assistente cadastrado para a empresa #<?= $selectedTenantId ?>.<?php endif; ?></small></label>
                </form>
                <a class="btn btn-quiet" href="<?= View::e(Router::url('/agents?tenant_id=' . $selectedTenantId) . ($selectedAgentId > 0 ? '#agent-settings-' . $selectedAgentId : '')) ?>">Abrir assistente</a>
            </div>
        </div>
    </section>

    <?php if (!$migrationReady): ?>
        <section class="card agent-lab-warning"><strong>Falta preparar o banco para o laboratório.</strong><p>Execute <code>php bin/migrate.php up</code>. A migration necessária é <code>105_agent_testing_lab.sql</code>.</p></section>
    <?php endif; ?>

    <?php if ($selectedTenantId > 0 && $selectedAgentId > 0): ?>
    <section class="agent-lab-grid">
        <article class="card agent-lab-chat">
            <div class="section-heading"><div><span class="eyebrow">Simulador</span><h3>Conversa de teste<?php if ($selectedAgent): ?> · <?= View::e((string) ($selectedAgent['name'] ?? '')) ?><?php endif; ?></h3><p>O WhatsApp não é acionado. No modo IA real, a resposta usa a mesma chave e o mesmo prompt configurados no assistente.<?php if ($selectedAgent): ?> <strong>Assistente atual: #<?= (int) ($selectedAgent['id'] ?? 0) ?>.</strong><?php endif; ?></p></div><button class="btn btn-quiet" type="button" data-lab-reset>Limpar conversa</button></div>
            <div class="agent-lab-mode">
                <label><input type="radio" name="lab_mode" value="quick" checked><span><strong>Teste rápido</strong><small>Não consome IA. Valida coleta, restrições e liberações.</small></span></label>
                <label><input type="radio" name="lab_mode" value="real"><span><strong>IA real</strong><small>Chama o provedor configurado e mostra a resposta verdadeira. Consome uso da IA.</small></span></label>
            </div>
            <div class="agent-lab-chat-window" data-lab-chat>
                <div class="agent-lab-bubble system">Envie uma mensagem como se fosse o cliente. Exemplo: “Quero marcar psicólogo para minha filha”.</div>
            </div>
            <div class="agent-lab-composer">
                <textarea class="form-control" data-lab-message placeholder="Digite a mensagem do cliente..."></textarea>
                <button class="btn btn-primary" type="button" data-lab-send>Enviar teste <span class="agent-lab-loader" data-lab-loader>...</span></button>
            </div>
        </article>

        <aside class="card">
            <div class="section-heading"><div><span class="eyebrow">Por dentro da decisão</span><h3>O que o RS Connect entendeu</h3><p>Use esta área para conferir se conversa e agenda estão sendo tratadas separadamente.</p></div></div>
            <div class="agent-lab-diagnostics">
                <div class="agent-lab-diagnostic-card"><strong>Situação atual</strong><div data-lab-status><span class="agent-lab-pill">Aguardando mensagem</span></div></div>
                <div class="agent-lab-diagnostic-card"><strong>Regra aplicada</strong><div data-lab-decision>—</div></div>
                <div class="agent-lab-diagnostic-card"><strong>Permissões deste turno</strong><div class="agent-lab-data"><div><span>Conversa</span><b data-lab-conversation>—</b></div><div><span>Agenda</span><b data-lab-calendar>—</b></div><div><span>IA</span><b data-lab-ai>—</b></div></div></div>
                <div class="agent-lab-diagnostic-card"><strong>Informações coletadas</strong><div class="agent-lab-data" data-lab-collected><span class="agent-lab-meta">Nenhuma ainda.</span></div></div>
                <div class="agent-lab-diagnostic-card"><strong>Informações que ainda faltam</strong><div data-lab-missing>—</div></div>
                <div class="agent-lab-diagnostic-card" data-lab-findings-card hidden><strong>Atenção de segurança</strong><div data-lab-findings></div></div>
            </div>
        </aside>
    </section>
    <?php endif; ?>

    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Regressão automática</span><h3>Cenários que não podem voltar a falhar</h3><p>Os cenários executam uma conversa inteira e conferem decisões, continuidade e bloqueios de agenda.</p></div>
            <?php if ($migrationReady && $selectedTenantId > 0 && $selectedAgentId > 0): ?>
            <form method="post" action="<?= View::e(Router::url('/agent-tests/defaults')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= $selectedTenantId ?>"><input type="hidden" name="agent_id" value="<?= $selectedAgentId ?>"><button class="btn btn-primary" type="submit">Preparar testes de Psicologia</button></form>
            <?php endif; ?>
        </div>
        <?php if (!$migrationReady): ?>
            <div class="agent-lab-empty">Os cenários aparecerão depois da migration 105.</div>
        <?php elseif (!$scenarios): ?>
            <div class="agent-lab-empty">Ainda não há cenários para esta empresa. Use “Preparar testes de Psicologia” ou transforme uma conversa real em teste.</div>
        <?php else: ?>
            <div class="agent-lab-scenarios">
                <?php foreach ($scenarios as $scenario): $last = (string) ($scenario['last_status'] ?? ''); ?>
                <article class="agent-lab-scenario">
                    <div><h4><?= View::e((string) $scenario['name']) ?></h4><p><?= View::e((string) ($scenario['description'] ?? '')) ?></p><div class="agent-lab-meta">Último resultado: <strong><?= View::e($statusLabel($last)) ?></strong><?= !empty($scenario['last_run_at']) ? ' · ' . View::e((string) $scenario['last_run_at']) : '' ?></div></div>
                    <div class="agent-lab-scenario-actions">
                        <form method="post" action="<?= View::e(Router::url('/agent-tests/run')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= $selectedTenantId ?>"><input type="hidden" name="agent_id" value="<?= $selectedAgentId ?>"><input type="hidden" name="scenario_id" value="<?= (int) $scenario['id'] ?>"><input type="hidden" name="mode" value="quick"><button class="btn btn-quiet" type="submit">Teste rápido</button></form>
                        <form method="post" action="<?= View::e(Router::url('/agent-tests/run')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= $selectedTenantId ?>"><input type="hidden" name="agent_id" value="<?= $selectedAgentId ?>"><input type="hidden" name="scenario_id" value="<?= (int) $scenario['id'] ?>"><input type="hidden" name="mode" value="real"><button class="btn btn-primary" type="submit">Testar com IA real</button></form>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Replay</span><h3>Transformar conversa real em teste</h3><p>Informe o número interno da conversa. O RS Connect copia apenas as mensagens recebidas para um cenário de homologação; não envia nada ao cliente.</p></div></div>
        <?php if ($migrationReady && $selectedTenantId > 0): ?>
        <form class="agent-lab-inline-form" method="post" action="<?= View::e(Router::url('/agent-tests/import-conversation')) ?>">
            <?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= $selectedTenantId ?>"><input type="hidden" name="agent_id" value="<?= $selectedAgentId ?>">
            <label class="field"><span>Número da conversa</span><input type="number" name="conversation_id" min="1" required placeholder="Ex.: 1542" value="<?= $sourceConversationId > 0 ? $sourceConversationId : '' ?>"></label>
            <button class="btn btn-primary" type="submit">Criar cenário a partir da conversa</button>
        </form>
        <?php endif; ?>
    </section>

    <section class="card agent-lab-history">
        <div class="section-heading"><div><span class="eyebrow">Últimas execuções</span><h3>Histórico de homologação</h3></div></div>
        <?php if (!$runs): ?><div class="agent-lab-empty">Nenhum cenário executado ainda.</div><?php else: ?>
        <table><thead><tr><th>Quando</th><th>Cenário</th><th>Assistente</th><th>Modo</th><th>Resultado</th><th>Etapas</th></tr></thead><tbody>
        <?php foreach ($runs as $run): ?><tr><td><?= View::e((string) $run['created_at']) ?></td><td><?= View::e((string) ($run['scenario_name'] ?? 'Teste manual')) ?></td><td><?= View::e((string) ($run['agent_name'] ?? '—')) ?></td><td><?= ($run['mode'] ?? '') === 'real' ? 'IA real' : 'Rápido' ?></td><td><span class="agent-lab-pill <?= ($run['status'] ?? '') === 'passed' ? 'ok' : (($run['status'] ?? '') === 'failed' ? 'block' : 'warn') ?>"><?= View::e($statusLabel((string) ($run['status'] ?? ''))) ?></span></td><td><?= (int) ($run['passed_steps'] ?? 0) ?> ok · <?= (int) ($run['failed_steps'] ?? 0) ?> falha(s)</td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </section>
</div>

<script>
(() => {
    const root = document.querySelector('[data-agent-lab]');
    if (!root) return;
    const tenantId = <?= $selectedTenantId ?>;
    const agentId = <?= $selectedAgentId ?>;
    const tenantSelect = root.querySelector('[data-lab-tenant-select]');
    const agentSelect = root.querySelector('[data-lab-agent-select]');
    const pageUrl = root.dataset.pageUrl || '';
    const agentListUrl = root.dataset.agentListUrl || '';
    const serverTenantId = String(root.dataset.serverTenantId || tenantId || '');
    const serverAgentId = String(root.dataset.serverAgentId || agentId || '');

    // Navegadores podem restaurar o valor antigo de <select> após refresh/back-forward.
    // O servidor é a fonte de verdade: force os valores renderizados para impedir que
    // o nome de uma empresa apareça ao lado dos assistentes de outra empresa.
    const syncServerSelection = () => {
        if (tenantSelect && serverTenantId && tenantSelect.value !== serverTenantId) {
            tenantSelect.value = serverTenantId;
        }
        if (agentSelect && serverAgentId && agentSelect.value !== serverAgentId) {
            agentSelect.value = serverAgentId;
        }
    };
    syncServerSelection();
    window.addEventListener('pageshow', syncServerSelection);

    tenantSelect?.addEventListener('change', async () => {
        const nextTenantId = String(tenantSelect.value || '').trim();
        if (!nextTenantId || nextTenantId === serverTenantId) return;
        if (agentSelect) {
            agentSelect.disabled = true;
            agentSelect.innerHTML = '<option value="">Carregando assistentes...</option>';
        }
        try {
            const response = await fetch(agentListUrl + '?tenant_id=' + encodeURIComponent(nextTenantId), {
                method: 'GET',
                headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'Não foi possível carregar os assistentes.');
            const nextAgentId = Array.isArray(data.agents) && data.agents.length ? String(data.agents[0].id || '') : '';
            const params = new URLSearchParams({tenant_id: nextTenantId});
            if (nextAgentId) params.set('agent_id', nextAgentId);
            window.location.assign(pageUrl + '?' + params.toString());
        } catch (error) {
            window.location.assign(pageUrl + '?tenant_id=' + encodeURIComponent(nextTenantId));
        }
    });

    agentSelect?.addEventListener('change', () => {
        const nextAgentId = String(agentSelect.value || '').trim();
        if (!nextAgentId || nextAgentId === serverAgentId) return;
        const params = new URLSearchParams({tenant_id: serverTenantId, agent_id: nextAgentId});
        window.location.assign(pageUrl + '?' + params.toString());
    });
    const chat = root.querySelector('[data-lab-chat]');
    const input = root.querySelector('[data-lab-message]');
    const send = root.querySelector('[data-lab-send]');
    const reset = root.querySelector('[data-lab-reset]');
    const loader = root.querySelector('[data-lab-loader]');
    let history = [];
    let state = {};

    const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
    const bubble = (kind, text, meta = '') => {
        const el = document.createElement('div');
        el.className = 'agent-lab-bubble ' + kind;
        el.innerHTML = esc(text) + (meta ? '<div class="agent-lab-meta">' + esc(meta) + '</div>' : '');
        chat.appendChild(el); chat.scrollTop = chat.scrollHeight;
    };
    const boolText = (value, yes='Liberada', no='Bloqueada') => value ? yes : no;
    const renderDiagnostics = (result) => {
        const triage = result.triage || {};
        const decision = triage.decision || {};
        root.querySelector('[data-lab-status]').innerHTML = result.safe
            ? '<span class="agent-lab-pill ok">Processado com segurança</span>'
            : '<span class="agent-lab-pill block">Precisa de revisão</span>';
        root.querySelector('[data-lab-decision]').innerHTML = '<strong>' + esc(decision.policy_key || decision.code || 'Nenhuma regra especial') + '</strong><div class="agent-lab-meta">' + esc(decision.message || 'Fluxo normal') + '</div>';
        root.querySelector('[data-lab-conversation]').textContent = boolText(!!triage.conversation_continues, 'Continua', 'Interrompida');
        root.querySelector('[data-lab-calendar]').textContent = boolText(!!triage.calendar_allowed);
        root.querySelector('[data-lab-ai]').textContent = triage.should_use_ai ? (result.ai_called ? 'Usada neste turno' : 'Liberada') : 'Não usada';
        const collected = (triage.state && triage.state.collected) || {};
        const collectedBox = root.querySelector('[data-lab-collected]'); collectedBox.innerHTML = '';
        const entries = Object.entries(collected);
        if (!entries.length) collectedBox.innerHTML = '<span class="agent-lab-meta">Nenhuma ainda.</span>';
        entries.forEach(([key,value]) => { const row=document.createElement('div'); row.innerHTML='<span>'+esc(key)+'</span><b>'+esc(typeof value==='object'?JSON.stringify(value):value)+'</b>'; collectedBox.appendChild(row); });
        const missing = (triage.state && triage.state.missing) || triage.missing || [];
        root.querySelector('[data-lab-missing]').textContent = missing.length ? missing.join(', ') : 'Nenhuma obrigatória conhecida';
        const findings = result.safety_findings || [];
        const card = root.querySelector('[data-lab-findings-card]');
        card.hidden = findings.length === 0;
        root.querySelector('[data-lab-findings]').innerHTML = findings.map(f => '<div class="agent-lab-warning">'+esc(f.message || f.code)+'</div>').join('');
    };
    const run = async () => {
        const message = input.value.trim(); if (!message || !tenantId || !agentId) return;
        const mode = root.querySelector('input[name="lab_mode"]:checked')?.value || 'quick';
        bubble('user', message); input.value=''; send.disabled=true; loader.classList.add('is-visible');
        const body = new URLSearchParams({tenant_id:String(tenantId),agent_id:String(agentId),message,mode,history_json:JSON.stringify(history),state_json:JSON.stringify(state),_token:root.dataset.csrf});
        try {
            const response = await fetch(root.dataset.simulateUrl,{method:'POST',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body});
            const data = await response.json();
            if (!data.ok) throw new Error(data.message || 'Falha no teste.');
            const result = data.result || {};
            history = result.history || history; state = result.state || state;
            if (result.response) bubble('assistant', result.response, result.response_kind === 'ai' ? 'Resposta da IA real' : (result.response_kind === 'rule' ? 'Mensagem configurada pela regra' : 'Resultado do teste'));
            else bubble('system', result.error || 'O turno não gerou resposta.');
            renderDiagnostics(result);
        } catch (error) { bubble('system','Erro no laboratório: ' + (error?.message || error)); }
        finally { send.disabled=false; loader.classList.remove('is-visible'); input.focus(); }
    };
    send?.addEventListener('click',run);
    input?.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();run();}});
    reset?.addEventListener('click',()=>{history=[];state={};chat.innerHTML='<div class="agent-lab-bubble system">Conversa de teste reiniciada.</div>';root.querySelector('[data-lab-status]').innerHTML='<span class="agent-lab-pill">Aguardando mensagem</span>';root.querySelector('[data-lab-decision]').textContent='—';root.querySelector('[data-lab-conversation]').textContent='—';root.querySelector('[data-lab-calendar]').textContent='—';root.querySelector('[data-lab-ai]').textContent='—';root.querySelector('[data-lab-collected]').innerHTML='<span class="agent-lab-meta">Nenhuma ainda.</span>';root.querySelector('[data-lab-missing]').textContent='—';root.querySelector('[data-lab-findings-card]').hidden=true;});
})();
</script>
