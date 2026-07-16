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
