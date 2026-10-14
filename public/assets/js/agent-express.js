/* RS Connect — AgentWizard + ReadinessDashboard. Sem dependências externas. */
(() => {
  'use strict';
  const root = document.getElementById('agentExpress');
  if (!root) return;

  const $ = (selector, ctx = root) => ctx.querySelector(selector);
  const $$ = (selector, ctx = root) => [...ctx.querySelectorAll(selector)];
  const el = (tag, className = '', text = '') => {
    const item = document.createElement(tag);
    if (className) item.className = className;
    if (text !== '') item.textContent = String(text);
    return item;
  };
  const days = [
    ['monday', 'Segunda-feira'], ['tuesday', 'Terça-feira'], ['wednesday', 'Quarta-feira'],
    ['thursday', 'Quinta-feira'], ['friday', 'Sexta-feira'], ['saturday', 'Sábado'], ['sunday', 'Domingo'],
  ];
  const names = {
    psychology_clinic: 'Psicologia', health_clinic: 'Clínica de saúde',
    real_estate: 'Imobiliária', beauty_salon: 'Beleza e estética', general_services: 'Outros serviços',
    empathetic_and_welcoming: 'Acolhedor', objective: 'Direto e profissional', formal: 'Formal', commercial: 'Consultivo',
    fixed_price: 'Preço fixo', variable_price: 'A partir de / valor variável', on_request: 'Sob consulta',
  };
  const validClock = /^([01]\d|2[0-3]):[0-5]\d$/;
  const pricePattern = /^R\$\s*(?:\d{1,3}(?:\.\d{3})*|\d+),\d{2}$/u;
  function get(obj, dotted) { return dotted.split('.').reduce((acc, key) => acc?.[key], obj); }
  function put(obj, dotted, value) {
    const parts = dotted.split('.'); let current = obj;
    for (const part of parts.slice(0, -1)) current = current[part];
    current[parts.at(-1)] = value;
  }
  function validHttps(value) {
    try { return new URL(value).protocol === 'https:'; } catch { return false; }
  }
  function apiUrl(agentId, endpoint) {
    // agentId é produzido pelo servidor com PublicId e nunca composto do input do usuário.
    return `${root.dataset.apiRoot}/${encodeURIComponent(agentId)}/${endpoint}`;
  }
  async function jsonRequest(url, method = 'GET', data = null) {
    const options = { method, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
    if (data !== null) {
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-TOKEN'] = root.dataset.csrf;
      options.body = JSON.stringify(data);
    }
    const response = await fetch(url, options);
    const type = response.headers.get('content-type') || '';
    if (!type.includes('application/json')) {
      throw new Error(response.status === 403 || response.status === 419
        ? 'Sua sessão ou permissão expirou. Atualize a página e entre novamente.'
        : 'Não foi possível comunicar com o sistema. Atualize a página e tente outra vez.');
    }
    const result = await response.json();
    if (!response.ok || result.ok === false) {
      const problem = new Error(result.message || 'Não foi possível concluir a solicitação.');
      problem.errors = result.errors || null;
      problem.status = response.status;
      throw problem;
    }
    return result;
  }

  class ReadinessDashboard {
    constructor(host, agents, canView, canFix) {
      this.host = host;
      this.agents = agents;
      this.canView = canView;
      this.canFix = canFix;
      this.agentSelect = $('#rexAgentSelect');
      this.agentSelect.replaceChildren();
      for (const agent of agents) {
        const option = new Option(agent.name || 'Assistente', agent.id);
        this.agentSelect.add(option);
      }
      this.agentSelect.addEventListener('change', () => this.load());
    }
    async load() {
      if (!this.agents.length) {
        this.empty('Configuração salva. Agora é necessário criar um assistente e vinculá-lo ao WhatsApp.', true);
        return;
      }
      if (!this.canView) {
        this.empty('Sua conta não possui permissão para verificar a prontidão. Solicite acesso ao administrador.', false);
        return;
      }
      const id = this.agentSelect.value;
      if (!id) return;
      this.host.replaceChildren(el('p', 'rex-loading', 'Verificando as informações do atendimento…'));
      try {
        const report = await jsonRequest(apiUrl(id, 'readiness'));
        this.render(report);
      } catch (error) { this.empty(error.message, false); }
    }
    empty(message, withLinks) {
      this.host.replaceChildren();
      const wrap = el('div', 'rex-empty');
      wrap.append(el('h4', '', 'Próximo passo'), el('p', '', message));
      if (withLinks) {
        const links = el('div', 'rex-empty-links');
        for (const [href, name] of [[root.dataset.agentsUrl, 'Cadastrar assistente'], [root.dataset.instancesUrl, 'Conectar WhatsApp']]) {
          const link = el('a', 'rex-button rex-button-subtle', name); link.href = href; links.append(link);
        }
        wrap.append(links);
      }
      this.host.append(wrap);
    }
    static itemTitle(item) {
      return ({ 'Perfil e blueprint': 'Modelo de atendimento',
        'Credencial de IA': 'Conexão do serviço de atendimento',
        'Execução do fluxo e políticas': 'Ordem das perguntas e atendimento',
        'Ação fora do expediente': 'Resposta fora do expediente',
        'Link de reunião': 'Link de reunião',
        'Horário e fuso operacional': 'Horários da empresa',
        'Agenda e aprovação humana': 'Agenda e confirmação',
        'Política comercial': 'Valores e formas de pagamento',
        'Perguntas de triagem': 'Perguntas iniciais',
        'Conexão com WhatsApp': 'WhatsApp conectado',
      })[item] || item;
    }
    static help(check) {
      const key = check.item;
      const success = check.status === 'ok';
      const table = {
        'Perfil e blueprint': ['Seu modelo de atendimento foi preparado.', 'Reveja e salve as informações desta configuração.'],
        'Conexão com WhatsApp': ['Seu WhatsApp está disponível para receber mensagens.', 'Conecte um número e confirme que ele pode receber mensagens.'],
        'Credencial de IA': ['O serviço de atendimento está conectado.', 'Solicite à equipe responsável a conexão do serviço de atendimento.'],
        'Horário e fuso operacional': ['Horários de atendimento definidos.', 'Confira os dias, horários e o fuso da empresa.'],
        'Ação fora do expediente': ['Já existe uma resposta para o horário fechado.', 'Escolha o que fazer quando a empresa estiver fechada.'],
        'Perguntas de triagem': ['Perguntas iniciais preparadas.', 'Inclua ao menos uma pergunta necessária para atender.'],
        'Execução do fluxo e políticas': ['Sequência de atendimento organizada.', 'Algumas regras se contradizem. Revise a configuração ou solicite ajuda à equipe.'],
        'Agenda e aprovação humana': ['Agenda e confirmação estão alinhadas.', 'Revise os ajustes de agenda e a necessidade de aprovação da equipe.'],
        'Política comercial': ['Valores e formas de pagamento definidos.', 'Confira os valores e as formas de pagamento informados.'],
        'Link de reunião': ['Envio de link de reunião configurado.', 'O envio de link está ligado, mas é necessário informar um endereço válido.'],
      };
      return (table[key] || [check.message, check.message])[success ? 0 : 1];
    }
    render(report) {
      const score = Number.isFinite(Number(report.readiness_score)) ? Math.max(0, Math.min(100, Number(report.readiness_score))) : 0;
      const ready = report.can_operate === true;
      this.host.replaceChildren();
      const header = el('div', 'rex-readiness-head');
      const meter = el('div', `rex-donut ${ready ? 'is-ready' : 'is-pending'}`);
      meter.style.setProperty('--rex-progress', `${score}%`);
      meter.setAttribute('role', 'img'); meter.setAttribute('aria-label', `${score}% de prontidão`);
      const ringInner = el('div', 'rex-donut-inner'); ringInner.append(el('strong', '', `${score}%`), el('span', '', 'preparado')); meter.append(ringInner);
      const copy = el('div', 'rex-readiness-copy');
      copy.append(el('span', 'rex-eyebrow', 'SITUAÇÃO DO ATENDIMENTO'),
        el('h4', '', ready ? 'Seu atendimento passou na verificação' : 'Ainda há alguns ajustes necessários'),
        el('p', '', ready
          ? 'Nenhuma pendência crítica foi encontrada. O atendimento não será ativado por esta tela.'
          : 'Para proteger seus clientes, o atendimento automático ainda não pode ser liberado.'));
      const status = el('span', `rex-status-pill ${ready ? 'is-ready' : 'is-pending'}`,
        report.status === 'ACTIVE' ? 'Atendimento ligado' : ready ? 'Pronto para ativar' : 'Configuração pendente');
      copy.append(status); header.append(meter, copy); this.host.append(header);
      const section = el('section', 'rex-checklist');
      section.append(el('h4', '', 'Lista de verificação'));
      const checks = Array.isArray(report.checklist) ? report.checklist : [];
      for (const check of checks) {
        const isOk = check.status === 'ok';
        const row = el('div', `rex-check ${check.status === 'error' ? 'is-error' : check.status === 'warning' ? 'is-warning' : 'is-ok'}`);
        row.append(el('span', 'rex-check-symbol', isOk ? '✓' : check.status === 'warning' ? '!' : '×'));
        const body = el('div', 'rex-check-body');
        body.append(el('strong', '', ReadinessDashboard.itemTitle(check.item)), el('p', '', ReadinessDashboard.help(check)));
        row.append(body);
        if (!isOk && check.auto_fix_available === true && typeof check.auto_fix_action === 'string') {
          if (this.canFix) {
            const button = el('button', 'rex-button rex-button-subtle rex-fix', 'Corrigir automaticamente');
            button.type = 'button';
            button.addEventListener('click', () => this.fix(check, button));
            row.append(button);
          } else {
            row.append(el('small', 'rex-no-permission', 'Solicite correção ao administrador'));
          }
        }
        section.append(row);
      }
      this.host.append(section);
      const bottom = el('div', 'rex-dashboard-bottom');
      const refresh = el('button', 'rex-button rex-button-subtle', 'Verificar novamente');
      refresh.type = 'button'; refresh.addEventListener('click', () => this.load());
      bottom.append(refresh);
      const goBack = el('button', 'rex-button rex-button-subtle', 'Editar configuração');
      goBack.type = 'button'; goBack.addEventListener('click', () => root.dispatchEvent(new CustomEvent('rex:edit')));
      bottom.append(goBack);
      if (ready) {
        const onboard = el('a', 'rex-button rex-button-primary', 'Seguir para a ativação');
        onboard.href = root.dataset.onboardingUrl;
        bottom.append(onboard);
      }
      this.host.append(bottom);
    }
    async fix(check, button) {
      const detail = check.auto_fix_action === 'disable_meeting_link_capability'
        ? 'O envio automático de links de reunião será desligado. Nenhum link será enviado até você reativar a opção.'
        : 'Quando alguém escrever fora do horário, o contato será registrado para retorno no próximo expediente.';
      if (!window.confirm(`${detail}\n\nDeseja aplicar esta correção?`)) return;
      button.disabled = true; button.textContent = 'Corrigindo…';
      try {
        const updated = await jsonRequest(apiUrl(this.agentSelect.value, 'autofix'), 'POST', { auto_fix_action: check.auto_fix_action });
        // Não reutilizar estado local após correção: sempre consultar o servidor novamente.
        root.dispatchEvent(new CustomEvent('rex:autofix', { detail: { action: check.auto_fix_action } }));
        // O POST retorna {applied, action, readiness}; o GET é a fonte de verdade após o COMMIT.
        await this.load();
      } catch (error) {
        this.empty(error.message, false);
      }
    }
  }

  class AgentWizard {
    constructor(initial) {
      this.data = initial.payload;
      this.hasSaved = initial.has_saved === true;
      this.agents = Array.isArray(initial.agents) ? initial.agents : [];
      this.step = 0; this.busy = false; this.dirty = false; this.showingReadiness = false;
      this.dashboard = new ReadinessDashboard($('#rexDashboard'), this.agents, root.dataset.canView === '1', root.dataset.canFix === '1');
      this.populate();
      this.wire();
      this.navigate(0);
    }
    populate() {
      $$('[data-bind]').forEach(control => {
        const value = get(this.data, control.dataset.bind);
        if (control.type === 'checkbox') control.checked = value === true;
        else {
          if (control.tagName === 'SELECT' && value && ![...control.options].some(option => option.value === value)) {
            control.add(new Option(String(value), String(value))); // preserva fuso existente
          }
          control.value = value == null ? '' : String(value);
        }
      });
      $$('input[name="rexSegment"]').forEach(c => { c.checked = c.value === this.data.business_info.segment; });
      $$('input[name="rexTone"]').forEach(c => { c.checked = c.value === this.data.business_info.tone_of_voice; });
      $$('input[name="payment"]').forEach(c => { c.checked = this.data.commercial_info.payment_methods.includes(c.value); });
      this.renderQuestions(); this.renderFaq(); this.renderHours(); this.updatePrice();
    }
    wire() {
      $('#rexWizard').addEventListener('submit', event => event.preventDefault());
      const changed = (ev) => {
        const control = ev.target.closest('[data-bind]');
        if (!control) return;
        const value = control.type === 'checkbox' ? control.checked : control.value;
        put(this.data, control.dataset.bind, value);
        this.dirty = true;
        if (control.dataset.bind === 'commercial_info.pricing_strategy') {
          this.data.commercial_info.price_value = null;
          this.updatePrice();
        }
      };
      root.addEventListener('input', changed); root.addEventListener('change', changed);
      root.addEventListener('change', (ev) => {
        const control = ev.target;
        if (control.name === 'rexSegment') { this.data.business_info.segment = control.value; this.dirty = true; }
        if (control.name === 'rexTone') { this.data.business_info.tone_of_voice = control.value; this.dirty = true; }
        if (control.name === 'payment') {
          this.data.commercial_info.payment_methods = $$('input[name="payment"]:checked').map(n => n.value);
          this.dirty = true;
        }
      });
      $('#rexAddQuestion').addEventListener('click', () => {
        if (this.data.triage_config.custom_questions.length >= 12) return this.notice('Você pode adicionar até 12 perguntas.');
        this.data.triage_config.custom_questions.push(''); this.renderQuestions(); this.dirty = true;
        $('#rexQuestions').lastElementChild?.querySelector('input')?.focus();
      });
      $('#rexAddFaq').addEventListener('click', () => {
        if (this.data.knowledge_base.faq.length >= 40) return this.notice('Você pode adicionar até 40 perguntas frequentes.');
        this.data.knowledge_base.faq.push({ question: '', answer: '' }); this.renderFaq(); this.dirty = true;
        $('#rexFaq').lastElementChild?.querySelector('input')?.focus();
      });
      $('#rexBack').addEventListener('click', () => this.navigate(Math.max(0, this.step - 1)));
      $('#rexNext').addEventListener('click', () => this.next());
      $$('[data-step-link]').forEach(button => button.addEventListener('click', () => {
        const target = Number(button.dataset.stepLink);
        if (target <= this.step) this.navigate(target);
        else if (this.validateThrough(target - 1)) this.navigate(target);
      }));
      $('#rexOpenDashboard')?.addEventListener('click', () => this.openDashboard());
      root.addEventListener('rex:edit', () => { this.showingReadiness = false; this.navigate(0); });
      root.addEventListener('rex:autofix', event => {
        // A correção do servidor também atualiza o rascunho exibido, evitando revertê-la ao salvar.
        const action = event.detail?.action;
        if (action === 'disable_meeting_link_capability') this.data.capabilities.send_meeting_link_on_confirmation = false;
        if (action === 'set_default_out_of_hours_action') this.data.routing_and_schedule.out_of_hours_action = 'capture_lead_and_promise_contact';
        this.populate();
      });
      window.addEventListener('beforeunload', event => {
        if (this.dirty) { event.preventDefault(); event.returnValue = ''; }
      });
    }
    notice(message, type = 'error') {
      const banner = $('#rexNotice'); banner.textContent = message; banner.dataset.type = type; banner.hidden = false;
      banner.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
    clearNotice() { $('#rexNotice').hidden = true; $('#rexNotice').textContent = ''; }
    updatePrice() {
      const fixed = this.data.commercial_info.pricing_strategy === 'fixed_price';
      $('#rexPriceWrap').hidden = !fixed;
      $('#rexVariableHint').hidden = this.data.commercial_info.pricing_strategy !== 'variable_price';
      const input = $('[data-bind="commercial_info.price_value"]');
      input.disabled = !fixed; input.value = fixed ? this.data.commercial_info.price_value || '' : '';
    }
    renderQuestions() {
      const host = $('#rexQuestions'); host.replaceChildren();
      this.data.triage_config.custom_questions.forEach((question, index) => {
        const row = el('div', 'rex-dynamic-row');
        const input = el('input', 'rex-input'); input.type = 'text'; input.maxLength = 300;
        input.value = question; input.placeholder = `Pergunta ${index + 1} (ex.: É a sua primeira consulta?)`;
        input.setAttribute('aria-label', `Pergunta ${index + 1}`);
        input.addEventListener('input', () => { this.data.triage_config.custom_questions[index] = input.value; this.dirty = true; });
        const remove = el('button', 'rex-icon-button', '×'); remove.type = 'button';
        remove.setAttribute('aria-label', `Excluir pergunta ${index + 1}`);
        remove.addEventListener('click', () => { this.data.triage_config.custom_questions.splice(index, 1); this.renderQuestions(); this.dirty = true; });
        row.append(input, remove); host.append(row);
      });
    }
    renderFaq() {
      const host = $('#rexFaq'); host.replaceChildren();
      this.data.knowledge_base.faq.forEach((pair, index) => {
        const section = el('div', 'rex-faq-item');
        const top = el('div', 'rex-faq-top'); top.append(el('strong', '', `Resposta ${index + 1}`));
        const remove = el('button', 'rex-link-button', 'Excluir'); remove.type = 'button';
        remove.setAttribute('aria-label', `Excluir resposta ${index + 1}`);
        remove.addEventListener('click', () => { this.data.knowledge_base.faq.splice(index, 1); this.renderFaq(); this.dirty = true; });
        top.append(remove);
        const qLabel = el('label', 'rex-form-field', 'O que o cliente costuma perguntar?');
        const q = el('input', 'rex-input'); q.type = 'text'; q.value = pair.question || '';
        q.maxLength = 250; q.placeholder = 'Ex.: Vocês aceitam convênio?';
        q.addEventListener('input', () => { this.data.knowledge_base.faq[index].question = q.value; this.dirty = true; });
        const aLabel = el('label', 'rex-form-field', 'Qual resposta sua equipe daria?');
        const a = el('textarea', 'rex-input'); a.rows = 2; a.maxLength = 1500;
        a.value = pair.answer || ''; a.placeholder = 'Ex.: Atendemos somente consultas particulares…';
        a.addEventListener('input', () => { this.data.knowledge_base.faq[index].answer = a.value; this.dirty = true; });
        qLabel.append(q); aLabel.append(a); section.append(top, qLabel, aLabel); host.append(section);
      });
    }
    renderHours() {
      const host = $('#rexHours'); host.replaceChildren();
      const hours = this.data.routing_and_schedule.business_hours;
      for (const [day, name] of days) {
        const intervals = hours.filter(item => item.day === day);
        const wrapper = el('div', 'rex-day');
        const top = el('div', 'rex-day-header');
        const checkLabel = el('label', 'rex-day-enable');
        const check = el('input'); check.type = 'checkbox'; check.checked = intervals.length > 0;
        check.setAttribute('aria-label', `${name}: com atendimento`);
        check.addEventListener('change', () => {
          this.data.routing_and_schedule.business_hours = hours.filter(i => i.day !== day);
          if (check.checked) this.data.routing_and_schedule.business_hours.push({ day, start: '08:00', end: '18:00' });
          this.renderHours(); this.dirty = true;
        });
        checkLabel.append(check, el('strong', '', name)); top.append(checkLabel);
        if (intervals.length) {
          const add = el('button', 'rex-link-button', '+ Horário'); add.type = 'button';
          add.disabled = intervals.length >= 3 || hours.length >= 21;
          add.addEventListener('click', () => {
            hours.push({ day, start: '13:00', end: '18:00' }); this.renderHours(); this.dirty = true;
          }); top.append(add);
        } else top.append(el('small', 'rex-closed', 'Sem atendimento'));
        wrapper.append(top);
        for (const interval of intervals) {
          const inner = el('div', 'rex-time-pair');
          for (const [field, title] of [['start', 'Abre às'], ['end', 'Fecha às']]) {
            const label = el('label', 'rex-time-control', title);
            const input = el('input', 'rex-input'); input.type = 'time'; input.value = interval[field];
            input.addEventListener('change', () => { interval[field] = input.value; this.dirty = true; });
            label.append(input); inner.append(label);
          }
          const remove = el('button', 'rex-icon-button', '×'); remove.type = 'button';
          remove.setAttribute('aria-label', `Excluir horário de ${name}`);
          remove.addEventListener('click', () => {
            this.data.routing_and_schedule.business_hours = hours.filter(item => item !== interval);
            this.renderHours(); this.dirty = true;
          });
          inner.append(remove); wrapper.append(inner);
        }
        host.append(wrapper);
      }
    }
    validateStep(step) {
      const d = this.data;
      if (step === 0) {
        if (d.business_info.agent_name.trim().length < 2 || d.business_info.agent_name.trim().length > 120) return 'Informe um nome para seu assistente (entre 2 e 120 caracteres).';
        if (!['psychology_clinic','health_clinic','real_estate','beauty_salon','general_services'].includes(d.business_info.segment)) return 'Escolha o tipo de empresa.';
        if (!['empathetic_and_welcoming','objective','formal','commercial'].includes(d.business_info.tone_of_voice)) return 'Escolha como seu assistente deve conversar.';
      }
      if (step === 1) {
        const c = d.commercial_info;
        if (c.pricing_strategy === 'fixed_price' && !pricePattern.test(c.price_value || '')) return 'Informe o preço fixo no formato R$ 250,00.';
        if (d.triage_config.custom_questions.length > 12 || d.triage_config.custom_questions.some(q => q.trim().length < 5 || q.length > 300)) return 'Revise suas perguntas: cada uma deve ter entre 5 e 300 caracteres.';
        const unique = d.triage_config.custom_questions.map(q => q.trim().toLocaleLowerCase('pt-BR'));
        if (new Set(unique).size !== unique.length) return 'Existem perguntas repetidas. Mantenha apenas uma de cada.';
        if (!d.triage_config.collect_name && !d.triage_config.collect_phone && !unique.length) return 'Escolha ao menos uma informação para pedir antes do atendimento.';
      }
      if (step === 2) {
        const r = d.routing_and_schedule;
        if (!r.business_hours.length) return 'Escolha pelo menos um dia e horário de atendimento.';
        for (const range of r.business_hours) {
          if (!validClock.test(range.start) || !validClock.test(range.end) || range.start >= range.end) return 'Revise os horários: a abertura precisa ser anterior ao encerramento.';
        }
        for (const [day] of days) {
          const intervals = r.business_hours.filter(x => x.day === day).sort((a,b) => a.start.localeCompare(b.start));
          if (intervals.some((x,i) => i && x.start < intervals[i-1].end)) return 'Existem horários sobrepostos no mesmo dia. Ajuste os períodos.';
        }
        if (r.default_meeting_link && !validHttps(r.default_meeting_link)) return 'Use um link de reunião HTTPS válido.';
        if (d.capabilities.send_meeting_link_on_confirmation && !r.default_meeting_link) return 'Para enviar o link ao confirmar, informe antes o endereço da reunião.';
        if (d.knowledge_base.faq.length > 40 || d.knowledge_base.faq.some(f => !f.question?.trim() || !f.answer?.trim())) return 'Preencha a pergunta e a resposta de cada dúvida frequente.';
        if (d.knowledge_base.faq.some(f => f.question.length > 250 || f.answer.length > 1500)) return 'Há uma dúvida frequente muito longa. Reduza o texto.';
      }
      return null;
    }
    validateThrough(last) {
      for (let i = 0; i <= Math.min(last, 2); i++) {
        const message = this.validateStep(i);
        if (message) { this.navigate(i); this.notice(message); return false; }
      }
      return true;
    }
    navigate(target) {
      if (this.busy) return;
      this.step = target; this.clearNotice();
      $$('[data-step-panel]').forEach(panel => panel.hidden = Number(panel.dataset.stepPanel) !== target);
      $$('[data-step-link]').forEach(button => {
        const pos = Number(button.dataset.stepLink);
        button.classList.toggle('is-active', pos === target);
        button.classList.toggle('is-complete', pos < target);
        if (pos === target) button.setAttribute('aria-current', 'step'); else button.removeAttribute('aria-current');
      });
      $('#rexBack').hidden = target === 0 || this.showingReadiness;
      $('#rexNext').hidden = this.showingReadiness;
      $('#rexNext').textContent = target === 3 ? 'Salvar configuração' : 'Continuar →';
      $('#rexStepHint').textContent = `Etapa ${target + 1} de 4`;
      $('#rexActions').hidden = this.showingReadiness;
      if (target === 3) {
        $('#rexReview').hidden = this.showingReadiness;
        $('#rexReadiness').hidden = !this.showingReadiness;
        if (!this.showingReadiness) this.renderReview();
      }
      root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    renderReview() {
      const host = $('#rexSummary'); host.replaceChildren();
      const d = this.data;
      const daysText = days.filter(([key]) => d.routing_and_schedule.business_hours.some(r => r.day === key))
        .map(([key, name]) => `${name.replace('-feira', '')}: ${d.routing_and_schedule.business_hours
          .filter(r => r.day === key).map(r => `${r.start}–${r.end}`).join(' e ')}`).join('; ');
      const boxes = [
        ['Seu assistente', [d.business_info.agent_name, names[d.business_info.segment], `Conversa: ${names[d.business_info.tone_of_voice]}`]],
        ['Informações coletadas', [d.triage_config.collect_name ? 'Nome' : 'Nome não obrigatório', d.triage_config.collect_phone ? 'Telefone' : 'Telefone não obrigatório', `${d.triage_config.custom_questions.length} perguntas adicionais`]],
        ['Valores', [names[d.commercial_info.pricing_strategy], d.commercial_info.pricing_strategy === 'fixed_price' ? d.commercial_info.price_value : '', `${d.commercial_info.payment_methods.length} formas de pagamento`]],
        ['Horários e respostas', [daysText, `${d.knowledge_base.faq.length} respostas frequentes`, `Link automático: ${d.capabilities.send_meeting_link_on_confirmation ? 'Sim' : 'Não'}`]],
      ];
      for (const [title, lines] of boxes) {
        const card = el('div', 'rex-review-card'); card.append(el('h4', '', title));
        for (const line of lines) if (line) card.append(el('p', '', line));
        host.append(card);
      }
    }
    async next() {
      if (this.busy) return;
      if (this.step < 3) {
        if (this.validateStep(this.step)) return this.notice(this.validateStep(this.step));
        this.navigate(this.step + 1);
      } else if (this.validateThrough(2)) await this.save();
    }
    async save() {
      if (this.hasSaved && !this.dirty) { this.openDashboard(); return; }
      if (this.hasSaved && !window.confirm('Salvar novamente substitui as perguntas e regras de atendimento anteriores pelas escolhas desta tela. Deseja continuar?')) return;
      this.busy = true;
      const button = $('#rexNext'); button.disabled = true; button.textContent = 'Salvando…';
      try {
        // A API exige JSON estrito; o servidor valida e aplica transação antes de responder.
        // A API não aceita caracteres de controle no FAQ: preserva o conteúdo como frases.
        this.data.knowledge_base.faq = this.data.knowledge_base.faq.map(pair => ({
          question: pair.question.trim().replace(/\s+/g, ' '),
          answer: pair.answer.trim().replace(/\s+/g, ' '),
        }));
        await jsonRequest(root.dataset.saveUrl, 'POST', this.data);
        const verified = await jsonRequest(root.dataset.readUrl);
        if (!verified.has_saved || !verified.payload) {
          throw new Error('A gravação não pôde ser conferida. Seus campos continuam na tela para revisão.');
        }
        const expected = this.data, actual = verified.payload;
        const comparable = value => JSON.stringify({
          name:value.business_info.agent_name,
          segment:value.business_info.segment,
          tone:value.business_info.tone_of_voice,
          questions:value.triage_config.custom_questions.map(v => v.trim().replace(/\s+/g,' ')),
          faq:value.knowledge_base.faq.map(v => ({question:v.question.trim().replace(/\s+/g,' '),answer:v.answer.trim().replace(/\s+/g,' ')})),
        });
        if (comparable(expected) !== comparable(actual)) {
          throw new Error('Os dados enviados não coincidem com os recuperados. Perguntas e respostas permanecem no formulário para conferir com o suporte.');
        }
        this.dirty = false;
        this.hasSaved = true;
        this.busy = false;
        this.openDashboard();
      } catch (error) {
        if (error.errors && typeof error.errors === 'object') {
          const first = Object.entries(error.errors)[0];
          const key = first?.[0] || '';
          const target = key.startsWith('business_info') ? 0
            : key.startsWith('commercial_info') || key.startsWith('triage_config') ? 1 : 2;
          this.busy = false;
          this.navigate(target);
          this.notice(first?.[1] || error.message);
        } else this.notice(error.message);
      } finally {
        this.busy = false; button.disabled = false; button.textContent = 'Salvar configuração';
      }
    }
    openDashboard() {
      if (this.dirty && !window.confirm('Você tem alterações que ainda não foram salvas. Deseja abrir a verificação sem salvar?')) return;
      this.showingReadiness = true; this.navigate(3);
      $('#rexReview').hidden = true;
      $('#rexReadiness').hidden = false;
      this.dashboard.load();
    }
  }

  let data;
  try { data = JSON.parse($('#rexData').textContent); }
  catch { root.replaceChildren(el('p', 'rex-notice', 'Não foi possível abrir a configuração. Recarregue a página.')); return; }
  if (!Array.isArray(data.payload?.routing_and_schedule?.business_hours)) {
    const savedHours = data.payload?.routing_and_schedule?.business_hours || {};
    const codes = {mon:'monday',tue:'tuesday',wed:'wednesday',thu:'thursday',fri:'friday',sat:'saturday',sun:'sunday'};
    data.payload.routing_and_schedule.business_hours = Object.entries(codes).flatMap(([short, day]) =>
      (Array.isArray(savedHours[short]) ? savedHours[short] : [])
        .filter(p => Array.isArray(p) && p.length === 2)
        .map(p => ({day,start:p[0],end:p[1]})));
  }
  new AgentWizard(data);
})();
