document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.getElementById('sidebar');
  const toggle = document.getElementById('sidebarToggle');
  const backdrop = document.getElementById('sidebarBackdrop');

  const closeSidebar = () => sidebar?.classList.remove('is-open');
  toggle?.addEventListener('click', () => sidebar?.classList.toggle('is-open'));
  backdrop?.addEventListener('click', closeSidebar);
  sidebar?.querySelectorAll('.nav-link').forEach((link) => link.addEventListener('click', closeSidebar));

  document.querySelectorAll('[data-dismiss-flash]').forEach((button) => {
    button.addEventListener('click', () => button.closest('.flash')?.remove());
  });

  window.setTimeout(() => {
    document.querySelectorAll('.flash').forEach((flash) => {
      flash.classList.add('is-hiding');
      window.setTimeout(() => flash.remove(), 250);
    });
  }, 6000);

  const thread = document.querySelector('[data-chat-thread]');
  if (thread) thread.scrollTop = thread.scrollHeight;

  const composer = document.querySelector('.chat-composer textarea');
  composer?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      if (composer.value.trim()) composer.closest('form')?.requestSubmit();
    }
  });

  document.querySelectorAll('[data-tabs]').forEach((tabs) => {
    const buttons = tabs.querySelectorAll('[data-tab-target]');
    const container = tabs.parentElement;
    buttons.forEach((button) => {
      button.addEventListener('click', () => {
        const target = button.dataset.tabTarget;
        buttons.forEach((item) => item.classList.toggle('is-active', item === button));
        container?.querySelectorAll('[data-tab-panel]').forEach((panel) => {
          panel.hidden = panel.dataset.tabPanel !== target;
        });
      });
    });
  });

  document.querySelectorAll('[data-tenant-select]').forEach((tenantSelect) => {
    const form = tenantSelect.closest('form');
    const instanceSelect = form?.querySelector('[data-instance-select]');
    if (!instanceSelect) return;

    const filterInstances = () => {
      const tenantId = tenantSelect.value;
      Array.from(instanceSelect.options).forEach((option, index) => {
        if (index === 0) return;
        const visible = tenantId === '' || option.dataset.tenantId === tenantId;
        option.hidden = !visible;
        option.disabled = !visible;
        if (!visible && option.selected) instanceSelect.selectedIndex = 0;
      });
    };

    tenantSelect.addEventListener('change', filterInstances);
    filterInstances();
  });


  const closePanel = (panel) => panel?.classList.remove('is-open');
  document.querySelectorAll('[data-toggle-panel]').forEach((button) => {
    button.addEventListener('click', () => {
      const panel = document.getElementById(button.dataset.togglePanel || '');
      panel?.classList.toggle('is-open');
    });
  });
  document.querySelectorAll('[data-close-panel]').forEach((button) => {
    button.addEventListener('click', () => closePanel(document.getElementById(button.dataset.closePanel || '')));
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeSidebar();
      document.querySelectorAll('.conversation-details.is-open').forEach((panel) => panel.classList.remove('is-open'));
    }
  });
  document.addEventListener('click', (event) => {
    document.querySelectorAll('.conversation-details.is-open').forEach((panel) => {
      const toggleClicked = event.target.closest?.('[data-toggle-panel="' + panel.id + '"]');
      if (!panel.contains(event.target) && !toggleClicked) panel.classList.remove('is-open');
    });
  });

  document.addEventListener('click', (event) => {
    document.querySelectorAll('details.action-popover[open]').forEach((details) => {
      if (!details.contains(event.target)) details.removeAttribute('open');
    });
  });
});


(function () {
  const toggle = document.querySelector('[data-toggle-bulk-read]');
  const toggleLabel = toggle?.querySelector('[data-bulk-toggle-label]');
  const form = document.querySelector('[data-bulk-read-form]');
  const cancelButton = document.querySelector('[data-cancel-bulk-select]');
  const selectAll = document.querySelector('[data-select-all-conversations]');
  const count = document.querySelector('[data-selection-count]');
  const submit = document.querySelector('[data-mark-read-button]');
  const deleteButton = document.querySelector('[data-delete-conversations-button]');
  const list = document.querySelector('[data-conversation-list]');
  if (!toggle || !form || !list) return;

  function checkboxes() {
    return Array.from(list.querySelectorAll('[data-conversation-select]'));
  }

  function isSelecting() {
    return !form.hidden;
  }

  function selectedCount() {
    return checkboxes().filter((item) => item.checked).length;
  }

  function refresh() {
    const items = checkboxes();
    const selected = items.filter((item) => item.checked).length;
    if (count) count.textContent = `${selected} selecionada${selected === 1 ? '' : 's'}`;
    if (submit) submit.disabled = selected < 1;
    if (deleteButton) deleteButton.disabled = selected < 1;
    if (selectAll) {
      selectAll.checked = items.length > 0 && selected === items.length;
      selectAll.indeterminate = selected > 0 && selected < items.length;
    }

    list.classList.toggle('is-selecting', isSelecting());
    document.body.classList.toggle('conversation-bulk-mode', isSelecting());
    items.forEach((item) => {
      item.closest('[data-conversation-row]')?.classList.toggle('is-bulk-selected', item.checked);
    });
  }

  function setSelecting(active) {
    form.hidden = !active;
    toggle.setAttribute('aria-expanded', active ? 'true' : 'false');
    toggle.classList.toggle('is-active', active);
    if (toggleLabel) toggleLabel.textContent = active ? 'Cancelar' : 'Selecionar';
    if (!active) checkboxes().forEach((item) => { item.checked = false; });
    refresh();
  }

  toggle.addEventListener('click', () => setSelecting(!isSelecting()));
  cancelButton?.addEventListener('click', () => setSelecting(false));

  selectAll?.addEventListener('change', () => {
    checkboxes().forEach((item) => { item.checked = Boolean(selectAll.checked); });
    refresh();
  });

  list.addEventListener('change', (event) => {
    if (event.target.matches?.('[data-conversation-select]')) refresh();
  });

  list.addEventListener('click', (event) => {
    if (!isSelecting()) return;
    const conversationLink = event.target.closest?.('[data-conversation-item]');
    if (!conversationLink) return;
    event.preventDefault();
    const row = conversationLink.closest('[data-conversation-row]');
    const checkbox = row?.querySelector('[data-conversation-select]');
    if (!checkbox) return;
    checkbox.checked = !checkbox.checked;
    refresh();
  });

  deleteButton?.addEventListener('click', (event) => {
    const selected = selectedCount();
    if (selected < 1) {
      event.preventDefault();
      refresh();
      return;
    }

    const confirmed = window.confirm(
      `Excluir ${selected} conversa${selected === 1 ? '' : 's'} do RS Connect?\n\n` +
      'O histórico de mensagens será apagado definitivamente. O contato, os negócios do CRM e os compromissos serão preservados sem o vínculo com a conversa.'
    );
    if (!confirmed) event.preventDefault();
  });

  form.addEventListener('submit', (event) => {
    if (selectedCount() < 1) {
      event.preventDefault();
      refresh();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isSelecting()) setSelecting(false);
  });

  refresh();
})();

(function () {
  const workspace = document.querySelector('[data-conversation-realtime]');
  if (!workspace) return;

  const pollUrl = workspace.dataset.pollUrl || '';
  const currentQuery = workspace.dataset.currentQuery || '';
  let selectedConversationId = Number(workspace.dataset.conversationId || 0);
  let lastMessageId = Number(workspace.dataset.lastMessageId || 0);
  let unreadTotal = 0;
  let timer = null;
  let isPolling = false;
  const baseTitle = workspace.dataset.baseTitle || document.title.replace(' — RS Connect', '');
  const thread = document.querySelector('[data-chat-thread]');
  const list = document.querySelector('[data-conversation-list]');
  const status = document.querySelector('[data-realtime-status]');
  const toast = document.querySelector('[data-realtime-toast]');

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function shouldStickToBottom() {
    if (!thread) return false;
    return thread.scrollHeight - thread.scrollTop - thread.clientHeight < 140;
  }

  function setStatus(text, mode) {
    if (!status) return;
    status.textContent = text;
    status.dataset.status = mode || 'ok';
  }

  function showToast(message) {
    if (!toast) return;
    toast.textContent = message;
    toast.hidden = false;
    toast.classList.add('is-visible');
    window.clearTimeout(showToast.timeout);
    showToast.timeout = window.setTimeout(() => {
      toast.classList.remove('is-visible');
      window.setTimeout(() => { toast.hidden = true; }, 220);
    }, 3800);
  }

  function pulseTitle(count) {
    document.title = count > 0 ? `(${count}) ${baseTitle} — RS Connect` : `${baseTitle} — RS Connect`;
  }

  function buildConversationUrl(id) {
    const url = new URL(window.location.href);
    url.searchParams.set('conversation_id', String(id));
    return url.pathname + url.search;
  }

  function initials(name, phone) {
    const source = String(name || phone || 'C').trim();
    return source ? source.charAt(0).toUpperCase() : 'C';
  }

  function renderConversationItem(item) {
    const unread = Number(item.unread_count || 0);
    const selectedClass = item.is_selected ? ' is-selected' : '';
    const unreadHidden = unread > 0 ? '' : ' hidden';
    const modeClass = escapeHtml(item.mode || 'ai');
    const modeLabel = item.mode === 'human' ? 'Humano' : (item.mode === 'paused' ? 'IA pausada' : 'IA ativa');
    return `<div class="conversation-list-row${unread > 0 ? ' has-unread' : ''}" data-conversation-row data-conversation-id="${Number(item.id)}">
      <label class="conversation-select-control" title="Selecionar ${escapeHtml(item.name || item.phone || 'conversa')}">
        <input type="checkbox" name="conversation_ids[]" value="${Number(item.id)}" form="conversation-bulk-read-form" data-conversation-select aria-label="Selecionar conversa de ${escapeHtml(item.name || item.phone || 'contato')}">
        <span aria-hidden="true"></span>
      </label>
      <a class="conversation-list-item${selectedClass}" data-conversation-item data-conversation-id="${Number(item.id)}" href="${escapeHtml(buildConversationUrl(item.id))}">
        <span class="conversation-avatar">${escapeHtml(initials(item.name, item.phone))}</span>
        <span class="conversation-summary">
          <span class="conversation-title-row">
            <strong data-conversation-name>${escapeHtml(item.name || item.phone || 'Contato')}</strong>
            <time data-conversation-time>${escapeHtml(item.last_message_label || '')}</time>
          </span>
          <span class="conversation-preview" data-conversation-preview>${escapeHtml(item.preview || 'Sem mensagens')}</span>
          <span class="conversation-meta-row">
            <span class="mini-badge mode-${modeClass}">${escapeHtml(modeLabel)}</span>
            <small>${escapeHtml(item.tenant_name || item.instance_label || '')}</small>
            <b class="unread-count" data-unread-count${unreadHidden}>${unread}</b>
          </span>
        </span>
      </a>
    </div>`;
  }

  function updateConversationList(conversations) {
    if (!list || !Array.isArray(conversations)) return;
    const empty = list.querySelector('.conversation-empty');
    if (empty && conversations.length > 0) empty.remove();

    conversations.slice().reverse().forEach((item) => {
      const id = Number(item.id);
      let node = list.querySelector(`[data-conversation-item][data-conversation-id="${id}"]`);
      if (!node) {
        list.insertAdjacentHTML('afterbegin', renderConversationItem(item));
        node = list.querySelector(`[data-conversation-item][data-conversation-id="${id}"]`);
      }
      node.classList.toggle('is-selected', id === selectedConversationId);
      const row = node.closest('[data-conversation-row]');
      row?.classList.toggle('has-unread', Number(item.unread_count || 0) > 0);
      const name = node.querySelector('[data-conversation-name]');
      const time = node.querySelector('[data-conversation-time]');
      const preview = node.querySelector('[data-conversation-preview]');
      const unread = node.querySelector('[data-unread-count]');
      if (name) name.textContent = item.name || item.phone || 'Contato';
      if (time) time.textContent = item.last_message_label || '';
      if (preview) preview.textContent = item.preview || 'Sem mensagens';
      if (unread) {
        unread.textContent = Number(item.unread_count || 0);
        unread.hidden = Number(item.unread_count || 0) < 1;
      }
      list.prepend(row || node);
    });
  }

  function renderMessage(message) {
    const outgoing = message.direction === 'outgoing';
    const failed = message.status === 'failed';
    const sender = outgoing ? (message.sender_type === 'ai' ? 'IA' : (message.sender_name || 'Equipe')) : '';
    const content = escapeHtml(message.content || '[Sem conteúdo]').replace(/\n/g, '<br>');
    const type = message.message_type && message.message_type !== 'text' ? `<span class="message-type">${escapeHtml(message.message_type)}</span>` : '';
    const statusText = outgoing ? `<span class="message-status">${escapeHtml(message.status || '')}</span>` : '';
    const senderText = outgoing ? `<span>${escapeHtml(sender)}</span>` : '';
    return `<article class="message-row ${outgoing ? 'is-outgoing' : 'is-incoming'}" data-message-id="${Number(message.id)}">
      <div class="message-bubble ${failed ? 'has-error' : ''}" data-sender="${escapeHtml(message.sender_type || '')}">
        ${type}
        <p>${content}</p>
        <footer>${senderText}<time>${escapeHtml(message.time_label || '')}</time>${statusText}</footer>
      </div>
    </article>`;
  }

  function appendMessages(messages) {
    if (!thread || !Array.isArray(messages) || messages.length === 0) return 0;
    const stick = shouldStickToBottom();
    let added = 0;
    messages.forEach((message) => {
      const id = Number(message.id || 0);
      if (!id || thread.querySelector(`[data-message-id="${id}"]`)) return;
      thread.insertAdjacentHTML('beforeend', renderMessage(message));
      lastMessageId = Math.max(lastMessageId, id);
      added += 1;
    });
    const empty = thread.querySelector('.chat-empty');
    if (empty && added > 0) empty.remove();
    workspace.dataset.lastMessageId = String(lastMessageId);
    thread.dataset.lastMessageId = String(lastMessageId);
    if (stick || added > 0) thread.scrollTop = thread.scrollHeight;
    return added;
  }

  async function poll() {
    if (isPolling || !pollUrl) return;
    isPolling = true;
    const params = new URLSearchParams(currentQuery);
    params.set('after_id', String(lastMessageId));
    params.set('mark_read', selectedConversationId > 0 ? '1' : '0');

    try {
      setStatus('Sincronizando...', 'loading');
      const response = await fetch(`${pollUrl}?${params.toString()}`, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store'
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const payload = await response.json();
      updateConversationList(payload.conversations || []);
      const added = appendMessages(payload.messages || []);
      unreadTotal = Number(payload.unread_total || 0);
      pulseTitle(unreadTotal);
      if (added > 0) showToast(`${added} nova(s) mensagem(ns) recebida(s).`);
      setStatus('Atualização automática ativa', 'ok');
    } catch (error) {
      setStatus('Reconectando atualização...', 'error');
    } finally {
      isPolling = false;
      schedule();
    }
  }

  function schedule() {
    window.clearTimeout(timer);
    const delay = document.hidden ? 12000 : 3500;
    timer = window.setTimeout(poll, delay);
  }

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) poll();
  });

  schedule();
})();

(function () {
  const modal = document.querySelector('[data-qr-code-modal]');
  const forms = document.querySelectorAll('[data-qr-code-form]');
  if (!modal || forms.length === 0) return;

  const image = modal.querySelector('[data-qr-image]');
  const loading = modal.querySelector('[data-qr-loading]');
  const errorBox = modal.querySelector('[data-qr-error]');
  const message = modal.querySelector('[data-qr-message]');

  function openModal() {
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('has-modal-open');
  }

  function closeModal() {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('has-modal-open');
  }

  function resetModal() {
    if (image) {
      image.hidden = true;
      image.removeAttribute('src');
    }
    if (loading) {
      loading.hidden = false;
      loading.textContent = 'Gerando QR Code com segurança...';
    }
    if (errorBox) {
      errorBox.hidden = true;
      errorBox.textContent = '';
    }
    if (message) message.hidden = false;
  }

  modal.querySelectorAll('[data-close-qr-modal]').forEach((button) => button.addEventListener('click', closeModal));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) closeModal();
  });

  forms.forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      resetModal();
      openModal();
      const button = form.querySelector('[data-qr-code-button]');
      const originalText = button?.textContent || 'Gerar QR Code';
      if (button) {
        button.disabled = true;
        button.textContent = 'Gerando...';
      }

      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          }
        });
        const contentType = response.headers.get('content-type') || '';
        const payload = contentType.includes('application/json')
          ? await response.json()
          : { ok: false, message: 'O servidor não retornou um QR Code válido.' };

        if (!response.ok || !payload.ok || !payload.qr_code) {
          throw new Error(payload.message || 'Não foi possível gerar o QR Code.');
        }

        if (loading) loading.hidden = true;
        if (image) {
          image.src = payload.qr_code;
          image.hidden = false;
        }
      } catch (error) {
        if (loading) loading.hidden = true;
        if (message) message.hidden = true;
        if (errorBox) {
          errorBox.textContent = error.message || 'Não foi possível gerar o QR Code.';
          errorBox.hidden = false;
        }
      } finally {
        if (button) {
          button.disabled = false;
          button.textContent = originalText;
        }
      }
    });
  });
})();

(function () {
  const links = Array.from(document.querySelectorAll('[data-notification-link]'));
  if (links.length < 1) return;

  const countUrl = links.find((link) => link.dataset.countUrl)?.dataset.countUrl || '';
  if (!countUrl) return;

  const badges = () => Array.from(document.querySelectorAll('[data-notification-badge]'));
  const storageKey = 'rs-connect-notification-latest-id';
  let initialized = false;
  let lastId = Number(window.sessionStorage.getItem(storageKey) || 0);
  let polling = false;

  function updateBadges(count) {
    const value = Math.max(0, Number(count || 0));
    badges().forEach((badge) => {
      badge.textContent = String(Math.min(99, value));
      badge.hidden = value < 1;
      badge.closest('[data-notification-link]')?.setAttribute(
        'aria-label',
        value > 0 ? `Notificações: ${value} nova${value === 1 ? '' : 's'}` : 'Notificações'
      );
    });
  }

  function showNotificationToast(notification) {
    if (!notification || !notification.title) return;

    let toast = document.querySelector('[data-notification-live-toast]');
    if (!toast) {
      toast = document.createElement('a');
      toast.className = 'notification-live-toast';
      toast.dataset.notificationLiveToast = '';
      toast.innerHTML = '<span class="notification-live-toast-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg></span><span class="notification-live-toast-copy"><strong></strong><small></small></span>';
      document.body.appendChild(toast);
    }

    const title = toast.querySelector('strong');
    const message = toast.querySelector('small');
    if (title) title.textContent = notification.title || 'Nova notificação';
    if (message) message.textContent = notification.message || 'Abra para ver os detalhes.';
    toast.href = notification.action_url || links[0].href;
    toast.classList.add('is-visible');

    window.clearTimeout(showNotificationToast.timeout);
    showNotificationToast.timeout = window.setTimeout(() => {
      toast.classList.remove('is-visible');
    }, 6500);
  }

  async function pollNotifications() {
    if (polling) return;
    polling = true;
    try {
      const response = await fetch(countUrl, {
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        cache: 'no-store'
      });
      if (!response.ok) return;

      const payload = await response.json();
      if (!payload || !payload.ok) return;
      updateBadges(payload.count);

      const latest = payload.latest || null;
      const latestId = Number(latest?.id || 0);
      if (!initialized) {
        initialized = true;
        if (latestId > lastId) {
          lastId = latestId;
          window.sessionStorage.setItem(storageKey, String(lastId));
        }
        return;
      }

      if (latestId > lastId) {
        lastId = latestId;
        window.sessionStorage.setItem(storageKey, String(lastId));
        showNotificationToast(latest);
        links.forEach((link) => {
          link.classList.remove('has-new-notification');
          void link.offsetWidth;
          link.classList.add('has-new-notification');
        });
      }
    } catch (error) {
      // O sininho continua funcional pelo carregamento normal caso o polling falhe.
    } finally {
      polling = false;
    }
  }

  pollNotifications();
  window.setInterval(() => {
    if (document.visibilityState === 'visible') pollNotifications();
  }, 10000);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') pollNotifications();
  });
})();

(function () {
  document.querySelectorAll('.notification-preference-option input[type="checkbox"]').forEach((input) => {
    input.addEventListener('change', () => {
      input.closest('.notification-preference-option')?.classList.toggle('is-enabled', input.checked);
    });
  });
})();

/* =========================================================
   ZIP 32.2 — Credenciais de IA com gaveta e filtros
   ========================================================= */
document.addEventListener('DOMContentLoaded', () => {
  const drawer = document.getElementById('ai-credential-drawer');
  const form = drawer?.querySelector('[data-ai-credential-form]');
  if (!drawer || !form) return;

  const field = (name) => form.querySelector(`[data-ai-field="${name}"]`);
  const title = drawer.querySelector('[data-ai-credential-drawer-title]');
  const eyebrow = drawer.querySelector('[data-ai-credential-drawer-eyebrow]');
  const description = drawer.querySelector('[data-ai-credential-drawer-description]');
  const submit = drawer.querySelector('[data-ai-credential-submit]');
  const tenantSelect = form.querySelector('[data-ai-credential-tenant]');
  const scopeSelect = form.querySelector('[data-ai-credential-scope]');
  const agentSelect = form.querySelector('[data-ai-credential-agent]');
  const agentField = form.querySelector('[data-ai-agent-field]');
  const providerSelect = form.querySelector('[data-ai-credential-provider]');
  const apiKeyInput = field('api_key');
  const apiKeyHint = drawer.querySelector('[data-ai-api-key-hint]');

  const filterAgents = (selectedAgentId = '') => {
    const tenantId = tenantSelect?.value || '';
    let available = 0;
    Array.from(agentSelect?.options || []).forEach((option, index) => {
      if (index === 0) return;
      const visible = tenantId !== '' && option.dataset.tenantId === tenantId;
      option.hidden = !visible;
      option.disabled = !visible;
      if (visible) available += 1;
    });

    if (selectedAgentId && agentSelect?.querySelector(`option[value="${CSS.escape(String(selectedAgentId))}"]:not([disabled])`)) {
      agentSelect.value = String(selectedAgentId);
    } else if (agentSelect) {
      agentSelect.value = '0';
    }

    if (scopeSelect?.value === 'agent' && available === 0) {
      scopeSelect.value = 'company';
    }
    toggleScope();
  };

  const toggleScope = () => {
    const useAgent = scopeSelect?.value === 'agent';
    if (agentField) agentField.hidden = !useAgent;
    if (agentSelect) {
      agentSelect.required = useAgent;
      if (!useAgent) agentSelect.value = '0';
    }
  };

  const providerDefaults = (force = false) => {
    const provider = providerSelect?.value || 'openai';
    const modelInput = field('default_model');
    const baseInput = field('base_url');
    const models = { openai: 'gpt-4o-mini', google: 'gemini-2.0-flash', custom: '' };
    if (force || !modelInput?.value.trim()) modelInput.value = models[provider] || '';
    if (force && baseInput) baseInput.value = '';
  };

  const resetForm = () => {
    form.reset();
    field('id').value = '0';
    tenantSelect.value = '';
    scopeSelect.value = 'company';
    agentSelect.value = '0';
    field('provider').value = 'openai';
    field('status').value = 'active';
    field('is_default').checked = true;
    field('default_model').value = 'gpt-4o-mini';
    field('base_url').value = '';
    apiKeyInput.required = true;
    apiKeyInput.value = '';
    if (eyebrow) eyebrow.textContent = 'Nova credencial';
    if (title) title.textContent = 'Cadastrar acesso à IA';
    if (description) description.textContent = 'Defina quem usará a chave e configure somente as informações necessárias.';
    if (submit) submit.textContent = 'Salvar credencial';
    if (apiKeyHint) apiKeyHint.textContent = 'Informe a chave fornecida pelo provedor. Depois de salvar, ela não será exibida novamente.';
    filterAgents();
  };

  const fillEditForm = (button) => {
    form.reset();
    field('id').value = button.dataset.id || '0';
    tenantSelect.value = button.dataset.tenantId || '';
    const agentId = button.dataset.agentId || '0';
    scopeSelect.value = agentId !== '0' ? 'agent' : 'company';
    field('label').value = button.dataset.label || '';
    field('provider').value = button.dataset.provider || 'openai';
    field('base_url').value = button.dataset.baseUrl || '';
    field('default_model').value = button.dataset.defaultModel || '';
    field('status').value = button.dataset.status || 'active';
    field('is_default').checked = button.dataset.isDefault === '1';
    apiKeyInput.value = '';
    apiKeyInput.required = false;
    if (eyebrow) eyebrow.textContent = 'Editar credencial';
    if (title) title.textContent = button.dataset.label || 'Atualizar acesso à IA';
    if (description) description.textContent = 'Atualize o vínculo, modelo ou situação. A chave atual será mantida se o campo ficar vazio.';
    if (submit) submit.textContent = 'Salvar alterações';
    if (apiKeyHint) apiKeyHint.textContent = 'Deixe em branco para manter a chave atual. Preencha somente para substituí-la.';
    filterAgents(agentId);
  };

  document.querySelectorAll('[data-ai-credential-open]').forEach((button) => {
    button.addEventListener('click', () => {
      if (button.dataset.aiCredentialOpen === 'edit') fillEditForm(button);
      else resetForm();
    });
  });

  tenantSelect?.addEventListener('change', () => filterAgents());
  scopeSelect?.addEventListener('change', toggleScope);
  providerSelect?.addEventListener('change', () => providerDefaults(true));
  resetForm();

  const searchInput = document.querySelector('[data-ai-credential-search]');
  const providerFilter = document.querySelector('[data-ai-credential-provider-filter]');
  const statusFilter = document.querySelector('[data-ai-credential-status-filter]');
  const clearButton = document.querySelector('[data-ai-credential-clear]');
  const visibleCount = document.querySelector('[data-ai-credential-visible-count]');
  const filterEmpty = document.querySelector('[data-ai-credential-filter-empty]');
  const cards = Array.from(document.querySelectorAll('[data-ai-credential-card]'));

  const normalize = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const applyFilters = () => {
    const query = normalize(searchInput?.value);
    const provider = providerFilter?.value || '';
    const status = statusFilter?.value || '';
    let shown = 0;
    cards.forEach((card) => {
      const matches = (!query || normalize(card.dataset.search).includes(query))
        && (!provider || card.dataset.provider === provider)
        && (!status || card.dataset.status === status);
      card.hidden = !matches;
      if (matches) shown += 1;
    });
    if (visibleCount) visibleCount.textContent = `${shown} registro(s)`;
    if (filterEmpty) filterEmpty.hidden = shown !== 0 || cards.length === 0;
  };

  searchInput?.addEventListener('input', applyFilters);
  providerFilter?.addEventListener('change', applyFilters);
  statusFilter?.addEventListener('change', applyFilters);
  clearButton?.addEventListener('click', () => {
    if (searchInput) searchInput.value = '';
    if (providerFilter) providerFilter.value = '';
    if (statusFilter) statusFilter.value = '';
    applyFilters();
    searchInput?.focus();
  });
});


/* =========================================================
   ZIP 32.3 — UX dos centros administrativos
   ========================================================= */
document.addEventListener('DOMContentLoaded', () => {
  const normalize = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  document.querySelectorAll('[data-admin-filter-root]').forEach((root) => {
    const scope = root.closest('.admin-module-panel') || document;
    const cards = Array.from(scope.querySelectorAll('[data-admin-card]'));
    const search = root.querySelector('[data-admin-search]');
    const filters = Array.from(root.querySelectorAll('[data-admin-filter]'));
    const clear = root.querySelector('[data-admin-clear]');
    const count = scope.querySelector('[data-admin-visible-count]');
    const empty = scope.querySelector('[data-admin-filter-empty]');
    const apply = () => {
      const query = normalize(search?.value);
      let shown = 0;
      cards.forEach((card) => {
        let matches = !query || normalize(card.dataset.search).includes(query);
        filters.forEach((filter) => {
          const value = filter.value || '';
          const key = filter.dataset.adminFilter || '';
          if (value && String(card.dataset[key] || '') !== value) matches = false;
        });
        card.hidden = !matches;
        if (matches) shown += 1;
      });
      if (count) count.textContent = `${shown} registro(s)`;
      if (empty) empty.hidden = shown !== 0 || cards.length === 0;
    };
    search?.addEventListener('input', apply);
    filters.forEach((filter) => filter.addEventListener('change', apply));
    clear?.addEventListener('click', () => { if (search) search.value = ''; filters.forEach((filter) => { filter.value = ''; }); apply(); search?.focus(); });
  });

  const instanceDrawer = document.getElementById('instance-drawer');
  const instanceForm = instanceDrawer?.querySelector('[data-instance-form]');
  if (instanceForm) {
    const field = (name) => instanceForm.querySelector(`[data-instance-field="${name}"]`);
    const reset = () => { instanceForm.reset(); instanceForm.action = `${window.location.origin}/instances`; field('id').value='0'; field('base_url').value=field('base_url').defaultValue || ''; field('api_key').required=true; instanceDrawer.querySelector('[data-instance-drawer-eyebrow]').textContent='Nova conexão'; instanceDrawer.querySelector('[data-instance-drawer-title]').textContent='Configurar WhatsApp'; instanceDrawer.querySelector('[data-instance-drawer-description]').textContent='Cadastre a conexão preparada na Evolution API.'; instanceDrawer.querySelector('[data-instance-api-hint]').textContent='Obrigatória no primeiro cadastro.'; instanceDrawer.querySelector('[data-instance-submit]').textContent='Salvar conexão'; instanceDrawer.querySelector('[data-instance-tenant-field]').hidden=false; field('tenant_id').required=true; };
    document.querySelectorAll('[data-instance-open]').forEach((button)=>button.addEventListener('click',()=>{ if(button.dataset.instanceOpen==='edit'){ instanceForm.reset(); instanceForm.action=`${window.location.origin}/instances/update`; field('id').value=button.dataset.id||'0'; field('name').value=button.dataset.name||''; field('instance_name').value=button.dataset.instanceName||''; field('base_url').value=button.dataset.baseUrl||''; field('status').value=button.dataset.status||'disconnected'; field('is_default').checked=button.dataset.isDefault==='1'; field('api_key').value=''; field('api_key').required=false; instanceDrawer.querySelector('[data-instance-tenant-field]').hidden=true; field('tenant_id').required=false; instanceDrawer.querySelector('[data-instance-drawer-eyebrow]').textContent='Editar conexão'; instanceDrawer.querySelector('[data-instance-drawer-title]').textContent=button.dataset.name||'Atualizar conexão'; instanceDrawer.querySelector('[data-instance-drawer-description]').textContent='Atualize o cadastro sem perder os vínculos existentes.'; instanceDrawer.querySelector('[data-instance-api-hint]').textContent='Deixe em branco para manter a chave atual.'; instanceDrawer.querySelector('[data-instance-submit]').textContent='Salvar alterações'; } else reset(); }));
    reset();
  }
  document.querySelectorAll('[data-instance-delete]').forEach((button)=>button.addEventListener('click',()=>{ const form=document.querySelector('[data-instance-delete-form]'); if(!form)return; const id=form.querySelector('[data-instance-delete-field="id"]'); const replacement=form.querySelector('[data-instance-delete-field="replacement"]'); const confirmation=form.querySelector('[data-instance-delete-field="confirmation"]'); const name=document.querySelector('[data-instance-delete-name]'); const hint=document.querySelector('[data-instance-delete-hint]'); if(id)id.value=button.dataset.id||''; if(name)name.textContent=button.dataset.name||'Conexão'; if(confirmation){confirmation.value='';confirmation.placeholder=`EXCLUIR ${button.dataset.instanceName||''}`;} if(hint)hint.innerHTML=`Digite exatamente: <strong>EXCLUIR ${button.dataset.instanceName||''}</strong>`; Array.from(replacement?.options||[]).forEach((option,index)=>{if(index===0)return; const visible=option.dataset.tenantId===button.dataset.tenantId && option.value!==button.dataset.id; option.hidden=!visible; option.disabled=!visible;}); if(replacement)replacement.value=''; }));

  const setupSimpleDrawer = (config) => {
    const drawer=document.getElementById(config.drawer); const form=drawer?.querySelector(config.form); if(!drawer||!form)return;
    const field=(name)=>form.querySelector(`[${config.attr}="${name}"]`);
    document.querySelectorAll(config.buttons).forEach((button)=>button.addEventListener('click',()=>config.fill({button,drawer,form,field})));
  };
  setupSimpleDrawer({drawer:'n8n-flow-drawer',form:'[data-n8n-form]',buttons:'[data-n8n-open]',attr:'data-n8n-field',fill:({button,drawer,form,field})=>{form.reset();field('id').value='0';field('status').value='active';form.querySelectorAll('input[name="events[]"]').forEach((input)=>{input.checked=input.value==='*';});const edit=button.dataset.n8nOpen==='edit';if(edit){field('id').value=button.dataset.id||'0';field('tenant_id').value=button.dataset.tenantId||'';field('flow_key').value=button.dataset.flowKey||'';field('name').value=button.dataset.name||'';field('description').value=button.dataset.description||'';field('status').value=button.dataset.status||'active';try{const events=JSON.parse(decodeURIComponent(button.dataset.events||'%5B%5D'));form.querySelectorAll('input[name="events[]"]').forEach((input)=>input.checked=events.includes(input.value));}catch(e){} drawer.querySelector('[data-n8n-eyebrow]').textContent='Editar fluxo';drawer.querySelector('[data-n8n-title]').textContent=button.dataset.name||'Atualizar automação';drawer.querySelector('[data-n8n-description]').textContent='A URL e o token atuais serão mantidos quando os campos ficarem vazios.';drawer.querySelector('[data-n8n-url-hint]').textContent='Deixe em branco para manter a URL atual.';drawer.querySelector('[data-n8n-submit]').textContent='Salvar alterações';}else{drawer.querySelector('[data-n8n-eyebrow]').textContent='Novo fluxo';drawer.querySelector('[data-n8n-title]').textContent='Configurar automação';drawer.querySelector('[data-n8n-description]').textContent='Defina a empresa, o webhook e quando este fluxo deve ser acionado.';drawer.querySelector('[data-n8n-url-hint]').textContent='Obrigatória no primeiro cadastro.';drawer.querySelector('[data-n8n-submit]').textContent='Salvar fluxo';}}});
  setupSimpleDrawer({drawer:'plan-drawer',form:'[data-plan-form]',buttons:'[data-plan-open]',attr:'data-plan-field',fill:({button,drawer,form,field})=>{form.reset();field('id').value='0';field('status').value='active';field('sort_order').value='50';form.querySelectorAll('[data-plan-limit]').forEach((input)=>input.value='');const edit=button.dataset.planOpen==='edit';if(edit){field('id').value=button.dataset.id||'0';field('plan_key').value=button.dataset.planKey||'';field('name').value=button.dataset.name||'';field('description').value=button.dataset.description||'';field('monthly_price').value=button.dataset.price||'';field('status').value=button.dataset.status||'active';field('sort_order').value=button.dataset.sortOrder||'50';field('features').value=decodeURIComponent(button.dataset.features||'');try{const limits=JSON.parse(decodeURIComponent(button.dataset.limits||'%7B%7D'));Object.entries(limits).forEach(([key,value])=>{const input=form.querySelector(`[data-plan-limit="${CSS.escape(key)}"]`);if(input)input.value=value??'';});}catch(e){}drawer.querySelector('[data-plan-eyebrow]').textContent='Editar plano';drawer.querySelector('[data-plan-title]').textContent=button.dataset.name||'Atualizar plano';drawer.querySelector('[data-plan-submit]').textContent='Salvar alterações';}else{drawer.querySelector('[data-plan-eyebrow]').textContent='Novo plano';drawer.querySelector('[data-plan-title]').textContent='Criar pacote comercial';drawer.querySelector('[data-plan-submit]').textContent='Salvar plano';}}});
  setupSimpleDrawer({drawer:'subscription-drawer',form:'[data-subscription-form]',buttons:'[data-subscription-open]',attr:'data-subscription-field',fill:({button,drawer,form,field})=>{
    form.reset();
    field('subscription_id').value='0';
    field('billing_status').value='active';
    field('billing_cycle').value='monthly';
    const now=new Date();
    const pad=(value)=>String(value).padStart(2,'0');
    const first=`${now.getFullYear()}-${pad(now.getMonth()+1)}-01`;
    const lastDate=new Date(now.getFullYear(),now.getMonth()+1,0);
    const last=`${lastDate.getFullYear()}-${pad(lastDate.getMonth()+1)}-${pad(lastDate.getDate())}`;
    field('current_period_starts_at').value=first;
    field('current_period_ends_at').value=last;
    const note=drawer.querySelector('[data-subscription-access-note]');
    if(note){note.hidden=true;note.textContent='';}
    const edit=button.dataset.subscriptionOpen==='edit';
    if(edit){
      field('subscription_id').value=button.dataset.subscriptionId||'0';
      field('tenant_id').value=button.dataset.tenantId||'';
      field('plan_id').value=button.dataset.planId||'';
      field('billing_status').value=button.dataset.billingStatus||'active';
      field('billing_cycle').value=button.dataset.billingCycle||'monthly';
      field('amount').value=button.dataset.amount||'';
      field('current_period_starts_at').value=button.dataset.periodStart||first;
      field('current_period_ends_at').value=button.dataset.periodEnd||last;
      field('next_billing_at').value=button.dataset.nextBilling||'';
      field('trial_ends_at').value=button.dataset.trialEnd||'';
      field('notes').value=decodeURIComponent(button.dataset.notes||'');
      drawer.querySelector('[data-subscription-eyebrow]').textContent='Editar vigência';
      drawer.querySelector('[data-subscription-title]').textContent=button.dataset.tenantName||'Atualizar assinatura';
      drawer.querySelector('[data-subscription-description]').textContent='Altere a data final, o plano ou a situação e salve para recalcular o acesso imediatamente.';
      drawer.querySelector('[data-subscription-submit]').textContent='Salvar e recalcular acesso';
      const accessMessage=decodeURIComponent(button.dataset.accessMessage||'');
      if(note&&accessMessage){note.textContent=accessMessage;note.hidden=false;}
    }else{
      drawer.querySelector('[data-subscription-eyebrow]').textContent='Nova assinatura';
      drawer.querySelector('[data-subscription-title]').textContent='Vincular plano';
      drawer.querySelector('[data-subscription-description]').textContent='Defina o plano e o primeiro período de acesso da empresa.';
      drawer.querySelector('[data-subscription-submit]').textContent='Salvar assinatura';
    }
  }});
  const autoSubscription=document.querySelector('[data-subscription-auto-open="1"]');
  if(autoSubscription){
    document.querySelector('[data-tab-target="subscriptions"]')?.click();
    window.setTimeout(()=>autoSubscription.click(),40);
  }

  document.querySelectorAll('[data-invoice-open]').forEach((button)=>button.addEventListener('click',()=>{const form=document.querySelector('[data-invoice-form]');if(!form)return;form.querySelector('[data-invoice-field="tenant_id"]').value=button.dataset.tenantId||'';form.querySelector('[data-invoice-field="subscription_id"]').value=button.dataset.subscriptionId||'';form.querySelector('[data-invoice-field="amount"]').value=button.dataset.amount||'';const title=document.querySelector('[data-invoice-title]');if(title)title.textContent=`Criar cobrança — ${button.dataset.tenantName||''}`;}));
  document.querySelectorAll('[data-payment-link-open]').forEach((button)=>button.addEventListener('click',()=>{const select=document.querySelector('[data-payment-link-invoice]');if(select&&button.dataset.invoiceId)select.value=button.dataset.invoiceId;}));
  document.querySelectorAll('[data-copy-value]').forEach((button)=>button.addEventListener('click',async()=>{const value=button.dataset.copyValue||'';if(!value)return;try{await navigator.clipboard.writeText(value);const original=button.textContent;button.textContent='Link copiado';window.setTimeout(()=>button.textContent=original,1800);}catch(error){window.prompt('Copie o link:',value);}}));
  setupSimpleDrawer({drawer:'gateway-drawer',form:'[data-gateway-form]',buttons:'[data-gateway-open]',attr:'data-gateway-field',fill:({button,drawer,form,field})=>{form.reset();field('id').value='0';field('environment').value='production';field('status').value='active';field('is_default').checked=true;const edit=button.dataset.gatewayOpen==='edit';if(edit){field('id').value=button.dataset.id||'0';field('label').value=button.dataset.label||'';field('provider').value=button.dataset.provider||'manual';field('environment').value=button.dataset.environment||'production';field('status').value=button.dataset.status||'active';field('api_base_url').value=button.dataset.apiBaseUrl||'';field('public_key').value=button.dataset.publicKey||'';field('method').value=button.dataset.method||'UNDEFINED';field('is_default').checked=button.dataset.isDefault==='1';field('notes').value=decodeURIComponent(button.dataset.notes||'');field('api_key').value='';field('webhook_secret').value='';drawer.querySelector('[data-gateway-eyebrow]').textContent='Editar gateway';drawer.querySelector('[data-gateway-title]').textContent=button.dataset.label||'Atualizar gateway';drawer.querySelector('[data-gateway-key-hint]').textContent='Deixe em branco para manter a chave atual.';drawer.querySelector('[data-gateway-submit]').textContent='Salvar alterações';}else{drawer.querySelector('[data-gateway-eyebrow]').textContent='Novo gateway';drawer.querySelector('[data-gateway-title]').textContent='Configurar pagamento';drawer.querySelector('[data-gateway-key-hint]').textContent='Informe a chave do provedor.';drawer.querySelector('[data-gateway-submit]').textContent='Salvar gateway';}}});
  setupSimpleDrawer({drawer:'reminder-drawer',form:'[data-reminder-form]',buttons:'[data-reminder-open]',attr:'data-reminder-field',fill:({button,drawer,form,field})=>{form.reset();field('id').value='0';field('days_from_due').value='-3';field('status').value='active';field('message_template').value='Olá, {{empresa}}. Sua cobrança {{invoice_number}} no valor de {{valor}} vence em {{vencimento}}. Link: {{link_pagamento}}';const edit=button.dataset.reminderOpen==='edit';if(edit){field('id').value=button.dataset.id||'0';field('label').value=button.dataset.label||'';field('days_from_due').value=button.dataset.days||'0';field('status').value=button.dataset.status||'active';field('event_key').value=button.dataset.eventKey||'';field('channel').value=button.dataset.channel||'';field('auto_mark_overdue').checked=button.dataset.autoOverdue==='1';field('auto_suspend').checked=button.dataset.autoSuspend==='1';field('message_template').value=decodeURIComponent(button.dataset.message||'');drawer.querySelector('[data-reminder-eyebrow]').textContent='Editar regra';drawer.querySelector('[data-reminder-title]').textContent=button.dataset.label||'Atualizar aviso';drawer.querySelector('[data-reminder-submit]').textContent='Salvar alterações';}else{drawer.querySelector('[data-reminder-eyebrow]').textContent='Nova regra';drawer.querySelector('[data-reminder-title]').textContent='Criar aviso automático';drawer.querySelector('[data-reminder-submit]').textContent='Salvar regra';}}});
  setupSimpleDrawer({drawer:'user-drawer',form:'[data-user-form]',buttons:'[data-user-open]',attr:'data-user-field',fill:({button,drawer,form,field})=>{form.reset();form.action=`${window.location.origin}/users`;field('id').value='0';field('tenant_id').value='global';field('role').value='super_admin';field('password').required=true;drawer.querySelector('[data-user-status-field]').hidden=true;const edit=button.dataset.userOpen==='edit';if(edit){form.action=`${window.location.origin}/users/update`;field('id').value=button.dataset.id||'0';field('tenant_id').value=button.dataset.tenantId||'global';field('name').value=button.dataset.name||'';field('email').value=button.dataset.email||'';field('role').value=button.dataset.role||'client_user';field('status').value=button.dataset.status||'active';field('password').value='';field('password').required=false;drawer.querySelector('[data-user-status-field]').hidden=false;drawer.querySelector('[data-user-eyebrow]').textContent='Editar usuário';drawer.querySelector('[data-user-title]').textContent=button.dataset.name||'Atualizar acesso';drawer.querySelector('[data-user-description]').textContent='Altere perfil, situação ou senha sem recriar o usuário.';drawer.querySelector('[data-user-password-hint]').textContent='Deixe em branco para manter a senha atual.';drawer.querySelector('[data-user-submit]').textContent='Salvar alterações';}else{drawer.querySelector('[data-user-eyebrow]').textContent='Novo usuário';drawer.querySelector('[data-user-title]').textContent='Criar acesso';drawer.querySelector('[data-user-description]').textContent='Defina a empresa, o perfil e os dados de entrada.';drawer.querySelector('[data-user-password-hint]').textContent='Obrigatória no primeiro cadastro.';drawer.querySelector('[data-user-submit]').textContent='Salvar usuário';}}});

  const permissionSearch=document.querySelector('[data-permission-search]');
  if(permissionSearch){const groups=Array.from(document.querySelectorAll('[data-permission-group]'));const apply=()=>{const q=normalize(permissionSearch.value);groups.forEach((group)=>{let shown=0;group.querySelectorAll('[data-permission-row]').forEach((row)=>{const visible=!q||normalize(row.dataset.search).includes(q);row.hidden=!visible;if(visible)shown++;});group.hidden=shown===0&&!normalize(group.dataset.search).includes(q);if(q&&shown>0)group.open=true;});};permissionSearch.addEventListener('input',apply);}
  document.querySelectorAll('[data-permission-set]').forEach((button)=>button.addEventListener('click',()=>{const role=button.dataset.permissionSet;const checked=button.dataset.value==='1';document.querySelectorAll(`[data-permission-role="${role}"]`).forEach((input)=>{if(!input.disabled)input.checked=checked;});}));
  document.querySelectorAll('[data-permission-category]').forEach((button)=>button.addEventListener('click',()=>{const group=button.closest('[data-permission-group]');const role=button.dataset.permissionCategory;const checked=button.dataset.value==='1';group?.querySelectorAll(`[data-permission-role="${role}"]`).forEach((input)=>{if(!input.disabled)input.checked=checked;});}));
});


/* ZIP 33.2 — preferências do menu do cliente */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.client-menu-option input[type="checkbox"]').forEach((input) => {
    input.addEventListener('change', () => input.closest('.client-menu-option')?.classList.toggle('is-visible', input.checked));
  });
});

/* ZIP 34.2 — movimentação do CRM sem recarregar a tela */
(function () {
  const boards = Array.from(document.querySelectorAll('[data-crm-board]'));
  if (!boards.length) return;

  let toastTimer = null;
  const toast = (message, error = false) => {
    let element = document.querySelector('.crm-ajax-toast');
    if (!element) {
      element = document.createElement('div');
      element.className = 'crm-ajax-toast';
      element.setAttribute('role', 'status');
      document.body.appendChild(element);
    }
    element.textContent = message;
    element.classList.toggle('is-error', error);
    element.classList.add('is-visible');
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => element.classList.remove('is-visible'), 3200);
  };

  const formatMoney = (value) => new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(Number(value || 0));

  const refreshStage = (stage) => {
    if (!stage) return;
    const cards = Array.from(stage.querySelectorAll(':scope [data-crm-dropzone] > [data-crm-card]'));
    const counter = stage.querySelector('[data-stage-count]');
    const empty = stage.querySelector('[data-crm-empty]');
    const value = stage.querySelector('[data-stage-value]');
    if (counter) counter.textContent = String(cards.length);
    if (empty) empty.hidden = cards.length > 0;
    if (value) {
      const total = cards.reduce((sum, card) => sum + Number(card.dataset.dealValue || 0), 0);
      value.textContent = formatMoney(total);
    }
  };

  const refreshMetrics = (metrics) => {
    if (!metrics || typeof metrics !== 'object') return;
    document.querySelectorAll('[data-crm-metric]').forEach((card) => {
      const key = card.dataset.crmMetric || '';
      const target = card.querySelector('[data-crm-metric-value]');
      if (!key || !target || !(key in metrics)) return;
      const raw = metrics[key];
      target.textContent = card.dataset.crmMetricFormat === 'money'
        ? formatMoney(raw)
        : String(Number(raw || 0));
    });
  };

  const moveRequest = async (board, card, stageId, rollback) => {
    const kind = board.dataset.crmKind || 'client';
    const payload = new FormData();
    payload.set('_token', board.dataset.csrf || '');
    payload.set('stage_id', String(stageId));
    payload.set(kind === 'admin' ? 'opportunity_id' : 'lead_id', card.dataset.itemId || '0');
    if (kind === 'client') payload.set('tenant_id', board.dataset.tenantId || '0');

    card.classList.add('is-saving');
    try {
      const response = await fetch(board.dataset.moveUrl || '', {
        method: 'POST',
        body: payload,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) throw new Error(data.message || 'Não foi possível salvar a nova etapa.');
      card.dataset.currentStage = String(stageId);
      card.querySelectorAll('[data-crm-stage-select]').forEach((select) => { select.value = String(stageId); });
      refreshMetrics(data.metrics);
      toast(data.message || 'Etapa atualizada.');
      return true;
    } catch (error) {
      rollback?.();
      card.classList.add('is-move-error');
      window.setTimeout(() => card.classList.remove('is-move-error'), 420);
      toast(error instanceof Error ? error.message : 'Não foi possível mover o card.', true);
      return false;
    } finally {
      card.classList.remove('is-saving');
    }
  };

  const insertBeforeForPointer = (zone, y, dragging) => {
    const cards = Array.from(zone.querySelectorAll(':scope > [data-crm-card]:not(.is-dragging)'));
    let closest = { offset: Number.NEGATIVE_INFINITY, element: null };
    cards.forEach((card) => {
      const rect = card.getBoundingClientRect();
      const offset = y - rect.top - rect.height / 2;
      if (offset < 0 && offset > closest.offset) closest = { offset, element: card };
    });
    if (closest.element) zone.insertBefore(dragging, closest.element);
    else zone.appendChild(dragging);
  };

  boards.forEach((board) => {
    let dragging = null;
    let originalZone = null;
    let originalNext = null;

    const prepareCard = (card) => {
      if (card.getAttribute('draggable') !== 'true') return;
      card.addEventListener('dragstart', (event) => {
        dragging = card;
        originalZone = card.parentElement;
        originalNext = card.nextElementSibling;
        card.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', card.dataset.itemId || '');
      });
      card.addEventListener('dragend', () => {
        card.classList.remove('is-dragging');
        board.querySelectorAll('[data-crm-dropzone]').forEach((zone) => zone.classList.remove('is-drag-over'));
        dragging = null;
        originalZone = null;
        originalNext = null;
      });
    };

    board.querySelectorAll('[data-crm-card]').forEach(prepareCard);

    board.querySelectorAll('[data-crm-dropzone]').forEach((zone) => {
      zone.addEventListener('dragover', (event) => {
        if (!dragging) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        board.querySelectorAll('[data-crm-dropzone]').forEach((item) => item.classList.toggle('is-drag-over', item === zone));
        insertBeforeForPointer(zone, event.clientY, dragging);
      });
      zone.addEventListener('drop', async (event) => {
        if (!dragging) return;
        event.preventDefault();
        const card = dragging;
        const oldZone = originalZone;
        const next = originalNext;
        const targetStage = zone.closest('[data-crm-stage]');
        const targetStageId = Number(targetStage?.dataset.stageId || 0);
        const previousStage = oldZone?.closest('[data-crm-stage]');
        zone.classList.remove('is-drag-over');
        if (!targetStageId || String(targetStageId) === String(card.dataset.currentStage || '')) {
          refreshStage(previousStage);
          refreshStage(targetStage);
          return;
        }
        refreshStage(previousStage);
        refreshStage(targetStage);
        await moveRequest(board, card, targetStageId, () => {
          if (!oldZone) return;
          if (next && next.parentElement === oldZone) oldZone.insertBefore(card, next);
          else oldZone.appendChild(card);
          refreshStage(previousStage);
          refreshStage(targetStage);
        });
      });
    });

    board.querySelectorAll('[data-crm-fallback-move]').forEach((form) => {
      const select = form.querySelector('[data-crm-stage-select]');
      const card = form.closest('[data-crm-card]');
      if (!select || !card) return;
      select.addEventListener('change', async (event) => {
        event.preventDefault();
        const targetStageId = Number(select.value || 0);
        const targetStage = board.querySelector(`[data-crm-stage][data-stage-id="${targetStageId}"]`);
        const targetZone = targetStage?.querySelector('[data-crm-dropzone]');
        const oldZone = card.parentElement;
        const oldStage = oldZone?.closest('[data-crm-stage]');
        const oldStageId = card.dataset.currentStage || '';
        const oldNext = card.nextElementSibling;
        if (!targetZone || String(targetStageId) === String(oldStageId)) return;
        targetZone.appendChild(card);
        refreshStage(oldStage);
        refreshStage(targetStage);
        const ok = await moveRequest(board, card, targetStageId, () => {
          if (oldNext && oldNext.parentElement === oldZone) oldZone.insertBefore(card, oldNext);
          else oldZone?.appendChild(card);
          select.value = String(oldStageId);
          refreshStage(oldStage);
          refreshStage(targetStage);
        });
        if (!ok) select.value = String(oldStageId);
      });
      form.addEventListener('submit', (event) => event.preventDefault());
    });
  });
})();

// ZIP 34.4.1 — leitura completa das configurações por empresa.
(function () {
  const drawer = document.getElementById('tenant-health-config-drawer');
  if (!drawer) return;

  const search = drawer.querySelector('[data-health-config-search]');
  const groups = Array.from(drawer.querySelectorAll('[data-health-config-group]'));
  const empty = drawer.querySelector('[data-health-config-empty]');

  const normalize = (value) => String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();

  const applySearch = () => {
    const term = normalize(search?.value);
    let visible = 0;
    groups.forEach((group) => {
      const haystack = normalize(group.dataset.healthConfigSearchText || group.textContent);
      const matches = term === '' || haystack.includes(term);
      group.hidden = !matches;
      if (matches) visible += 1;
    });
    if (empty) empty.hidden = visible > 0;
  };

  search?.addEventListener('input', applySearch);

  drawer.querySelector('[data-health-config-expand]')?.addEventListener('click', () => {
    drawer.querySelectorAll('.tenant-health-config-record').forEach((details) => {
      details.open = true;
    });
  });

  drawer.querySelector('[data-health-config-collapse]')?.addEventListener('click', () => {
    drawer.querySelectorAll('.tenant-health-config-record, .tenant-health-config-long-fields details').forEach((details) => {
      details.open = false;
    });
  });

  drawer.querySelectorAll('[data-health-config-jump]').forEach((button) => {
    button.addEventListener('click', () => {
      const key = button.dataset.healthConfigJump || '';
      const target = drawer.querySelector('#health-config-' + CSS.escape(key));
      if (!target) return;
      target.hidden = false;
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      target.classList.add('is-highlighted');
      window.setTimeout(() => target.classList.remove('is-highlighted'), 1300);
    });
  });

  drawer.querySelector('[data-health-config-copy]')?.addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const lines = [];
    const company = drawer.querySelector('.conversation-drawer-header h2')?.textContent?.trim();
    if (company) lines.push(company, '');

    groups.forEach((group) => {
      if (group.hidden) return;
      const title = group.querySelector('.tenant-health-config-group-title h3')?.textContent?.trim();
      if (title) lines.push('## ' + title);
      group.querySelectorAll('.tenant-health-config-record').forEach((record) => {
        const recordTitle = record.querySelector(':scope > summary strong')?.textContent?.trim();
        if (recordTitle) lines.push('- ' + recordTitle);
        record.querySelectorAll(':scope > .tenant-health-config-fields > div').forEach((field) => {
          const label = field.querySelector('dt')?.textContent?.trim();
          const value = field.querySelector('dd')?.textContent?.trim();
          if (label) lines.push('  ' + label + ': ' + (value || 'Não informado'));
        });
      });
      lines.push('');
    });

    try {
      await navigator.clipboard.writeText(lines.join('\n').trim());
      const original = button.textContent;
      button.textContent = 'Resumo copiado';
      window.setTimeout(() => { button.textContent = original; }, 1800);
    } catch (_) {
      window.prompt('Copie o resumo abaixo:', lines.join('\n').trim());
    }
  });
})();
