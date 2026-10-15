<?php
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$agents = is_array($setupAgents ?? null) ? $setupAgents : [];
$primaryAgent = null;
foreach ($agents as $candidate) {
    if (!empty($candidate['is_default'])) { $primaryAgent = $candidate; break; }
}
$jsonOptions = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div class="business-agent" id="businessAgent"
     data-save-url="<?= View::e(Router::url('/api/v1/agents/business-save')) ?>"
     data-read-url="<?= View::e(Router::url('/api/v1/agents/business-config')) ?>"
     data-api-url="<?= View::e(Router::url('/api/v1/agents')) ?>"
     data-csrf="<?= View::e(Csrf::token()) ?>"
     data-can-manage="<?= !empty($setupCanManage) ? '1' : '0' ?>"
     data-can-view="<?= !empty($setupCanView) ? '1' : '0' ?>">
  <script type="application/json" id="businessInitialData"><?= json_encode([
      'payload' => $setupPayload,
      'primary_agent' => $primaryAgent,
      'agents_count' => count($agents),
      'has_saved' => !empty($setupHasSaved),
      'protected_questions' => $setupProtectedQuestions ?? [],
  ], $jsonOptions) ?></script>

  <header class="ba-topbar">
    <div class="ba-title-area">
      <div class="ba-kicker">SEU ATENDIMENTO <span>·</span> PAINEL DE CONTROLE</div>
      <h1>Configure como sua empresa atende</h1>
      <p>Escolha as regras do seu negócio, confira o resultado e teste antes de colocar em prática.</p>
    </div>
    <div class="ba-top-actions">
      <span id="baDirtyLabel" class="ba-saving-status" aria-live="polite">Verificando dados salvos</span>
      <button id="baSave" type="button" class="ba-button ba-primary" <?= empty($setupCanManage) ? 'disabled' : '' ?>>Salvar configurações</button>
    </div>
  </header>

  <?php if (count($agents) > 1): ?>
    <div class="ba-banner ba-banner-info" role="note">Estas regras são compartilhadas pela empresa e atualizam o assistente principal. Os demais atendentes não serão alterados individualmente.</div>
  <?php endif; ?>
  <?php if (!$primaryAgent): ?>
    <div class="ba-banner ba-banner-info" role="note">Nenhum assistente principal foi definido. É possível preparar as regras, mas a verificação e o teste só estarão disponíveis depois de definir um assistente principal. <a href="<?= View::e(Router::url('/instances')) ?>">Configurar canais</a></div>
  <?php endif; ?>
  <div id="baNotice" class="ba-notice" role="alert" hidden></div>

  <div class="ba-layout">
    <main class="ba-config">
      <nav class="ba-tabs" aria-label="Áreas do atendimento" role="tablist">
        <button type="button" class="ba-tab is-active" data-tab="identity" id="baTabIdentity" role="tab" aria-controls="baPaneIdentity" aria-selected="true"><span aria-hidden="true">◉</span> Identidade</button>
        <button type="button" class="ba-tab" data-tab="rules" id="baTabRules" role="tab" aria-controls="baPaneRules" aria-selected="false"><span aria-hidden="true">▦</span> Regras de atendimento</button>
        <button type="button" class="ba-tab" data-tab="faq" id="baTabFaq" role="tab" aria-controls="baPaneFaq" aria-selected="false"><span aria-hidden="true">▤</span> Dúvidas frequentes</button>
        <button type="button" class="ba-tab" data-tab="channels" id="baTabChannels" role="tab" aria-controls="baPaneChannels" aria-selected="false"><span aria-hidden="true">◎</span> Canais</button>
      </nav>

      <section class="ba-tabpane" data-panel="identity" role="tabpanel" id="baPaneIdentity" aria-labelledby="baTabIdentity">
        <div class="ba-card">
          <div class="ba-card-head"><span class="ba-section-icon" aria-hidden="true">✦</span><div><h2>A personalidade do seu atendimento</h2><p>Assim sua empresa será apresentada a quem entrar em contato.</p></div></div>
          <div class="ba-avatar-row"><div class="ba-avatar" id="baAvatar" aria-hidden="true">A</div><div><strong id="baAgentPreviewName">Seu assistente</strong><small>Identidade visual do atendimento</small></div></div>
          <label class="ba-field">Nome do assistente<input type="text" class="ba-input" data-bind="business_info.agent_name" maxlength="120" autocomplete="off" placeholder="Ex.: Assistente Vida Plena"></label>
          <label class="ba-field">Área de atuação<select class="ba-input" data-bind="business_info.segment" disabled>
            <option value="">Selecione o tipo de empresa</option><option value="psychology_clinic">Psicologia</option><option value="health_clinic">Clínica de saúde</option>
            <option value="real_estate">Imobiliária</option><option value="beauty_salon">Beleza e estética</option>
            <option value="general_services">Serviços em geral</option></select><small><?= !empty($setupHasSaved) ? 'A área foi definida na implantação. Para trocar de segmento, utilize a configuração inicial.' : 'Escolha a área da sua empresa antes de salvar pela primeira vez.' ?></small></label>
          <fieldset class="ba-choice-group"><legend>Como deve ser o jeito de conversar?</legend>
            <div class="ba-tone-grid">
              <label class="ba-tone"><input type="radio" name="baTone" value="empathetic_and_welcoming"><span><strong>Acolhedor</strong><small>Gentil, atento e humano</small></span></label>
              <label class="ba-tone"><input type="radio" name="baTone" value="objective"><span><strong>Profissional</strong><small>Claro e direto</small></span></label>
              <label class="ba-tone"><input type="radio" name="baTone" value="formal"><span><strong>Formal</strong><small>Discreto e respeitoso</small></span></label>
              <label class="ba-tone"><input type="radio" name="baTone" value="commercial"><span><strong>Consultivo</strong><small>Prestativo e proativo</small></span></label>
            </div>
          </fieldset>
        </div>
      </section>

      <section class="ba-tabpane" data-panel="rules" role="tabpanel" id="baPaneRules" aria-labelledby="baTabRules" hidden>
        <div class="ba-card">
          <div class="ba-card-head"><span class="ba-section-icon" aria-hidden="true">◷</span><div><h2>Horários de atendimento</h2><p>Marque os dias úteis e indique os períodos em que sua empresa atende.</p></div></div>
          <label class="ba-field">Horário local<select class="ba-input" data-bind="routing_and_schedule.timezone">
            <option value="America/Sao_Paulo">Brasília / São Paulo</option><option value="America/Manaus">Manaus</option><option value="America/Cuiaba">Cuiabá</option><option value="America/Rio_Branco">Rio Branco</option><option value="America/Belem">Belém</option><option value="America/Recife">Recife</option><option value="America/Noronha">Fernando de Noronha</option><option value="UTC">UTC</option>
          </select></label>
          <div id="baHours" class="ba-hours"></div>
          <label class="ba-field">Quando alguém escrever fora do expediente<select class="ba-input" data-bind="routing_and_schedule.out_of_hours_action">
            <option value="capture_lead_and_promise_contact">Registrar contato para retornar depois</option>
            <option value="inform_business_hours">Informar os horários de atendimento</option>
            <option value="handoff_to_human">Encaminhar para a equipe</option>
          </select><small>O horário real será verificado pelo sistema; esta opção define o que dizer quando a empresa estiver fechada.</small></label>
        </div>
        <div class="ba-card">
          <div class="ba-card-head"><span class="ba-section-icon" aria-hidden="true">☷</span><div><h2>Informações antes do atendimento</h2><p>Escolha o que perguntar antes de seguir para um agendamento.</p></div></div>
          <div class="ba-switch-stack">
            <label class="ba-switch-row"><span><strong>Solicitar nome</strong><small>Saber como chamar a pessoa</small></span><input type="checkbox" role="switch" data-bind="triage_config.collect_name"><i></i></label>
            <label class="ba-switch-row"><span><strong>Solicitar telefone</strong><small>Permitir retorno, quando necessário</small></span><input type="checkbox" role="switch" data-bind="triage_config.collect_phone"><i></i></label>
            <label class="ba-switch-row"><span><strong>Permitir reagendamento</strong><small>Respeitando a disponibilidade real</small></span><input type="checkbox" role="switch" data-bind="capabilities.allow_rescheduling"><i></i></label>
            <label class="ba-switch-row"><span><strong>Encaminhar à equipe em caso de problema</strong><small>Evita decisões sem confirmação</small></span><input type="checkbox" role="switch" data-bind="capabilities.escalate_to_human_on_error"><i></i></label>
          </div>
          <div class="ba-inline-title"><div><h3>Perguntas personalizadas</h3><p>O assistente pergunta uma por vez.</p></div><button type="button" class="ba-text-button" id="baAddQuestion">+ Adicionar</button></div>
          <div id="baQuestions" class="ba-dynamic"></div>
          <div id="baOtherQuestions" class="ba-soft-note" hidden>
            <strong>Outras informações já definidas na Ativação Assistida</strong>
            <p>Estas perguntas continuam válidas. Para alterá-las, use Ativação Assistida; salvar aqui não vai apagá-las.</p>
            <ul id="baOtherQuestionsList"></ul>
          </div>
        </div>
        <div class="ba-card">
          <div class="ba-card-head"><span class="ba-section-icon" aria-hidden="true">R$</span><div><h2>Valores e consultas online</h2><p>Defina como apresentar preços e como compartilhar o acesso à consulta.</p></div></div>
          <div class="ba-two-cols">
            <label class="ba-field">Como informar o preço<select class="ba-input" data-bind="commercial_info.pricing_strategy"><option value="on_request">Informar sob consulta</option><option value="fixed_price">Preço fixo</option><option value="variable_price">Preço variável — consultar equipe</option></select></label>
            <label class="ba-field" id="baPriceRow">Preço definido<input class="ba-input" data-bind="commercial_info.price_value" maxlength="40" placeholder="R$ 250,00" inputmode="decimal"></label>
          </div>
          <fieldset class="ba-choice-group"><legend>Formas de pagamento</legend><div class="ba-check-grid">
            <label><input type="checkbox" name="baPayment" value="pix"> Pix</label><label><input type="checkbox" name="baPayment" value="credit_card"> Cartão de crédito</label><label><input type="checkbox" name="baPayment" value="debit_card"> Cartão de débito</label><label><input type="checkbox" name="baPayment" value="bank_transfer"> Transferência</label><label><input type="checkbox" name="baPayment" value="cash"> Dinheiro</label>
          </div></fieldset>
          <label class="ba-field">Link padrão de reunião<input type="url" class="ba-input" data-bind="routing_and_schedule.default_meeting_link" maxlength="500" placeholder="https://meet.google.com/..."><small>Utilize um link HTTPS do Meet ou Zoom.</small></label>
          <label class="ba-switch-row"><span><strong>Enviar o link quando o agendamento for confirmado</strong><small>Somente após a confirmação válida</small></span><input type="checkbox" role="switch" data-bind="capabilities.send_meeting_link_on_confirmation"><i></i></label>
          <div class="ba-soft-note">Capacidade por horário e aprovação de reservas continuam configuradas na <a href="<?= View::e(Router::url('/calendar')) ?>">Agenda</a>, sem serem alteradas aqui.</div>
        </div>
      </section>

      <section class="ba-tabpane" data-panel="faq" role="tabpanel" id="baPaneFaq" aria-labelledby="baTabFaq" hidden>
        <div class="ba-card">
          <div class="ba-card-head"><span class="ba-section-icon" aria-hidden="true">?</span><div><h2>Respostas para perguntas comuns</h2><p>Conte como sua equipe responderia. O assistente usará essas informações quando forem relevantes.</p></div></div>
          <div id="baFaq" class="ba-dynamic"></div>
          <button type="button" id="baAddFaq" class="ba-button ba-outline">+ Adicionar pergunta e resposta</button>
          <p class="ba-muted">Evite incluir dados sensíveis de clientes. É possível cadastrar até 40 perguntas.</p>
        </div>
      </section>

      <section class="ba-tabpane" data-panel="channels" role="tabpanel" id="baPaneChannels" aria-labelledby="baTabChannels" hidden>
        <div class="ba-card">
          <div class="ba-card-head"><span class="ba-section-icon" aria-hidden="true">⇄</span><div><h2>Por onde seus clientes entram em contato</h2><p>Confira os canais e a disponibilidade operacional do atendimento.</p></div></div>
          <div id="baChannelStatus" class="ba-channel-status" aria-live="polite">Consultando a conexão…</div>
          <a href="<?= View::e(Router::url('/instances')) ?>" class="ba-button ba-outline">Gerenciar WhatsApp</a>
          <div class="ba-soft-note">Outros canais só aparecem como conectados quando houver uma integração operacional configurada. O Instagram não é ativado nesta tela.</div>
        </div>
      </section>
    </main>

    <aside class="ba-sidebar" aria-label="Conferência e testes">
      <section class="ba-card ba-readiness">
        <div class="ba-aside-title"><div><h2>Pronto para atender?</h2><p>Verificação das regras salvas.</p></div><button type="button" id="baRefresh" class="ba-icon-action" aria-label="Atualizar verificação" title="Atualizar">↻</button></div>
        <div class="ba-scorebox"><div id="baDonut" class="ba-donut" role="img" aria-label="Pontuação indisponível"><div class="ba-donut-core"><strong id="baScore">--</strong><small>de 100</small></div></div>
        <div><strong id="baReadyTitle">Verificando…</strong><p id="baReadyDetail">Aguarde a consulta.</p></div></div>
        <div id="baReadinessList" class="ba-checklist" aria-live="polite"></div>
        <div class="ba-score-foot">O resultado reflete a configuração já salva, não as alterações em edição.</div>
      </section>
      <section class="ba-card ba-simulator">
        <div class="ba-aside-title"><div><h2>Teste o atendimento</h2><p>Veja como o assistente responderia.</p></div><button type="button" class="ba-icon-action" id="baChatReset" aria-label="Reiniciar conversa" title="Reiniciar conversa">↺</button></div>
        <div class="ba-sim-banner"><span class="ba-online-dot"></span> Modo de simulação · Não envia mensagens reais</div>
        <div class="ba-chat" id="baChatLog" role="log" aria-label="Conversa de teste" aria-live="polite"><div class="ba-chat-empty">Experimente escrever “Olá! Quero agendar um horário.”</div></div>
        <form id="baChatForm" class="ba-chat-form"><label class="ba-sr-only" for="baChatInput">Mensagem de teste</label><input class="ba-input" id="baChatInput" maxlength="1000" placeholder="Escreva uma mensagem…" autocomplete="off"><button type="submit" id="baChatSend" class="ba-send-button" aria-label="Enviar mensagem">➤</button></form>
        <p id="baChatHint" class="ba-chat-hint">Salve suas alterações antes de testar. A simulação usa as regras já salvas e pode consumir créditos de processamento.</p>
      </section>
    </aside>
  </div>
</div>
