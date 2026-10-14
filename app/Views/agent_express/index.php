<?php
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS;
?>
<div class="rex" id="agentExpress" data-csrf="<?= View::e(Csrf::token()) ?>"
     data-save-url="<?= View::e(Router::url('/api/agents/express')) ?>"
     data-api-root="<?= View::e(Router::url('/api/v1/agents')) ?>"
     data-can-view="<?= !empty($setupCanView) ? '1' : '0' ?>"
     data-can-fix="<?= !empty($setupCanManage) ? '1' : '0' ?>"
     data-onboarding-url="<?= View::e(Router::url('/onboarding')) ?>"
     data-agents-url="<?= View::e(Router::url('/agents')) ?>"
     data-instances-url="<?= View::e(Router::url('/instances')) ?>">
    <script type="application/json" id="rexData"><?= json_encode(['payload'=>$setupPayload, 'agents'=>$setupAgents, 'has_saved'=>$setupHasSaved], $jsonFlags | JSON_THROW_ON_ERROR) ?></script>
    <div class="rex-heading">
        <div><div class="rex-eyebrow">RS CONNECT <span aria-hidden="true">/</span> CONFIGURAÇÃO RÁPIDA</div>
        <h2>Vamos preparar seu atendimento</h2>
        <p>Você conta como sua empresa funciona. O restante fica por nossa conta.</p></div>
        <div class="rex-head-actions"><?php if (!empty($setupHasSaved)): ?><button class="rex-button rex-button-subtle" type="button" id="rexOpenDashboard">Ver prontidão</button><?php endif; ?><a class="rex-back-link" href="<?= View::e(Router::url('/onboarding')) ?>">← Voltar à implantação</a></div>
    </div>
    <div class="rex-stepbar" role="navigation" aria-label="Etapas da configuração">
        <button class="rex-step is-active" type="button" data-step-link="0" aria-current="step"><span>1</span> Sua empresa</button>
        <button class="rex-step" type="button" data-step-link="1"><span>2</span> Atendimento</button>
        <button class="rex-step" type="button" data-step-link="2"><span>3</span> Horários e respostas</button>
        <button class="rex-step" type="button" data-step-link="3"><span>4</span> Conferência</button>
    </div>
    <div id="rexNotice" class="rex-notice" role="alert" hidden></div>
    <form id="rexWizard" novalidate>
        <section class="rex-panel" data-step-panel="0" aria-labelledby="rexStepTitle0">
            <div class="rex-panel-head"><span class="rex-step-number">01 / 04</span><h3 id="rexStepTitle0">Que empresa estamos representando?</h3><p>Defina um nome e escolha o tipo de atendimento que mais combina com o seu negócio.</p></div>
            <div class="rex-fields"><label class="rex-label" for="rexName">Como se chama seu assistente?</label><input class="rex-input rex-input-large" id="rexName" data-bind="business_info.agent_name" maxlength="120" required placeholder="Ex.: Assistente Vida Plena" autocomplete="off"></div>
            <fieldset class="rex-fieldset"><legend class="rex-label">Qual é seu tipo de empresa?</legend>
                <div class="rex-segment-grid" id="rexSegments">
                    <label class="rex-segment"><input type="radio" name="rexSegment" value="psychology_clinic"><span class="rex-segment-icon" aria-hidden="true">♡</span><strong>Psicologia</strong><small>Consultas e acolhimento</small></label>
                    <label class="rex-segment"><input type="radio" name="rexSegment" value="health_clinic"><span class="rex-segment-icon" aria-hidden="true">✚</span><strong>Clínica de saúde</strong><small>Especialidades e pacientes</small></label>
                    <label class="rex-segment"><input type="radio" name="rexSegment" value="real_estate"><span class="rex-segment-icon" aria-hidden="true">⌂</span><strong>Imobiliária</strong><small>Imóveis e visitas</small></label>
                    <label class="rex-segment"><input type="radio" name="rexSegment" value="beauty_salon"><span class="rex-segment-icon" aria-hidden="true">✦</span><strong>Beleza e estética</strong><small>Serviços e horários</small></label>
                    <label class="rex-segment"><input type="radio" name="rexSegment" value="general_services"><span class="rex-segment-icon" aria-hidden="true">◇</span><strong>Outros serviços</strong><small>Atendimento personalizado</small></label>
                </div>
            </fieldset>
            <fieldset class="rex-fieldset"><legend class="rex-label">Como prefere que ele converse?</legend>
                <div class="rex-pills" id="rexTones">
                    <label><input type="radio" name="rexTone" value="empathetic_and_welcoming"><span>Acolhedor</span></label>
                    <label><input type="radio" name="rexTone" value="objective"><span>Direto e profissional</span></label>
                    <label><input type="radio" name="rexTone" value="formal"><span>Formal</span></label>
                    <label><input type="radio" name="rexTone" value="commercial"><span>Consultivo</span></label>
                </div>
            </fieldset>
        </section>
        <section class="rex-panel" data-step-panel="1" aria-labelledby="rexStepTitle1" hidden>
            <div class="rex-panel-head"><span class="rex-step-number">02 / 04</span><h3 id="rexStepTitle1">O que precisa acontecer durante o atendimento?</h3><p>Selecione somente as informações necessárias e como apresentar seus valores.</p></div>
            <div class="rex-grid-2">
                <label class="rex-switch-row"><span><strong>Pedir o nome da pessoa</strong><small>Antes de encaminhar para um agendamento</small></span><input type="checkbox" data-bind="triage_config.collect_name" role="switch"><i aria-hidden="true"></i></label>
                <label class="rex-switch-row"><span><strong>Pedir telefone de contato</strong><small>Para facilitar o retorno da equipe</small></span><input type="checkbox" data-bind="triage_config.collect_phone" role="switch"><i aria-hidden="true"></i></label>
            </div>
            <div class="rex-subsection"><div class="rex-subsection-title"><div><h4>Outras perguntas importantes</h4><p>O assistente pergunta uma por vez, antes de verificar a agenda.</p></div><button class="rex-link-button" id="rexAddQuestion" type="button">+ Adicionar pergunta</button></div><div class="rex-dynamic-list" id="rexQuestions"></div><p class="rex-help">Até 12 perguntas. Evite pedir dados sensíveis que não sejam necessários.</p></div>
            <div class="rex-subsection rex-subsection-lined"><h4>Valores e pagamento</h4><div class="rex-grid-2">
                <label class="rex-form-field">Como informar os valores?<select class="rex-input" data-bind="commercial_info.pricing_strategy"><option value="on_request">Não informar — falar com a equipe</option><option value="fixed_price">Preço fixo</option><option value="variable_price">A partir de / valor variável</option></select></label>
                <label class="rex-form-field" id="rexPriceWrap">Qual é o valor?<input class="rex-input" data-bind="commercial_info.price_value" maxlength="40" placeholder="R$ 250,00" inputmode="decimal"><small>Informe o valor exato, como R$ 250,00.</small></label>
            </div><p class="rex-help" id="rexVariableHint" hidden>Valores variáveis serão tratados sob consulta. Este formato não cadastra um preço mínimo.</p>
            <fieldset class="rex-fieldset rex-payment"><legend class="rex-label">Como o cliente pode pagar?</legend><div class="rex-checks">
                <label><input type="checkbox" value="pix" name="payment"> Pix</label>
                <label><input type="checkbox" value="credit_card" name="payment"> Cartão de crédito</label>
                <label><input type="checkbox" value="debit_card" name="payment"> Cartão de débito</label>
                <label><input type="checkbox" value="bank_transfer" name="payment"> Transferência</label>
                <label><input type="checkbox" value="cash" name="payment"> Dinheiro</label>
            </div></fieldset></div>
            <div class="rex-subsection rex-subsection-lined"><h4>Decisões da equipe</h4><div class="rex-grid-2">
                <label class="rex-switch-row"><span><strong>Permitir reagendamento</strong><small>Somente com regras e disponibilidade válidas</small></span><input type="checkbox" role="switch" data-bind="capabilities.allow_rescheduling"><i aria-hidden="true"></i></label>
                <label class="rex-switch-row"><span><strong>Chamar a equipe quando necessário</strong><small>Para problemas que não possam ser resolvidos</small></span><input type="checkbox" role="switch" data-bind="capabilities.escalate_to_human_on_error"><i aria-hidden="true"></i></label>
            </div><p class="rex-help">Quantidade de pessoas por horário e aprovação das reservas são ajustadas na <a href="<?= View::e(Router::url('/calendar')) ?>">Agenda</a>.</p></div>
        </section>
        <section class="rex-panel" data-step-panel="2" aria-labelledby="rexStepTitle2" hidden>
            <div class="rex-panel-head"><span class="rex-step-number">03 / 04</span><h3 id="rexStepTitle2">Quando atender e o que responder?</h3><p>Defina seus horários, as dúvidas mais comuns e o que fazer quando a empresa estiver fechada.</p></div>
            <div class="rex-grid-2 rex-section-spacing">
                <label class="rex-form-field">Fuso horário<select class="rex-input" data-bind="routing_and_schedule.timezone"><option value="America/Sao_Paulo">Brasília / São Paulo</option><option value="America/Manaus">Manaus</option><option value="America/Cuiaba">Cuiabá</option><option value="America/Rio_Branco">Rio Branco</option><option value="America/Belem">Belém</option><option value="America/Recife">Recife</option><option value="America/Noronha">Fernando de Noronha</option><option value="UTC">UTC</option></select></label>
                <label class="rex-form-field">Quando chegar mensagem fora do horário<select class="rex-input" data-bind="routing_and_schedule.out_of_hours_action"><option value="capture_lead_and_promise_contact">Registrar e avisar que a equipe retornará</option><option value="inform_business_hours">Informar o horário de atendimento</option><option value="handoff_to_human">Encaminhar para a equipe</option></select></label>
            </div>
            <div class="rex-subsection"><div class="rex-subsection-title"><div><h4>Dias e horários de atendimento</h4><p>Marque os dias ativos e informe a abertura e o encerramento.</p></div></div><div class="rex-hours" id="rexHours"></div><p class="rex-help">Se houver uma pausa no expediente, clique em + Horário para adicionar outro período no mesmo dia.</p></div>
            <div class="rex-subsection rex-subsection-lined"><div class="rex-subsection-title"><div><h4>Perguntas frequentes</h4><p>Cadastre respostas que a equipe já considera corretas.</p></div><button class="rex-link-button" type="button" id="rexAddFaq">+ Adicionar resposta</button></div><div class="rex-dynamic-list" id="rexFaq"></div><p class="rex-help">Até 40 perguntas e respostas.</p></div>
            <div class="rex-subsection rex-subsection-lined"><h4>Atendimentos por vídeo</h4>
                <label class="rex-form-field" for="rexMeetingLink">Link padrão da reunião (opcional)<input id="rexMeetingLink" class="rex-input" type="url" data-bind="routing_and_schedule.default_meeting_link" maxlength="500" placeholder="https://meet.google.com/..." autocomplete="url"><small>Insira um link HTTPS do Meet, Zoom ou outra plataforma.</small></label>
                <label class="rex-switch-row rex-meeting-toggle"><span><strong>Enviar link na confirmação</strong><small>Somente após o agendamento estar confirmado</small></span><input type="checkbox" role="switch" data-bind="capabilities.send_meeting_link_on_confirmation"><i aria-hidden="true"></i></label>
            </div>
        </section>
        <section class="rex-panel" data-step-panel="3" aria-labelledby="rexStepTitle3" hidden>
            <div id="rexReview" data-review>
                <div class="rex-panel-head"><span class="rex-step-number">04 / 04</span><h3 id="rexStepTitle3">Confira antes de salvar</h3><p>Verifique as escolhas da sua empresa. Salvar não ativa automaticamente o atendimento.</p></div>
                <div class="rex-review-grid" id="rexSummary"></div>
                <div class="rex-guardnote">✓ A configuração será preparada, mas a liberação do atendimento depende da verificação final.</div>
            </div>
            <div id="rexReadiness" hidden>
                <div class="rex-panel-head"><span class="rex-step-number">CONFERÊNCIA</span><h3>Seu atendimento está pronto?</h3><p>Veja o que já funciona e o que falta antes de receber clientes.</p></div>
                <div class="rex-agent-picker" id="rexAgentPicker"><label class="rex-form-field">Qual assistente verificar?<select id="rexAgentSelect" class="rex-input"></select></label></div>
                <div id="rexDashboard" aria-live="polite"></div>
            </div>
        </section>
        <div class="rex-actions" id="rexActions"><button type="button" class="rex-button rex-button-subtle" id="rexBack">Voltar</button><span class="rex-step-hint" id="rexStepHint">Etapa 1 de 4</span><button type="button" class="rex-button rex-button-primary" id="rexNext">Continuar <span aria-hidden="true">→</span></button></div>
    </form>
</div>
