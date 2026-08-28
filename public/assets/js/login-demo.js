(() => {
  const modal = document.querySelector('[data-login-demo-modal]');
  const openButton = document.querySelector('[data-login-demo-open]');
  if (!modal || !openButton) return;

  const closeButtons = modal.querySelectorAll('[data-login-demo-close]');
  const messages = modal.querySelector('[data-demo-messages]');
  const options = modal.querySelector('[data-demo-options]');
  const stageLabel = modal.querySelector('[data-demo-stage-label]');
  const stageProgress = modal.querySelector('[data-demo-stage-progress]');
  const stageReason = modal.querySelector('[data-demo-stage-reason]');
  const restart = modal.querySelector('[data-demo-restart]');
  let activeStep = 'start';
  let timers = [];

  const flow = {
    start: {
      assistant: 'Olá! Sou a assistente virtual da RS Connect. Posso mostrar como a plataforma atende e organiza uma oportunidade comercial.',
      stage: ['Novo lead', 16, 'A conversa acabou de começar.'],
      options: [
        ['Conhecer os planos', 'plans'],
        ['Automatizar meu atendimento', 'automation'],
        ['Falar com uma pessoa', 'human']
      ]
    },
    plans: {
      user: 'Quero conhecer os planos.',
      assistant: 'Claro! Para indicar a melhor opção, quantos números de WhatsApp e atendentes sua empresa utiliza?',
      stage: ['Em qualificação', 38, 'A IA identificou interesse e iniciou a coleta da necessidade.'],
      options: [
        ['2 números e 5 atendentes', 'qualified'],
        ['Estou começando agora', 'starter']
      ]
    },
    automation: {
      user: 'Quero automatizar meu atendimento.',
      assistant: 'Ótimo. A RS Connect pode responder dúvidas, organizar filas, transferir para humanos e acompanhar oportunidades. Quantas pessoas atendem hoje?',
      stage: ['Em qualificação', 38, 'A necessidade comercial foi identificada.'],
      options: [
        ['Minha equipe tem 4 pessoas', 'qualified'],
        ['Quero ver um exemplo', 'example']
      ]
    },
    qualified: {
      user: 'Usamos 2 números e temos 5 atendentes.',
      assistant: 'Perfeito. Pelo tamanho da operação, o plano Profissional é um bom ponto de partida. Posso preparar uma proposta com os canais e a automação necessários.',
      stage: ['Qualificado', 58, 'O lead informou estrutura, canais e necessidade.'],
      options: [
        ['Pode enviar a proposta', 'proposal'],
        ['Quero entender os benefícios', 'benefits']
      ]
    },
    starter: {
      user: 'Estou começando agora.',
      assistant: 'Sem problema. O plano Inicial atende operações menores e permite começar com atendimento organizado, IA e um canal conectado.',
      stage: ['Qualificado', 52, 'A IA identificou o perfil inicial do negócio.'],
      options: [
        ['Quero uma proposta', 'proposal'],
        ['Falar com consultor', 'human']
      ]
    },
    example: {
      user: 'Quero ver um exemplo.',
      assistant: 'Imagine um cliente perguntando preço fora do horário: a IA responde, registra o interesse e deixa a conversa pronta para sua equipe continuar.',
      stage: ['Qualificado', 55, 'O interesse foi confirmado durante a demonstração.'],
      options: [
        ['Quero uma proposta', 'proposal'],
        ['Falar com consultor', 'human']
      ]
    },
    benefits: {
      user: 'Quero entender os benefícios.',
      assistant: 'Você centraliza conversas, reduz tempo de resposta, acompanha filas, integra automações e mantém o comercial organizado em um único painel.',
      stage: ['Qualificado', 62, 'O lead está avaliando a solução antes da proposta.'],
      options: [
        ['Pode enviar a proposta', 'proposal'],
        ['Preciso negociar valores', 'negotiation']
      ]
    },
    proposal: {
      user: 'Pode enviar a proposta.',
      assistant: 'Combinado. A proposta foi preparada e encaminhada ao setor comercial. Você também pode escolher um horário para uma apresentação rápida.',
      stage: ['Proposta', 76, 'A conversa indicou solicitação explícita de proposta.'],
      options: [
        ['Quero fechar', 'won'],
        ['Preciso negociar valores', 'negotiation']
      ]
    },
    negotiation: {
      user: 'Preciso negociar valores.',
      assistant: 'Tudo bem. Vou registrar essa condição e encaminhar a conversa para um consultor continuar a negociação com todo o contexto.',
      stage: ['Negociação', 88, 'O lead iniciou uma negociação comercial.'],
      options: [
        ['Condição aprovada, vamos fechar', 'won'],
        ['Falar com consultor', 'human']
      ]
    },
    human: {
      user: 'Quero falar com uma pessoa.',
      assistant: 'Sem problema. A conversa foi transferida para o Comercial com o resumo do atendimento e o interesse identificado.',
      stage: ['Atendimento humano', 70, 'A IA transferiu a conversa sem perder o contexto.'],
      options: [
        ['Simular contratação', 'won'],
        ['Recomeçar', 'start']
      ]
    },
    won: {
      user: 'Condição aprovada. Vamos fechar.',
      assistant: 'Perfeito! A oportunidade foi marcada como ganha e a equipe recebeu o próximo passo para iniciar a implantação.',
      stage: ['Ganho', 100, 'O lead confirmou a contratação de forma explícita.'],
      options: [
        ['Recomeçar demonstração', 'start']
      ]
    }
  };

  const clearTimers = () => {
    timers.forEach(clearTimeout);
    timers = [];
  };

  const escapeHtml = (value) => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const scrollMessages = () => {
    messages.scrollTop = messages.scrollHeight;
  };

  const addMessage = (type, text) => {
    const article = document.createElement('article');
    article.className = `demo-message is-${type}`;
    article.innerHTML = `<p>${escapeHtml(text)}</p><small>agora</small>`;
    messages.appendChild(article);
    requestAnimationFrame(scrollMessages);
  };

  const addTyping = () => {
    const typing = document.createElement('article');
    typing.className = 'demo-message is-assistant is-typing';
    typing.dataset.demoTyping = '1';
    typing.innerHTML = '<span></span><span></span><span></span>';
    messages.appendChild(typing);
    scrollMessages();
  };

  const removeTyping = () => {
    modal.querySelector('[data-demo-typing]')?.remove();
  };

  const setStage = ([label, percent, reason]) => {
    stageLabel.textContent = label;
    stageProgress.style.width = `${percent}%`;
    stageProgress.parentElement?.setAttribute('aria-valuenow', String(percent));
    stageReason.textContent = reason;
  };

  const renderOptions = (items) => {
    options.innerHTML = '';
    items.forEach(([label, next]) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'demo-quick-reply';
      button.textContent = label;
      button.addEventListener('click', () => go(next));
      options.appendChild(button);
    });
  };

  const go = (stepName, immediate = false) => {
    clearTimers();
    const step = flow[stepName] || flow.start;
    activeStep = stepName;
    options.innerHTML = '';

    if (stepName === 'start') {
      messages.innerHTML = '';
      setStage(step.stage);
      if (immediate) {
        addMessage('assistant', step.assistant);
        renderOptions(step.options);
      } else {
        addTyping();
        timers.push(setTimeout(() => {
          removeTyping();
          addMessage('assistant', step.assistant);
          renderOptions(step.options);
        }, 650));
      }
      return;
    }

    if (step.user) addMessage('user', step.user);
    addTyping();
    timers.push(setTimeout(() => {
      removeTyping();
      addMessage('assistant', step.assistant);
      setStage(step.stage);
      renderOptions(step.options);
    }, 850));
  };

  const open = () => {
    modal.hidden = false;
    document.body.classList.add('login-demo-open');
    openButton.setAttribute('aria-expanded', 'true');
    go('start', true);
    modal.querySelector('[data-login-demo-close]')?.focus();
  };

  const close = () => {
    clearTimers();
    modal.hidden = true;
    document.body.classList.remove('login-demo-open');
    openButton.setAttribute('aria-expanded', 'false');
    openButton.focus();
  };

  openButton.addEventListener('click', open);
  closeButtons.forEach((button) => button.addEventListener('click', close));
  restart?.addEventListener('click', () => go('start'));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) close();
  });
})();
