/* RS Connect — Painel de negócios: editor determinístico, prontidão e teste isolado. */
(() => {
  'use strict';
  const root = document.getElementById('businessAgent');
  if (!root) return;
  const $ = (sel, scope = root) => scope.querySelector(sel);
  const $$ = (sel, scope = root) => Array.from(scope.querySelectorAll(sel));
  const make = (tag, cls, txt) => {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (txt !== undefined) e.textContent = String(txt);
    return e;
  };
  const days = [['monday','Segunda'],['tuesday','Terça'],['wednesday','Quarta'],['thursday','Quinta'],['friday','Sexta'],['saturday','Sábado'],['sunday','Domingo']];
  const pricingRegex = /^R\$\s*(?:\d{1,3}(?:\.\d{3})*|\d+),\d{2}$/u;
  const validClock = /^([01]\d|2[0-3]):[0-5]\d$/;
  const get = (obj, path) => path.split('.').reduce((v, part) => v?.[part], obj);
  const set = (obj, path, value) => {
    const parts = path.split('.'); let ref = obj;
    parts.slice(0, -1).forEach(key => { ref = ref[key]; });
    ref[parts.at(-1)] = value;
  };
  const clone = x => JSON.parse(JSON.stringify(x));
  const encodeId = id => encodeURIComponent(id);
  let initial;
  try { initial = JSON.parse($('#businessInitialData').textContent); }
  catch { root.textContent = 'Não foi possível abrir suas configurações. Atualize a página.'; return; }

  const data = initial.payload;
  // Itens de outro editor são somente leitura; não entram no JSON da API.
  const protectedQuestions = Array.isArray(initial.protected_questions) ? initial.protected_questions : [];
  const otherBox = $('#baOtherQuestions');
  const otherList = $('#baOtherQuestionsList');
  if (otherBox && otherList && protectedQuestions.length) {
    otherBox.hidden = false;
    for (const item of protectedQuestions) {
      const li = make('li', '', String(item.question || 'Pergunta cadastrada'));
      otherList.append(li);
    }
  }
  if (!Array.isArray(data.routing_and_schedule?.business_hours)) {
    // Protege clientes com HTML antigo em cache; nunca inventa horários.
    const hours = data.routing_and_schedule?.business_hours || {};
    const weekdays = {mon:'monday',tue:'tuesday',wed:'wednesday',thu:'thursday',fri:'friday',sat:'saturday',sun:'sunday'};
    data.routing_and_schedule.business_hours = Object.entries(weekdays).flatMap(([short, day]) =>
      (Array.isArray(hours[short]) ? hours[short] : []).filter(p => Array.isArray(p) && p.length === 2)
        .map(p => ({day,start:p[0],end:p[1]})));
  }
  // Os registros anteriores usam conversation_behavior. A leitura da API
  // já os converte para este formato, sem habilitar novas modalidades.
  if (!data.service_modalities || typeof data.service_modalities !== 'object') {
    data.service_modalities = {mode:'not_applicable',online_channel:'',online_message:'',presencial_location:'',presencial_days:[],presencial_message:''};
  }
  data.service_modalities.presencial_days ||= [];
  const agent = initial.primary_agent || null;
  const canManage = root.dataset.canManage === '1';
  const canView = root.dataset.canView === '1';
  const selectedAgent = agent?.id || null;
  let hasSaved = Boolean(initial.has_saved);
  // Sem configuração Expressa anterior, nunca assumir segmento 'Serviços em geral' silenciosamente.
  if (!hasSaved) data.business_info.segment = '';
  let dirty = false;
  let saving = false;
  let chatBusy = false;
  let history = [];
  let state = {};
  let readiness = null;
  let lastSaved = clone(data);
  let editGeneration = 0;

  function endpoint(action) { return `${root.dataset.apiUrl}/${encodeId(selectedAgent)}/${action}`; }
  async function request(url, method = 'GET', payload = undefined) {
    const options = { method, cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (payload !== undefined) {
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-TOKEN'] = root.dataset.csrf;
      options.body = JSON.stringify(payload);
    }
    const response = await fetch(url, options);
    if (!(response.headers.get('content-type') || '').includes('application/json')) {
      throw new Error(response.status === 419 || response.status === 403
        ? 'Sua sessão expirou ou você não tem permissão. Atualize a página.'
        : 'Não foi possível conversar com o sistema. Atualize a página e tente novamente.');
    }
    const answer = await response.json();
    if (!response.ok || answer.ok === false) {
      const e = new Error(answer.message || 'Não foi possível concluir esta operação.');
      e.errors = answer.errors || null;
      throw e;
    }
    return answer;
  }
  function notice(text, type = 'error') {
    const n = $('#baNotice'); n.dataset.type = type; n.textContent = text; n.hidden = false;
  }
  function clearNotice() { $('#baNotice').hidden = true; }
  function markDirty() {
    editGeneration += 1;
    dirty = true;
    $('#baDirtyLabel').textContent = 'Mudanças não salvas';
    $('#baDirtyLabel').classList.add('is-dirty');
    updateChatAvailability();
    if (readiness) {
      $('#baReadyDetail').textContent = 'Existem alterações não salvas. O resultado abaixo reflete a versão anterior.';
      $$('.ba-fix').forEach(b => { b.disabled = true; });
    }
  }
  function markSaved() {
    dirty = false;
    lastSaved = clone(data);
    $('#baDirtyLabel').textContent = 'Tudo salvo';
    $('#baDirtyLabel').classList.remove('is-dirty');
    updateChatAvailability();
  }
  function updateChatAvailability() {
    const disabled = !canManage || !selectedAgent || !hasSaved || dirty || chatBusy || saving;
    $('#baChatInput').disabled = disabled;
    $('#baChatSend').disabled = disabled;
    const hint = $('#baChatHint');
    hint.textContent = dirty ? 'Salve as mudanças para testar as novas regras.'
      : !hasSaved ? 'Salve a configuração antes de testar.'
      : !selectedAgent ? 'Defina um assistente principal para habilitar os testes.'
      : !canManage ? 'Você não tem permissão para testar este atendimento.'
      : 'Simulação isolada: não envia mensagens reais nem altera agendamentos. Usa regras salvas e pode consumir créditos.';
  }
  function activate(tab) {
    $$('[data-tab]').forEach(btn => {
      const on = btn.dataset.tab === tab;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
      btn.tabIndex = on ? 0 : -1;
    });
    $$('[data-panel]').forEach(p => { p.hidden = p.dataset.panel !== tab; });
  }
  $$('[data-tab]').forEach(btn => btn.addEventListener('click', () => activate(btn.dataset.tab)));
  function updateName() {
    const name = (data.business_info.agent_name || '').trim();
    $('#baAgentPreviewName').textContent = name || 'Seu assistente';
    $('#baAvatar').textContent = (name.charAt(0) || 'A').toLocaleUpperCase('pt-BR');
  }
  function updatePrice() {
    const fixed = data.commercial_info.pricing_strategy === 'fixed_price';
    $('#baPriceRow').hidden = !fixed;
    const field = $('[data-bind="commercial_info.price_value"]');
    field.disabled = !canManage || !fixed;
    if (!fixed) field.value = '';
  }
  function populate() {
    $$('[data-bind]').forEach(node => {
      const value = get(data, node.dataset.bind);
      node.type === 'checkbox' ? node.checked = value === true : node.value = value ?? '';
      node.disabled = !canManage || (node.dataset.bind === 'business_info.segment' && hasSaved);
    });
    $$('input[name="baTone"]').forEach(input => {
      input.checked = input.value === data.business_info.tone_of_voice;
      input.disabled = !canManage;
    });
    $$('input[name="baPayment"]').forEach(input => {
      input.checked = data.commercial_info.payment_methods.includes(input.value);
      input.disabled = !canManage;
    });
    updateName(); updatePrice(); renderHours(); renderQuestions(); renderFaq(); renderModalities();
  }
  $$('[data-bind]').forEach(node => node.addEventListener(node.tagName === 'INPUT' && node.type !== 'checkbox' ? 'input' : 'change', () => {
    const value = node.type === 'checkbox' ? node.checked : node.value;
    set(data, node.dataset.bind, value);
    if (node.dataset.bind === 'service_modalities.mode') renderModalities();
    if (node.dataset.bind === 'business_info.agent_name') updateName();
    if (node.dataset.bind === 'commercial_info.pricing_strategy') {
      if (value !== 'fixed_price') data.commercial_info.price_value = null;
      updatePrice();
    }
    markDirty();
  }));
  $$('input[name="baTone"]').forEach(node => node.addEventListener('change', () => {
    if (node.checked) { data.business_info.tone_of_voice = node.value; markDirty(); }
  }));
  $$('input[name="baPayment"]').forEach(node => node.addEventListener('change', () => {
    data.commercial_info.payment_methods = $$('input[name="baPayment"]:checked').map(input => input.value);
    markDirty();
  }));
  const modalityDays = [['mon','Segunda'],['tue','Terça'],['wed','Quarta'],['thu','Quinta'],['fri','Sexta'],['sat','Sábado'],['sun','Domingo']];
  function renderModalities() {
    const m = data.service_modalities;
    $('#baOnlineSettings').hidden = !['online','both'].includes(m.mode);
    $('#baPresencialSettings').hidden = !['presencial','both'].includes(m.mode);
    $('#baModalitiesWarning').hidden = m.mode !== 'both';
    const host = $('#baPresencialDays'); host.replaceChildren();
    for (const [day, label] of modalityDays) {
      const wrap = make('label','ba-modality-day');
      const check = make('input'); check.type = 'checkbox'; check.value = day;
      check.checked = m.presencial_days.includes(day); check.disabled = !canManage;
      check.addEventListener('change', () => {
        m.presencial_days = check.checked
          ? [...m.presencial_days,day].filter((value, idx, list) => list.indexOf(value) === idx)
          : m.presencial_days.filter(value => value !== day);
        markDirty();
      });
      wrap.append(check, make('span','',label)); host.append(wrap);
    }
  }
  function removeButton(label, handler) {
    const btn = make('button', 'ba-remove', '×'); btn.type = 'button'; btn.title = label;
    btn.setAttribute('aria-label', label); btn.disabled = !canManage;
    btn.addEventListener('click', handler);
    return btn;
  }
  function renderHours() {
    const host = $('#baHours'); host.replaceChildren();
    for (const [day, title] of days) {
      const intervals = data.routing_and_schedule.business_hours.filter(i => i.day === day);
      const block = make('div', 'ba-day');
      const header = make('div', 'ba-day-head');
      const label = make('label');
      const cb = make('input'); cb.type = 'checkbox'; cb.checked = intervals.length > 0; cb.disabled = !canManage;
      cb.setAttribute('aria-label', `Atende ${title}`);
      cb.addEventListener('change', () => {
        data.routing_and_schedule.business_hours = data.routing_and_schedule.business_hours.filter(i => i.day !== day);
        if (cb.checked) data.routing_and_schedule.business_hours.push({day, start:'08:00', end:'18:00'});
        markDirty(); renderHours();
      });
      label.append(cb, make('span','',title)); header.append(label);
      if (intervals.length) {
        const add = make('button','ba-time-add','+ Período'); add.type = 'button';
        const last = [...intervals].sort((a,b) => a.end.localeCompare(b.end)).at(-1);
        add.disabled = !canManage || intervals.length >= 3 || data.routing_and_schedule.business_hours.length >= 21 || last.end >= '23:00';
        add.addEventListener('click', () => {
          const endMinutes = Number(last.end.slice(0,2)) * 60 + Number(last.end.slice(3));
          const nextEnd = Math.min(1439, endMinutes + 120);
          const format = n => `${String(Math.floor(n / 60)).padStart(2,'0')}:${String(n % 60).padStart(2,'0')}`;
          data.routing_and_schedule.business_hours.push({day, start:last.end, end:format(nextEnd)});
          markDirty(); renderHours();
        });
        header.append(add);
      } else header.append(make('span','ba-closed','Sem atendimento'));
      block.append(header);
      intervals.forEach((interval, index) => {
        const timeRow = make('div','ba-day-times');
        for (const [field, caption] of [['start','Abre'],['end','Fecha']]) {
          const group = make('label','ba-time-label',caption);
          const clock = make('input','ba-input'); clock.type = 'time'; clock.value = interval[field]; clock.disabled = !canManage;
          clock.setAttribute('aria-label', `${title}, período ${index + 1}, ${caption.toLowerCase()}`);
          clock.addEventListener('change', () => { interval[field] = clock.value; markDirty(); });
          group.append(clock); timeRow.append(group);
        }
        timeRow.append(removeButton(`Remover período de ${title}`, () => {
          data.routing_and_schedule.business_hours = data.routing_and_schedule.business_hours.filter(i => i !== interval);
          markDirty(); renderHours();
        }));
        block.append(timeRow);
      });
      host.append(block);
    }
  }
  function renderQuestions() {
    const host = $('#baQuestions'); host.replaceChildren();
    data.triage_config.custom_questions.forEach((question, idx) => {
      const row = make('div','ba-dynamic-row');
      const input = make('input','ba-input'); input.type = 'text'; input.maxLength = 300;
      input.placeholder = `Ex.: Qual serviço você procura?`; input.value = question; input.disabled = !canManage;
      input.setAttribute('aria-label', `Pergunta personalizada ${idx + 1}`);
      input.addEventListener('input', () => { data.triage_config.custom_questions[idx] = input.value; markDirty(); });
      row.append(input, removeButton(`Remover pergunta ${idx + 1}`, () => {
        data.triage_config.custom_questions.splice(idx,1); markDirty(); renderQuestions();
      }));
      host.append(row);
    });
    $('#baAddQuestion').disabled = !canManage || data.triage_config.custom_questions.length >= 12;
  }
  $('#baAddQuestion').addEventListener('click', () => {
    if (data.triage_config.custom_questions.length >= 12) return;
    data.triage_config.custom_questions.push(''); markDirty(); renderQuestions();
  });
  function renderFaq() {
    const host = $('#baFaq'); host.replaceChildren();
    data.knowledge_base.faq.forEach((faq, idx) => {
      const item = make('article','ba-faq-item');
      const title = make('div','ba-faq-title'); title.append(make('strong','',`Pergunta ${idx + 1}`));
      title.append(removeButton(`Remover pergunta frequente ${idx + 1}`, () => {
        data.knowledge_base.faq.splice(idx,1); markDirty(); renderFaq();
      })); item.append(title);
      for (const [key, desc, max, placeholder] of [['question','O que as pessoas perguntam?',250,'Ex.: Vocês atendem por convênio?'],['answer','Como sua equipe responde?',1500,'Ex.: Trabalhamos com atendimento particular…']]) {
        const label = make('label','ba-field',desc);
        const input = make(key === 'answer' ? 'textarea' : 'input','ba-input');
        if (key === 'question') input.type = 'text'; else input.rows = 3;
        input.maxLength = max; input.value = faq[key] || ''; input.placeholder = placeholder; input.disabled = !canManage;
        input.addEventListener('input', () => { data.knowledge_base.faq[idx][key] = input.value; markDirty(); });
        label.append(input); item.append(label);
      }
      host.append(item);
    });
    $('#baAddFaq').disabled = !canManage || data.knowledge_base.faq.length >= 40;
  }
  $('#baAddFaq').addEventListener('click', () => {
    if (data.knowledge_base.faq.length >= 40) return;
    data.knowledge_base.faq.push({question:'',answer:''}); markDirty(); renderFaq();
  });
  function validate() {
    const d = data;
    if (!d.business_info.agent_name || d.business_info.agent_name.trim().length < 2 || d.business_info.agent_name.trim().length > 120) return ['identity','Informe um nome de 2 a 120 caracteres.'];
    if (!['psychology_clinic','health_clinic','real_estate','beauty_salon','general_services'].includes(d.business_info.segment)) return ['identity','Escolha uma área de atuação válida para sua empresa.'];
    if (!['empathetic_and_welcoming','objective','formal','commercial'].includes(d.business_info.tone_of_voice)) return ['identity','Selecione como o assistente conversa.'];
    const c = d.commercial_info;
    if (c.pricing_strategy === 'fixed_price' && !pricingRegex.test(c.price_value || '')) return ['rules','Informe o valor fixo no formato R$ 250,00.'];
    if (!d.triage_config.collect_name && !d.triage_config.collect_phone && !d.triage_config.custom_questions.length) return ['rules','Selecione uma informação para solicitar durante o atendimento.'];
    const questions = d.triage_config.custom_questions.map(x => x.trim());
    if (questions.length > 12 || questions.some(x => x.length < 5 || x.length > 300) || new Set(questions.map(x => x.toLowerCase())).size !== questions.length) return ['rules','Revise perguntas vazias, repetidas ou muito longas (até 12).'];
    const hours = d.routing_and_schedule.business_hours;
    if (hours.length < 1 || hours.length > 21) return ['rules','Informe pelo menos um período de atendimento.'];
    for (const interval of hours) if (!validClock.test(interval.start) || !validClock.test(interval.end) || interval.start >= interval.end) return ['rules','Revise os horários de abertura e encerramento.'];
    for (const [day] of days) {
      const periods = hours.filter(i => i.day === day).sort((a,b) => a.start.localeCompare(b.start));
      if (periods.length > 3 || periods.some((i,idx) => idx > 0 && periods[idx - 1].end > i.start)) return ['rules','Não é permitido sobrepor períodos no mesmo dia.'];
    }
    const url = d.routing_and_schedule.default_meeting_link;
    if (url && url.length > 500) return ['rules','O link da reunião deve ter no máximo 500 caracteres.'];
    if (url) {
      try { if (new URL(url).protocol !== 'https:') return ['rules','O link de reunião deve começar com https://.']; }
      catch { return ['rules','Confira se o link de reunião está correto.']; }
    }
    if (d.capabilities.send_meeting_link_on_confirmation && !url) return ['rules','Informe um link antes de ativar o envio após a confirmação.'];
    const m = d.service_modalities;
    if (!['not_applicable','online','presencial','both'].includes(m.mode)) return ['rules','Selecione a forma de atendimento.'];
    if (['online','both'].includes(m.mode) && !m.online_channel.trim()) return ['rules','Informe como acontece o atendimento online.'];
    if (['presencial','both'].includes(m.mode)) {
      if (!m.presencial_location.trim()) return ['rules','Informe o local do atendimento presencial.'];
      if (!Array.isArray(m.presencial_days) || !m.presencial_days.length) return ['rules','Selecione os dias do atendimento presencial.'];
    }
    if (d.knowledge_base.faq.length > 40 || d.knowledge_base.faq.some(f => !f.question?.trim() || !f.answer?.trim() || f.question.length > 250 || f.answer.length > 1500)) return ['faq','Preencha todas as perguntas e respostas (até 40).'];
    return null;
  }
  // Comparação de leitura após gravação: só informar sucesso quando a fonte
  // operacional concordar com os valores enviados. Dias podem voltar ordenados.
  function canonicalPayload(value) {
    const result = clone(value);
    result.routing_and_schedule.business_hours = (result.routing_and_schedule.business_hours || [])
      .map(h => ({day:h.day,start:h.start,end:h.end}))
      .sort((a,b) => a.day.localeCompare(b.day) || a.start.localeCompare(b.start));
    result.commercial_info.payment_methods = [...(result.commercial_info.payment_methods || [])].sort();
    result.triage_config.custom_questions = (result.triage_config.custom_questions || [])
      .map(q => q.trim().replace(/\s+/g,' '));
    result.knowledge_base.faq = (result.knowledge_base.faq || []).map(pair => ({
      question: pair.question.trim().replace(/\s+/g,' '),
      answer: pair.answer.trim().replace(/\s+/g,' '),
    }));
    if (result.service_modalities) {
      result.service_modalities.presencial_days = [...(result.service_modalities.presencial_days || [])].sort();
      for (const field of ['online_channel','online_message','presencial_location','presencial_message']) {
        result.service_modalities[field] = (result.service_modalities[field] || '').trim();
      }
    }
    result.routing_and_schedule.default_meeting_link ||= null;
    if (result.commercial_info.pricing_strategy !== 'fixed_price') result.commercial_info.price_value = null;
    return result;
  }
  function mismatchAreas(expected, persisted) {
    const labels = {
      business_info:'identidade', commercial_info:'valores e pagamentos',
      triage_config:'informações e perguntas', routing_and_schedule:'horários de atendimento',
      knowledge_base:'dúvidas frequentes', service_modalities:'modalidades online e presencial', capabilities:'permissões do atendimento',
    };
    const a = canonicalPayload(expected), b = canonicalPayload(persisted);
    return Object.keys(labels).filter(key => JSON.stringify(a[key]) !== JSON.stringify(b[key]))
      .map(key => labels[key]);
  }
  async function save() {
    if (!canManage || saving) return;
    if (!dirty && hasSaved) { notice('Não há alterações pendentes.', 'success'); return; }
    const problem = validate();
    if (problem) { activate(problem[0]); notice(problem[1]); return; }
    if (hasSaved && !window.confirm('Salvar substituirá as perguntas, respostas e regras anteriores da empresa pelos valores desta tela. Outras configurações avançadas podem ser afetadas. Deseja continuar?')) return;
    saving = true; updateChatAvailability();
    $('#baSave').disabled = true; $('#baSave').textContent = 'Salvando…'; clearNotice();
    const revision = editGeneration;
    const snapshot = canonicalPayload(data);
    try {
      await request(root.dataset.saveUrl, 'POST', snapshot);
      const verified = await request(root.dataset.readUrl);
      if (!verified.has_saved || !verified.payload) throw new Error('A configuração não foi localizada após salvar. Não atualize novamente antes de conferir os dados.');
      const differences = mismatchAreas(snapshot, verified.payload);
      if (differences.length) {
        notice('O sistema gravou a operação, mas a conferência encontrou divergências em: ' + differences.join(', ') + '. Nenhuma alteração foi descartada nesta tela. Consulte o suporte antes de salvar de novo.');
        return;
      }
      if (editGeneration !== revision) {
        // Se houve digitação durante a requisição, não sobrescrever o trabalho novo.
        hasSaved = true;
        notice('A versão enviada foi salva e conferida, mas há alterações mais recentes nesta tela. Salve novamente quando terminar.', 'success');
        return;
      }
      Object.assign(data, verified.payload); hasSaved = true;
      populate(); markSaved(); resetChat();
      notice('Configurações gravadas e conferidas. Perguntas e respostas foram preservadas.', 'success');
      await refreshReadiness();
    } catch (err) {
      if (err.errors) {
        const first = Object.entries(err.errors)[0] || [];
        const path = first[0] || '';
        activate(path.startsWith('business_info') ? 'identity' : path.startsWith('knowledge_base') ? 'faq' : 'rules');
        notice(String(first[1] || err.message));
      } else notice(err.message);
    } finally {
      saving = false; $('#baSave').disabled = !canManage; $('#baSave').textContent = 'Salvar configurações';
      updateChatAvailability();
    }
  }
  $('#baSave').addEventListener('click', save);
  // Traduz terminologia interna da auditoria para decisões compreensíveis pela empresa.
  const checkLabels = {
    blueprint: ['Identidade do atendimento', 'Revise e salve as informações iniciais do seu atendimento.'],
    workflow: ['Ordem do atendimento', 'Confira se as perguntas e os encaminhamentos estão na ordem esperada.'],
    triage: ['Informações dos clientes', 'Revise quais informações precisam ser solicitadas.'],
    commercial_policies: ['Valores e pagamentos', 'Revise os valores e formas de pagamento configurados.'],
    capabilities: ['Link de consulta online', 'Confira o link usado após confirmar uma consulta.'],
  };
  function friendlyCheck(item) {
    const label = item.category === 'channel_integration' && /credencial/i.test(item.item || '')
      ? ['Serviço de respostas', 'A conexão do serviço de respostas precisa ser configurada pela administração da plataforma.']
      : checkLabels[item.category];
    let title = label ? label[0] : (item.item || 'Verificação');
    let message = item.message || '';
    if (item.category === 'channel_integration' && /WhatsApp/i.test(item.item || '')) {
      title = 'WhatsApp';
      message = item.message || (item.status === 'ok' ? 'Canal conectado e preparado para receber mensagens.'
        : 'Verifique a conexão e o vínculo do WhatsApp com este atendimento.');
    } else if (label && item.status === 'ok') {
      message = 'Configurado e conferido.';
    } else if (label && item.status !== 'ok') {
      message = label[1];
    } else if (/\b(IA|blueprint|workflow|webhook|capabilit|guardrail|prompt|token|LLM|tenant)\b/i.test(message)) {
      message = 'Verifique esta configuração antes de liberar o atendimento.';
    }
    if (item.category === 'routing_and_schedule' && /agenda/i.test(item.item || '')) {
      title = 'Agenda e aprovação da equipe';
      message = item.status === 'ok' ? 'A agenda segue as permissões definidas.' : 'Confira as permissões de agenda e a aprovação pela equipe.';
    }
    if (item.category === 'routing_and_schedule' && /expediente/i.test(item.item || '')) title = 'Atendimento fora do horário';
    return [title, message];
  }
  function addChecklistItem(item) {
    const row = make('div','ba-check');
    const status = ['ok','error','warning'].includes(item.status) ? item.status : 'warning';
    row.dataset.status = status;
    row.append(make('span','ba-check-icon',status === 'ok' ? '✓' : status === 'error' ? '×' : '!'));
    const body = make('div','ba-check-body');
    const [title, description] = friendlyCheck(item);
    body.append(make('strong','',title));
    body.append(make('small','',description));
    if (item.auto_fix_available && item.auto_fix_action && canManage) {
      const btn = make('button','ba-fix','Corrigir automaticamente'); btn.type = 'button'; btn.disabled = dirty;
      btn.addEventListener('click', () => applyFix(item, btn)); body.append(btn);
    }
    row.append(body); $('#baReadinessList').append(row);
  }
  function setReadinessText(score, ok) {
    $('#baScore').textContent = String(score) + '%';
    $('#baDonut').style.setProperty('--pct',String(score));
    $('#baDonut').style.setProperty('--ring',ok ? '#27956d' : '#dc9b35');
    $('#baDonut').setAttribute('aria-label',`Prontidão de ${score} por cento`);
    $('#baReadyTitle').textContent = ok ? 'Tudo pronto para revisão final' : 'Ainda há ajustes necessários';
    $('#baReadyDetail').textContent = dirty ? 'As alterações recentes ainda não foram salvas.' : ok ? 'Nenhuma falha crítica encontrada.' : 'Resolva os pontos críticos antes de ativar o atendimento.';
  }
  function updateChannel(checklist, details = null) {
    const channel = checklist.find(c => c.category === 'channel_integration' && /WhatsApp/i.test(c.item || ''));
    const host = $('#baChannelStatus'); host.replaceChildren();
    const row = make('div','ba-channel-row');
    const ready = channel?.status === 'ok';
    const physicallyConnected = details?.connected === true;
    row.append(make('span','ba-check-icon',ready ? '✓' : physicallyConnected ? '!' : '×'));
    const desc = make('div');
    desc.append(make('strong','',ready ? 'WhatsApp conectado ao atendimento'
      : physicallyConnected ? 'WhatsApp conectado · Verifique o vínculo do assistente'
        : 'WhatsApp sem conexão validada'));
    desc.append(make('small','',details?.message || channel?.message || 'Confira os canais configurados.'));
    row.append(desc); host.append(row);
  }
  async function refreshReadiness() {
    if (!selectedAgent || !canView) {
      $('#baReadyTitle').textContent = selectedAgent ? 'Sem permissão de consulta' : 'Primeiro configure seu assistente principal';
      $('#baReadyDetail').textContent = 'A verificação estará disponível após o vínculo do atendimento.';
      $('#baReadinessList').replaceChildren();
      $('#baChannelStatus').textContent = 'Nenhuma conexão verificada.';
      return;
    }
    $('#baRefresh').disabled = true;
    try {
      const result = await request(endpoint('readiness'));
      readiness = result;
      const score = Number.isInteger(result.readiness_score) ? Math.min(100,Math.max(0,result.readiness_score)) : 0;
      setReadinessText(score, result.can_operate === true);
      const checks = Array.isArray(result.checklist) ? result.checklist : [];
      const pending = checks.filter(c => c.status !== 'ok');
      const critical = pending.filter(c => c.status === 'error' && c.severity === 'critical');
      if (!dirty) $('#baReadyDetail').textContent = pending.length === 0
        ? 'Nenhuma pendência encontrada. O atendimento ainda depende da sua decisão de ativar.'
        : `${pending.length} ${pending.length === 1 ? 'pendência' : 'pendências'}, ${critical.length} ${critical.length === 1 ? 'crítica' : 'críticas'}. A nota mede prontidão, não o percentual de regras salvas.`;
      $('#baReadinessList').replaceChildren();
      (Array.isArray(result.checklist) ? result.checklist : []).forEach(addChecklistItem);
      updateChannel(Array.isArray(result.checklist) ? result.checklist : [], result.channel_status);
    } catch (e) {
      $('#baReadyTitle').textContent = 'Verificação indisponível';
      $('#baReadyDetail').textContent = e.message;
      $('#baScore').textContent = '--';
      $('#baDonut').style.setProperty('--pct','0');
      $('#baChannelStatus').textContent = 'Não foi possível conferir o canal. Atualize a verificação.';
    } finally { $('#baRefresh').disabled = false; }
  }
  async function applyFix(item, btn) {
    if (dirty) { notice('Salve ou descarte suas alterações antes de corrigir automaticamente.'); return; }
    const descriptions = {
      set_default_out_of_hours_action: 'Quando alguém escrever fora do horário, o contato será registrado para retorno no próximo expediente.',
      disable_meeting_link_capability: 'O envio de link automático será desligado até você informar um endereço válido.',
      restore_required_triage_steps: 'As informações obrigatórias sem etapa de coleta serão vinculadas antes da consulta à agenda. Perguntas, horários e reservas existentes serão preservados.',
    };
    const description = descriptions[item.auto_fix_action];
    if (!description) return;
    if (!window.confirm(`${description}\n\nAplicar esta mudança na configuração salva?`)) return;
    btn.disabled = true; btn.textContent = 'Corrigindo…';
    try {
      await request(endpoint('autofix'), 'POST', {auto_fix_action:item.auto_fix_action});
      if (item.auto_fix_action === 'set_default_out_of_hours_action') data.routing_and_schedule.out_of_hours_action = 'capture_lead_and_promise_contact';
      if (item.auto_fix_action === 'disable_meeting_link_capability') data.capabilities.send_meeting_link_on_confirmation = false;
      lastSaved = clone(data);
      populate(); resetChat();
      notice('Correção aplicada. Confira o novo resultado.', 'success');
      await refreshReadiness();
    } catch(e) { notice(e.message); await refreshReadiness(); }
  }
  $('#baRefresh').addEventListener('click', refreshReadiness);
  function bubble(text, who) {
    const chat = $('#baChatLog');
    if ($('.ba-chat-empty', chat)) chat.replaceChildren();
    const node = make('div',`ba-bubble ${who}`, text);
    chat.append(node); chat.scrollTop = chat.scrollHeight;
  }
  function resetChat() {
    history = []; state = {};
    $('#baChatLog').replaceChildren(make('div','ba-chat-empty','Experimente escrever “Olá! Quero agendar um horário.”'));
  }
  $('#baChatReset').addEventListener('click', resetChat);
  $('#baChatForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (!selectedAgent || !hasSaved || dirty || chatBusy || !canManage) return;
    const input = $('#baChatInput');
    const message = input.value.trim();
    if (!message) return;
    if (history.length >= 14) { bubble('A conversa chegou ao limite de teste. Reinicie para continuar.', 'note'); return; }
    chatBusy = true; updateChatAvailability(); input.value = '';
    bubble(message, 'user');
    try {
      const answer = await request(endpoint('simulate'), 'POST', {message,history,state});
      if (!answer.safe || answer.needs_review) bubble('Este resultado merece revisão antes de ser usado com clientes.', 'note');
      bubble(answer.response || 'Não foi possível obter uma resposta.', 'bot');
      state = answer.state && typeof answer.state === 'object' ? answer.state : {};
      history = Array.isArray(answer.history) ? answer.history.slice(-16) : [];
    } catch (e) { bubble(e.message,'note'); }
    finally { chatBusy = false; updateChatAvailability(); input.focus(); }
  });
  window.addEventListener('beforeunload', e => {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });
  try {
    populate();
    $('#baDirtyLabel').textContent = hasSaved ? 'Tudo salvo' : 'Pendente de configuração';
  } catch (error) {
    console.error('[RS Connect][Business] Falha ao abrir configuração', error);
    notice('Parte da configuração não pôde ser exibida. Os dados não foram alterados. Atualize a página ou contate o suporte.');
    $('#baDirtyLabel').textContent = 'Conferência necessária';
    $('#baSave').disabled = true;
  }
  updateChatAvailability(); refreshReadiness();
})();
