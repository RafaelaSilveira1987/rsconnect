document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.getElementById('sidebar');
  const toggle = document.getElementById('sidebarToggle');
  const backdrop = document.getElementById('sidebarBackdrop');

  const closeSidebar = () => {
    sidebar?.classList.remove('is-open');
    document.body.classList.remove('sidebar-open');
    toggle?.setAttribute('aria-expanded', 'false');
    toggle?.setAttribute('aria-label', 'Abrir menu');
  };
  toggle?.addEventListener('click', () => {
    const open = !sidebar?.classList.contains('is-open');
    sidebar?.classList.toggle('is-open', Boolean(open));
    document.body.classList.toggle('sidebar-open', Boolean(open));
    toggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle?.setAttribute('aria-label', open ? 'Fechar menu' : 'Abrir menu');
  });
  backdrop?.addEventListener('click', closeSidebar);
  sidebar?.querySelectorAll('.nav-link').forEach((link) => link.addEventListener('click', closeSidebar));

  const backToTop = document.querySelector('[data-back-to-top]');
  const refreshBackToTop = () => backToTop?.classList.toggle('is-visible', window.scrollY > 520);
  window.addEventListener('scroll', refreshBackToTop, { passive: true });
  backToTop?.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
  refreshBackToTop();

  const initCollapsibleLists = () => {
    document.querySelectorAll('[data-collapsible-list]').forEach((list) => {
      if (list.dataset.collapsibleReady === '1') return;
      list.dataset.collapsibleReady = '1';
      const limit = Math.max(1, Number(list.dataset.collapsibleList || 3));
      const getItems = () => Array.from(list.children).filter((item) => !item.classList.contains('empty-state'));
      let expanded = false;
      const anchor = list.closest('.table-wrap') || list;
      const controls = document.createElement('div');
      controls.className = 'collapsible-list-controls';
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-small btn-quiet collapsible-list-toggle';
      controls.appendChild(button);
      anchor.insertAdjacentElement('afterend', controls);

      const render = () => {
        const items = getItems();
        items.forEach((item, index) => {
          item.hidden = !expanded && index >= limit;
        });
        const remaining = Math.max(0, items.length - limit);
        controls.hidden = remaining === 0;
        button.textContent = expanded ? 'Mostrar menos' : `Ver mais (${remaining})`;
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      };
      button.addEventListener('click', () => { expanded = !expanded; render(); });
      const observer = new MutationObserver(render);
      observer.observe(list, { childList: true });
      render();
    });
  };
  initCollapsibleLists();

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


  const panelFocusOrigins = new WeakMap();
  const panelFocusable = (panel) => Array.from(panel?.querySelectorAll?.(
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])'
  ) || []).filter((element) => !element.hidden && element.offsetParent !== null);
  const openPanel = (panel, trigger = null) => {
    if (!panel) return;
    if (trigger instanceof HTMLElement) panelFocusOrigins.set(panel, trigger);
    panel.classList.add('is-open');
    panel.setAttribute('role', panel.getAttribute('role') || 'dialog');
    panel.setAttribute('aria-modal', panel.getAttribute('aria-modal') || 'true');
    panel.setAttribute('aria-hidden', 'false');
    window.requestAnimationFrame(() => panelFocusable(panel)[0]?.focus());
  };
  const closePanel = (panel) => {
    if (!panel) return;
    panel.classList.remove('is-open');
    panel.setAttribute('aria-hidden', 'true');
    const origin = panelFocusOrigins.get(panel);
    origin?.focus?.();
  };
  document.querySelectorAll('.conversation-details').forEach((panel) => {
    panel.setAttribute('aria-hidden', panel.classList.contains('is-open') ? 'false' : 'true');
  });
  document.querySelectorAll('[data-toggle-panel]').forEach((button) => {
    button.addEventListener('click', () => {
      const panel = document.getElementById(button.dataset.togglePanel || '');
      if (!panel) return;
      if (panel.classList.contains('is-open')) closePanel(panel);
      else openPanel(panel, button);
    });
  });
  document.querySelectorAll('[data-close-panel]').forEach((button) => {
    button.addEventListener('click', () => closePanel(document.getElementById(button.dataset.closePanel || '')));
  });
  document.addEventListener('keydown', (event) => {
    const openPanels = Array.from(document.querySelectorAll('.conversation-details.is-open'));
    const activePanel = openPanels[openPanels.length - 1];
    if (event.key === 'Escape') {
      closeSidebar();
      openPanels.forEach((panel) => closePanel(panel));
      return;
    }
    if (event.key === 'Tab' && activePanel) {
      const items = panelFocusable(activePanel);
      if (!items.length) return;
      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });
  document.addEventListener('click', (event) => {
    document.querySelectorAll('.conversation-details.is-open').forEach((panel) => {
      const toggleClicked = event.target.closest?.('[data-toggle-panel="' + panel.id + '"]');
      if (!panel.contains(event.target) && !toggleClicked) closePanel(panel);
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
  const form = document.querySelector('[data-new-conversation-form]');
  if (!form) return;

  const shell = document.querySelector('[data-new-conversation-shell]');
  const openButton = document.querySelector('[data-new-conversation-open]');
  const closeButtons = document.querySelectorAll('[data-new-conversation-close]');
  const lookupUrl = form.dataset.contactLookupUrl || '';
  const instance = form.querySelector('[data-new-conversation-instance]');
  const search = form.querySelector('[data-new-conversation-search]');
  const phone = form.querySelector('[data-new-conversation-phone]');
  const name = form.querySelector('[data-new-conversation-name]');
  const results = form.querySelector('[data-new-conversation-results]');
  const existing = form.querySelector('[data-new-conversation-existing]');
  let lookupTimer = null;
  let lastQuery = '';
  let requestSeq = 0;

  function openNewConversation() {
    if (!shell) return;
    shell.hidden = false;
    document.body.classList.add('has-new-conversation-drawer');
    window.requestAnimationFrame(() => {
      const target = instance?.value ? search : instance;
      target?.focus();
    });
  }

  function closeNewConversation() {
    if (!shell) return;
    shell.hidden = true;
    document.body.classList.remove('has-new-conversation-drawer');
    clearResults();
    openButton?.focus();
  }

  openButton?.addEventListener('click', openNewConversation);
  closeButtons.forEach((button) => button.addEventListener('click', closeNewConversation));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && shell && !shell.hidden) closeNewConversation();
  });

  const escape = (value) => String(value || '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');

  function clearResults() {
    if (!results) return;
    results.hidden = true;
    results.innerHTML = '';
  }

  function clearExisting() {
    if (!existing) return;
    existing.hidden = true;
    existing.innerHTML = '';
  }

  function conversationUrl(id, publicId = '') {
    const url = new URL(window.location.href);
    url.searchParams.delete('conversation_id');
    url.searchParams.delete('conversation_uuid');
    if (publicId) url.searchParams.set('conversation_uuid', String(publicId));
    else url.searchParams.set('conversation_id', String(id));
    return url.pathname + url.search;
  }

  function showExisting(item) {
    if (!existing) return;
    if (!item?.conversation_id) {
      clearExisting();
      return;
    }
    existing.innerHTML = `<div><strong>Conversa já existente</strong><small>Este contato já possui atendimento neste canal. Ao enviar, a conversa existente será reaberta e usada.</small></div><a class="btn btn-outline btn-small" href="${escape(conversationUrl(item.conversation_id, item.conversation_public_id || ''))}">Abrir conversa</a>`;
    existing.hidden = false;
  }

  function choose(item) {
    if (!item) return;
    if (phone) phone.value = item.phone || '';
    if (name && !name.value.trim()) name.value = item.name || '';
    if (search) search.value = item.name || item.phone || '';
    showExisting(item);
    clearResults();
  }

  function render(items) {
    if (!results) return;
    if (!Array.isArray(items) || items.length === 0) {
      results.innerHTML = '<div class="new-conversation-search-empty"><strong>Nenhum contato encontrado</strong><small>Informe o telefone completo para iniciar um novo atendimento.</small></div>';
      results.hidden = false;
      return;
    }
    results.innerHTML = items.map((item, index) => {
      const label = item.name || item.phone || 'Contato';
      const secondary = [item.phone, item.company].filter(Boolean).join(' · ');
      const badge = item.conversation_id ? '<span>Conversa existente</span>' : '<span>Contato cadastrado</span>';
      return `<button type="button" class="new-conversation-search-item" data-new-contact-result="${index}"><span class="new-conversation-search-avatar">${escape(String(label).charAt(0).toUpperCase())}</span><span class="new-conversation-search-copy"><strong>${escape(label)}</strong><small>${escape(secondary)}</small></span>${badge}</button>`;
    }).join('');
    results.hidden = false;
    results.querySelectorAll('[data-new-contact-result]').forEach((button) => {
      button.addEventListener('click', () => choose(items[Number(button.dataset.newContactResult || 0)]));
    });
  }

  async function lookup(value, preferExact = false) {
    const instanceId = Number(instance?.value || 0);
    const query = String(value || '').trim();
    if (!lookupUrl || !instanceId || query.length < 2) {
      clearResults();
      if (!query) clearExisting();
      return;
    }
    const seq = ++requestSeq;
    lastQuery = query;
    try {
      const params = new URLSearchParams({ instance_id: String(instanceId), q: query });
      const response = await fetch(`${lookupUrl}?${params.toString()}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) return;
      const payload = await response.json();
      if (seq !== requestSeq || lastQuery !== query) return;
      const items = Array.isArray(payload.results) ? payload.results : [];
      if (preferExact) {
        const digits = query.replace(/\D+/g, '');
        const exact = items.find((item) => String(item.phone || '').replace(/\D+/g, '') === digits);
        if (exact) {
          if (name && !name.value.trim()) name.value = exact.name || '';
          showExisting(exact);
        } else {
          clearExisting();
        }
      } else {
        render(items);
      }
    } catch (_) {
      // A busca é auxiliar e nunca deve bloquear o início da conversa.
    }
  }

  search?.addEventListener('input', () => {
    window.clearTimeout(lookupTimer);
    lookupTimer = window.setTimeout(() => lookup(search.value, false), 220);
  });
  search?.addEventListener('focus', () => {
    if (search.value.trim().length >= 2) lookup(search.value, false);
  });
  phone?.addEventListener('input', () => {
    window.clearTimeout(lookupTimer);
    const digits = phone.value.replace(/\D+/g, '');
    if (digits.length < 8) {
      clearExisting();
      return;
    }
    lookupTimer = window.setTimeout(() => lookup(digits, true), 260);
  });
  instance?.addEventListener('change', () => {
    clearResults();
    clearExisting();
    if (search?.value.trim().length >= 2) lookup(search.value, false);
    else if ((phone?.value || '').replace(/\D+/g, '').length >= 8) lookup(phone.value, true);
  });
  document.addEventListener('click', (event) => {
    if (!form.contains(event.target)) clearResults();
  });
})();

(function () {
  const workspace = document.querySelector('[data-conversation-realtime]');
  if (!workspace) return;

  const pollUrl = workspace.dataset.pollUrl || '';
  const avatarUrl = workspace.dataset.avatarUrl || '';
  const currentParams = new URLSearchParams(workspace.dataset.currentQuery || '');
  let selectedConversationId = Number(workspace.dataset.conversationId || 0);
  let selectedConversationPublicId = String(workspace.dataset.conversationPublicId || '');
  let lastMessageId = Number(workspace.dataset.lastMessageId || 0);
  let unreadTotal = 0;
  let timer = null;
  let isPolling = false;
  const baseTitle = workspace.dataset.baseTitle || document.title.replace(' — RS Connect', '');
  const thread = document.querySelector('[data-chat-thread]');
  const list = document.querySelector('[data-conversation-list]');
  const status = document.querySelector('[data-realtime-status]');
  const toast = document.querySelector('[data-realtime-toast]');
  const composerForm = document.querySelector('[data-chat-composer]');
  const composerInput = composerForm?.querySelector('textarea[name="message"]') || null;
  const composerButton = composerForm?.querySelector('button[type="submit"]') || null;
  const attachmentInput = composerForm?.querySelector('[data-attachment-input]') || null;
  const attachmentOpen = composerForm?.querySelector('[data-attachment-open]') || null;
  const attachmentPreview = composerForm?.querySelector('[data-attachment-preview]') || null;
  const attachmentPreviewName = composerForm?.querySelector('[data-attachment-preview-name]') || null;
  const attachmentPreviewSize = composerForm?.querySelector('[data-attachment-preview-size]') || null;
  const attachmentPreviewIcon = composerForm?.querySelector('[data-attachment-preview-icon]') || null;
  const attachmentRemove = composerForm?.querySelector('[data-attachment-remove]') || null;
  const ownershipBanner = document.querySelector('[data-ownership-banner]');
  const searchInput = document.querySelector('[data-conversation-search]');
  const conversationCount = document.querySelector('[data-conversation-count]');
  const afterHoursQueueCount = document.querySelector('[data-after-hours-queue-count]');
  const quotePendingQueueCount = document.querySelector('[data-quote-pending-count]');
  let searchTimer = null;

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

  function buildConversationUrl(id, publicId = '') {
    const url = new URL(window.location.href);
    ['search', 'status', 'mode', 'instance_id', 'tenant_id', 'conversation_id', 'instance_uuid', 'tenant_uuid', 'conversation_uuid', 'intent'].forEach((key) => url.searchParams.delete(key));
    currentParams.forEach((value, key) => { if (value !== '') url.searchParams.set(key, value); });
    if (publicId) url.searchParams.set('conversation_uuid', String(publicId));
    else url.searchParams.set('conversation_id', String(id));
    return url.pathname + url.search;
  }

  function syncBrowserQuery() {
    const url = new URL(window.location.href);
    ['search', 'status', 'mode', 'instance_id', 'tenant_id', 'conversation_id', 'instance_uuid', 'tenant_uuid', 'conversation_uuid', 'intent'].forEach((key) => url.searchParams.delete(key));
    currentParams.forEach((value, key) => { if (value !== '') url.searchParams.set(key, value); });
    if (selectedConversationPublicId) url.searchParams.set('conversation_uuid', selectedConversationPublicId);
    else if (selectedConversationId > 0) url.searchParams.set('conversation_id', String(selectedConversationId));
    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
  }

  function initials(name, phone) {
    const source = String(name || phone || 'C').trim();
    return source ? source.charAt(0).toUpperCase() : 'C';
  }

  function safeAvatarUrl(value) {
    const url = String(value || '').trim();
    return /^https?:\/\//i.test(url) ? url : '';
  }

  function avatarMarkup(item) {
    const fallback = escapeHtml(initials(item.name, item.phone));
    const url = safeAvatarUrl(item.avatar_url);
    return `<span class="conversation-avatar" data-contact-avatar-container data-avatar-resolved="${item.avatar_resolved ? '1' : '0'}"><span class="conversation-avatar-fallback" data-avatar-fallback>${fallback}</span>${url ? `<img class="conversation-avatar-image" data-contact-avatar src="${escapeHtml(url)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : ''}</span>`;
  }

  function wireAvatarImages(root = document) {
    root.querySelectorAll?.('[data-contact-avatar]').forEach((image) => {
      if (image.dataset.avatarWired === '1') return;
      image.dataset.avatarWired = '1';
      const handleLoad = () => {
        const container = image.closest('[data-contact-avatar-container]');
        if (!container) return;
        container.dataset.avatarResolved = '1';
        container.dataset.avatarRetry = '0';
      };
      const handleError = () => {
        const container = image.closest('[data-contact-avatar-container]');
        image.remove();
        if (!container || container.dataset.avatarRetry === '1') return;
        container.dataset.avatarRetry = '1';
        container.dataset.avatarResolved = '0';
        fetchConversationAvatar(container, true);
      };
      image.addEventListener('load', handleLoad, { once: true });
      image.addEventListener('error', handleError, { once: true });

      // O script é carregado com defer; a imagem pode ter concluído antes dos listeners.
      if (image.complete) {
        if (image.naturalWidth > 0) handleLoad();
        else handleError();
      }
    });
  }

  function updateAvatar(node, item) {
    const container = node?.matches?.('[data-contact-avatar-container]')
      ? node
      : node?.querySelector?.('[data-contact-avatar-container]');
    if (!container) return;
    if (item && Object.prototype.hasOwnProperty.call(item, 'avatar_resolved')) {
      container.dataset.avatarResolved = item.avatar_resolved ? '1' : '0';
    }
    const fallback = container.querySelector('[data-avatar-fallback]');
    if (fallback) fallback.textContent = initials(item.name, item.phone);
    const url = safeAvatarUrl(item.avatar_url);
    let image = container.querySelector('[data-contact-avatar]');
    if (!url) {
      image?.remove();
      return;
    }
    if (!image) {
      image = document.createElement('img');
      image.className = 'conversation-avatar-image';
      image.setAttribute('data-contact-avatar', '');
      image.alt = '';
      image.loading = 'lazy';
      image.referrerPolicy = 'no-referrer';
      container.appendChild(image);
    }
    if (image.src !== url) image.src = url;
    wireAvatarImages(container);
  }

  async function fetchConversationAvatar(target, force = false) {
    if (!avatarUrl || !target) return;
    const container = target.matches?.('[data-contact-avatar-container]')
      ? target
      : target.querySelector?.('[data-contact-avatar-container]');
    if (!container || container.dataset.avatarLoading === '1') return;
    if (!force && container.dataset.avatarResolved === '1') return;

    const row = container.closest('[data-conversation-row]');
    const conversationId = Number(
      container.dataset.conversationId
      || row?.dataset.conversationId
      || (container.closest('[data-selected-conversation-panel]') ? selectedConversationId : 0)
      || 0
    );
    const conversationPublicId = String(
      container.dataset.conversationPublicId
      || row?.dataset.conversationPublicId
      || (container.closest('[data-selected-conversation-panel]') ? selectedConversationPublicId : '')
      || ''
    );
    if (!conversationId && !conversationPublicId) return;

    container.dataset.avatarLoading = '1';
    try {
      const params = new URLSearchParams();
      if (conversationPublicId) params.set('conversation_uuid', conversationPublicId);
      else params.set('conversation_id', String(conversationId));
      if (force) params.set('force', '1');
      const tenantPublicId = currentParams.get('tenant_uuid');
      const tenantId = currentParams.get('tenant_id');
      if (tenantPublicId) params.set('tenant_uuid', tenantPublicId);
      else if (tenantId) params.set('tenant_id', tenantId);
      const response = await fetch(`${avatarUrl}?${params.toString()}`, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store'
      });
      if (!response.ok) return;
      const payload = await response.json();
      const item = {
        name: row?.querySelector('[data-conversation-name]')?.textContent
          || document.querySelector('.chat-contact-title h2')?.textContent
          || '',
        phone: '',
        avatar_url: payload.avatar_url || '',
        avatar_resolved: Boolean(payload.resolved)
      };
      updateAvatar(container, item);
    } catch (_) {
      // Avatar é opcional; a conversa continua com as iniciais.
    } finally {
      container.dataset.avatarLoading = '0';
    }
  }

  const avatarObserver = avatarUrl && 'IntersectionObserver' in window
    ? new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          avatarObserver.unobserve(entry.target);
          fetchConversationAvatar(entry.target);
        });
      }, { root: list || null, rootMargin: '80px' })
    : null;

  function observeConversationAvatars(root = list) {
    if (!root) return;
    root.querySelectorAll('[data-conversation-row]').forEach((row) => {
      const container = row.querySelector('[data-contact-avatar-container]');
      if (!container || container.dataset.avatarResolved === '1') return;
      if (avatarObserver) avatarObserver.observe(row);
      else fetchConversationAvatar(row);
    });
  }

  function applyOwnership(ownership) {
    if (!ownership || typeof ownership !== 'object') return;
    const locked = Boolean(ownership.locked_by_other);
    if (composerInput) {
      composerInput.disabled = locked;
      composerInput.placeholder = locked ? 'Conversa em atendimento por outro profissional' : 'Digite uma mensagem...';
    }
    if (composerButton) composerButton.disabled = locked;
    if (attachmentOpen) attachmentOpen.disabled = locked;
    if (attachmentInput) attachmentInput.disabled = locked;
    if (ownershipBanner && locked) {
      ownershipBanner.classList.remove('is-available', 'is-mine');
      ownershipBanner.classList.add('is-locked');
      const name = ownership.assigned_user_name || 'Outro profissional';
      const strong = ownershipBanner.querySelector('strong');
      const text = ownershipBanner.querySelector('span');
      if (strong) strong.textContent = `Atendimento em andamento com ${name}`;
      if (text) text.textContent = 'Você pode acompanhar a conversa, mas não pode responder nem alterar o atendimento enquanto ela estiver aberta.';
    }
  }

  function modeText(mode) {
    return mode === 'human' ? 'Humano' : (mode === 'paused' ? 'IA pausada' : 'IA ativa');
  }

  function normalizeConversationStatus(value) {
    const normalized = String(value || '').toLowerCase();
    return ['open', 'pending', 'closed'].includes(normalized) ? normalized : 'open';
  }

  function conversationStatusText(value) {
    const normalized = normalizeConversationStatus(value);
    return normalized === 'closed' ? 'Encerrada' : (normalized === 'pending' ? 'Pendente' : 'Aberta');
  }

  function applySelectedConversationStatus(value) {
    const normalized = normalizeConversationStatus(value);
    const panel = document.querySelector('[data-selected-conversation-panel]');
    if (panel) {
      panel.classList.remove('conversation-status-open', 'conversation-status-pending', 'conversation-status-closed');
      panel.classList.add(`conversation-status-${normalized}`);
      panel.dataset.conversationStatus = normalized;
    }
    const badge = document.querySelector('[data-conversation-status-badge]');
    if (badge) {
      badge.className = `badge badge-${normalized}`;
      badge.textContent = conversationStatusText(normalized);
    }
  }

  function setConversationMode(mode) {
    const normalized = ['ai', 'human', 'paused'].includes(mode) ? mode : 'ai';
    const stateBadge = document.querySelector('.chat-state-bar .mini-badge');
    if (stateBadge) {
      stateBadge.className = `mini-badge mode-${normalized}`;
      stateBadge.textContent = modeText(normalized);
    }

    document.querySelectorAll('[data-mode-action]').forEach((form) => {
      const action = form.dataset.modeAction || '';
      form.hidden = normalized === 'human'
        ? action !== 'ai'
        : (normalized === 'ai' ? action === 'ai' : action === 'paused');
    });

    const selectedItem = list?.querySelector(`[data-conversation-item][data-conversation-id="${selectedConversationId}"]`);
    const selectedMode = selectedItem?.querySelector('.mini-badge');
    if (selectedMode) {
      selectedMode.className = `mini-badge mode-${normalized}`;
      selectedMode.textContent = modeText(normalized);
    }
  }

  function afterHoursStatusMeta(status) {
    const normalized = String(status || '').trim();
    const map = {
      pending: { label: 'Aguardando horário', className: 'is-waiting' },
      processing: { label: 'Retomando agora', className: 'is-processing' },
      blocked_plan: { label: 'Aguardando franquia', className: 'is-blocked' },
      blocked_human: { label: 'Aguardando equipe', className: 'is-human' },
      error: { label: 'Nova tentativa programada', className: 'is-error' },
    };
    return map[normalized] || { label: 'Aguardando horário', className: 'is-waiting' };
  }

  function afterHoursListMarkup(afterHours) {
    if (!afterHours || !afterHours.status) return '';
    const meta = afterHoursStatusMeta(afterHours.status);
    const count = Math.max(1, Number(afterHours.message_count || 0));
    const messageLabel = count === 1 ? 'mensagem preservada' : 'mensagens preservadas';
    return `<span class="conversation-queue-state ${meta.className}" data-after-hours-list-state>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
      <span><strong>${escapeHtml(meta.label)}</strong><small>${count} ${messageLabel}</small></span>
    </span>`;
  }

  function quotePendingMarkup(item) {
    if (!item || !item.quote_pending) return '';
    return `<span class="conversation-queue-state is-quote-pending" data-quote-pending-list-state>
      <span class="quote-pending-icon" aria-hidden="true">$</span>
      <span><strong>Orçamento pendente</strong><small>retorno comercial necessário</small></span>
    </span>`;
  }

  function operationalQueueMarkup(item) {
    return `${afterHoursListMarkup(item?.after_hours)}${quotePendingMarkup(item)}`;
  }

  function renderConversationItem(item) {
    const unread = Number(item.unread_count || 0);
    const selectedClass = item.is_selected ? ' is-selected' : '';
    const unreadHidden = unread > 0 ? '' : ' hidden';
    const modeClass = escapeHtml(item.mode || 'ai');
    const modeLabel = modeText(item.mode);
    const conversationStatus = normalizeConversationStatus(item.status);
    const publicId = String(item.public_id || '');
    const hasAfterHoursQueue = Boolean(item.after_hours && item.after_hours.status);
    const afterHoursStatus = hasAfterHoursQueue ? String(item.after_hours.status) : '';
    const hasQuotePending = Boolean(item.quote_pending);
    return `<div class="conversation-list-row status-${conversationStatus}${unread > 0 ? ' has-unread' : ''}${hasAfterHoursQueue ? ' has-after-hours-queue' : ''}${hasQuotePending ? ' has-quote-pending' : ''}" data-conversation-row data-conversation-id="${Number(item.id)}" data-conversation-public-id="${escapeHtml(publicId)}" data-conversation-status="${conversationStatus}" data-after-hours-status="${escapeHtml(afterHoursStatus)}">
      <label class="conversation-select-control" title="Selecionar ${escapeHtml(item.name || item.phone || 'conversa')}">
        <input type="checkbox" name="conversation_ids[]" value="${Number(item.id)}" form="conversation-bulk-read-form" data-conversation-select aria-label="Selecionar conversa de ${escapeHtml(item.name || item.phone || 'contato')}">
        <span aria-hidden="true"></span>
      </label>
      <a class="conversation-list-item${selectedClass}" data-conversation-item data-conversation-id="${Number(item.id)}" data-conversation-public-id="${escapeHtml(publicId)}" href="${escapeHtml(buildConversationUrl(item.id, publicId))}">
        ${avatarMarkup(item)}
        <span class="conversation-summary">
          <span class="conversation-title-row">
            <strong data-conversation-name>${escapeHtml(item.name || item.phone || 'Contato')}</strong>
            <time data-conversation-time>${escapeHtml(item.last_message_label || '')}</time>
          </span>
          <span class="conversation-preview" data-conversation-preview>${escapeHtml(item.preview || 'Sem mensagens')}</span>
          <span class="conversation-queue-slot" data-after-hours-list-slot>${operationalQueueMarkup(item)}</span>
          <span class="conversation-meta-row">
            <span class="mini-badge mode-${modeClass}">${escapeHtml(modeLabel)}</span>
            <span class="mini-badge conversation-status-badge status-${conversationStatus}" data-conversation-list-status>${escapeHtml(conversationStatusText(conversationStatus))}</span>
            <small>${escapeHtml(item.assigned_user_name ? `Responsável: ${item.assigned_user_name}` : (item.tenant_name || item.instance_label || ''))}</small>
            <b class="unread-count" data-unread-count${unreadHidden}>${unread}</b>
          </span>
        </span>
      </a>
    </div>`;
  }

  function updateConversationList(conversations) {
    if (!list || !Array.isArray(conversations)) return;
    const validIds = new Set(conversations.map((item) => Number(item.id)).filter(Boolean));
    list.querySelectorAll('[data-conversation-row]').forEach((row) => {
      const id = Number(row.dataset.conversationId || 0);
      if (!validIds.has(id)) row.remove();
    });

    conversations.slice().reverse().forEach((item) => {
      const id = Number(item.id);
      let node = list.querySelector(`[data-conversation-item][data-conversation-id="${id}"]`);
      if (!node) {
        list.insertAdjacentHTML('afterbegin', renderConversationItem(item));
        node = list.querySelector(`[data-conversation-item][data-conversation-id="${id}"]`);
      }
      const publicId = String(item.public_id || node.dataset.conversationPublicId || '');
      if (publicId) {
        node.dataset.conversationPublicId = publicId;
        node.href = buildConversationUrl(id, publicId);
      }
      node.classList.toggle('is-selected', id === selectedConversationId);
      const row = node.closest('[data-conversation-row]');
      if (row && publicId) row.dataset.conversationPublicId = publicId;
      const itemStatus = normalizeConversationStatus(item.status);
      if (row) {
        row.classList.remove('status-open', 'status-pending', 'status-closed');
        row.classList.add(`status-${itemStatus}`);
        row.dataset.conversationStatus = itemStatus;
      }
      row?.classList.toggle('has-unread', Number(item.unread_count || 0) > 0);
      const hasAfterHoursQueue = Boolean(item.after_hours && item.after_hours.status);
      row?.classList.toggle('has-after-hours-queue', hasAfterHoursQueue);
      row?.classList.toggle('has-quote-pending', Boolean(item.quote_pending));
      if (row) row.dataset.afterHoursStatus = hasAfterHoursQueue ? String(item.after_hours.status) : '';
      const name = node.querySelector('[data-conversation-name]');
      const time = node.querySelector('[data-conversation-time]');
      const preview = node.querySelector('[data-conversation-preview]');
      const afterHoursSlot = node.querySelector('[data-after-hours-list-slot]');
      const unread = node.querySelector('[data-unread-count]');
      const modeBadge = node.querySelector('.mini-badge');
      const statusBadge = node.querySelector('[data-conversation-list-status]');
      if (name) name.textContent = item.name || item.phone || 'Contato';
      if (time) time.textContent = item.last_message_label || '';
      if (preview) preview.textContent = item.preview || 'Sem mensagens';
      if (afterHoursSlot) afterHoursSlot.innerHTML = operationalQueueMarkup(item);
      updateAvatar(node, item);
      if (modeBadge) {
        const itemMode = ['ai', 'human', 'paused'].includes(item.mode) ? item.mode : 'ai';
        modeBadge.className = `mini-badge mode-${itemMode}`;
        modeBadge.textContent = modeText(itemMode);
      }
      if (statusBadge) {
        statusBadge.className = `mini-badge conversation-status-badge status-${itemStatus}`;
        statusBadge.textContent = conversationStatusText(itemStatus);
      }
      if (id === selectedConversationId) applySelectedConversationStatus(itemStatus);
      if (unread) {
        unread.textContent = Number(item.unread_count || 0);
        unread.hidden = Number(item.unread_count || 0) < 1;
      }
      list.prepend(row || node);
    });

    list.querySelector('.conversation-empty')?.remove();
    if (conversations.length === 0) {
      const searching = String(currentParams.get('search') || '').trim() !== '';
      list.insertAdjacentHTML('beforeend', `<div class="empty-state conversation-empty"><strong>${searching ? 'Nenhum contato encontrado.' : 'Nenhuma conversa encontrada.'}</strong><span>${searching ? 'Tente outro nome, telefone ou trecho de mensagem.' : 'As novas conversas aparecerão aqui.'}</span></div>`);
    }
    if (conversationCount) conversationCount.textContent = String(conversations.length);
    if (afterHoursQueueCount) {
      const queueTotal = conversations.filter((item) => item.after_hours && item.after_hours.status).length;
      const value = afterHoursQueueCount.querySelector('span');
      if (value) value.textContent = String(queueTotal);
      afterHoursQueueCount.hidden = queueTotal < 1;
    }
    if (quotePendingQueueCount) {
      const quoteTotal = conversations.filter((item) => Boolean(item.quote_pending)).length;
      const values = quotePendingQueueCount.querySelectorAll('span');
      const value = values.length > 1 ? values[values.length - 1] : values[0];
      if (value) value.textContent = String(quoteTotal);
      quotePendingQueueCount.hidden = quoteTotal < 1;
    }
    wireAvatarImages(list);
    observeConversationAvatars(list);
  }

  function attachmentIcon(kind) {
    if (kind === 'image') return '🖼️';
    if (kind === 'audio') return '🎵';
    return '📄';
  }

  function formatBytes(bytes) {
    const value = Number(bytes || 0);
    if (value >= 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
    if (value >= 1024) return `${Math.round(value / 1024)} KB`;
    return `${value} B`;
  }

  function renderAttachments(attachments) {
    if (!Array.isArray(attachments) || attachments.length === 0) return '';
    return attachments.map((attachment) => {
      const kind = String(attachment.kind || 'other');
      const ready = String(attachment.status || '') === 'ready';
      const name = escapeHtml(attachment.name || 'arquivo');
      const size = escapeHtml(attachment.size_label || formatBytes(attachment.size_bytes));
      const viewUrl = escapeHtml(attachment.view_url || '');
      const downloadUrl = escapeHtml(attachment.download_url || '');
      const image = ready && kind === 'image'
        ? `<a class="message-attachment-image" href="${viewUrl}" target="_blank" rel="noopener"><img src="${viewUrl}" alt="${name}" loading="lazy"></a>`
        : '';
      const audio = ready && kind === 'audio'
        ? `<div class="message-attachment-audio"><audio controls preload="metadata" src="${viewUrl}"></audio><label>Velocidade <select data-audio-speed><option value="1">1x</option><option value="1.5">1,5x</option><option value="2">2x</option></select></label></div>`
        : '';
      const actions = ready
        ? `<span class="message-attachment-actions">${kind === 'document' ? `<a href="${viewUrl}" target="_blank" rel="noopener">Visualizar</a>` : ''}<a href="${downloadUrl}">Baixar</a></span>`
        : `<small class="message-attachment-error">${escapeHtml(attachment.error_message || 'Arquivo indisponível.')}</small>`;
      return `<div class="message-attachment kind-${escapeHtml(kind)} status-${escapeHtml(attachment.status || 'pending')}">
        ${image}${audio}
        <div class="message-attachment-info"><span class="message-attachment-icon" aria-hidden="true">${attachmentIcon(kind)}</span><span><strong>${name}</strong><small>${size}</small></span>${actions}</div>
      </div>`;
    }).join('');
  }

  function resetAttachmentSelection() {
    if (attachmentInput) attachmentInput.value = '';
    if (attachmentPreview) attachmentPreview.hidden = true;
    if (attachmentPreviewName) attachmentPreviewName.textContent = '';
    if (attachmentPreviewSize) attachmentPreviewSize.textContent = '';
    if (composerInput) composerInput.placeholder = 'Digite uma mensagem...';
    composerForm?.classList.remove('has-attachment', 'is-dragging-file');
  }

  function showAttachmentSelection(file) {
    if (!file || !attachmentPreview) return;
    attachmentPreview.hidden = false;
    if (attachmentPreviewName) attachmentPreviewName.textContent = file.name || 'arquivo';
    if (attachmentPreviewSize) attachmentPreviewSize.textContent = formatBytes(file.size || 0);
    if (attachmentPreviewIcon) {
      const type = String(file.type || '');
      attachmentPreviewIcon.textContent = type.startsWith('image/') ? '🖼️' : (type.startsWith('audio/') ? '🎵' : '📄');
    }
    if (composerInput) composerInput.placeholder = 'Legenda opcional...';
    composerForm?.classList.add('has-attachment');
  }

  function renderMessage(message) {
    const outgoing = message.direction === 'outgoing';
    const failed = message.status === 'failed';
    const baseSender = message.sender_type === 'ai' ? 'IA' : (message.sender_name || 'Equipe');
    const sender = outgoing && message.sender_type === 'user' && message.sender_role_label
      ? `${baseSender} — ${message.sender_role_label}`
      : (outgoing ? baseSender : '');
    const attachments = Array.isArray(message.attachments) ? message.attachments : [];
    const rawContent = String(message.content || '').trim();
    const attachmentNames = attachments.map((item) => String(item?.name || '').trim()).filter(Boolean);
    const mediaPlaceholders = ['[Imagem]', '[Áudio]', '[Documento]', '[Arquivo]', ...attachmentNames];
    const content = rawContent && !mediaPlaceholders.includes(rawContent)
      ? `<p>${escapeHtml(rawContent).replace(/\n/g, '<br>')}</p>`
      : '';
    const typeLabels = { image: 'Imagem', audio: 'Áudio', document: 'Documento' };
    const type = message.message_type && message.message_type !== 'text' ? `<span class="message-type">${escapeHtml(typeLabels[message.message_type] || message.message_type)}</span>` : '';
    const attachmentMarkup = renderAttachments(attachments);
    const statusText = outgoing ? `<span class="message-status">${escapeHtml(message.status || '')}</span>` : '';
    const senderText = outgoing ? `<span>${escapeHtml(sender)}</span>` : '';
    return `<article class="message-row ${outgoing ? 'is-outgoing' : 'is-incoming'}" data-message-id="${Number(message.id)}">
      <div class="message-bubble ${failed ? 'has-error' : ''}" data-sender="${escapeHtml(message.sender_type || '')}">
        ${type}
        ${attachmentMarkup}
        ${content}
        <footer>${senderText}<time>${escapeHtml(message.time_label || '')}</time>${statusText}</footer>
      </div>
    </article>`;
  }

  function appendMessages(messages) {
    const summary = { added: 0, incoming: 0, outgoing: 0 };
    if (!thread || !Array.isArray(messages) || messages.length === 0) return summary;
    const stick = shouldStickToBottom();
    messages.forEach((message) => {
      const id = Number(message.id || 0);
      if (!id || thread.querySelector(`[data-message-id="${id}"]`)) return;
      thread.insertAdjacentHTML('beforeend', renderMessage(message));
      lastMessageId = Math.max(lastMessageId, id);
      summary.added += 1;
      if (message.direction === 'incoming') summary.incoming += 1;
      if (message.direction === 'outgoing') summary.outgoing += 1;
    });
    const empty = thread.querySelector('.chat-empty');
    if (empty && summary.added > 0) empty.remove();
    workspace.dataset.lastMessageId = String(lastMessageId);
    thread.dataset.lastMessageId = String(lastMessageId);
    if (stick || summary.added > 0) thread.scrollTop = thread.scrollHeight;
    return summary;
  }

  async function poll() {
    if (isPolling || !pollUrl) return;
    isPolling = true;
    const params = new URLSearchParams(currentParams);
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
      if (payload.selected_conversation_public_id) {
        selectedConversationPublicId = String(payload.selected_conversation_public_id);
        workspace.dataset.conversationPublicId = selectedConversationPublicId;
      }
      updateConversationList(payload.conversations || []);
      applyOwnership(payload.ownership || null);
      const messageSummary = appendMessages(payload.messages || []);
      unreadTotal = Number(payload.unread_total || 0);
      pulseTitle(unreadTotal);
      if (messageSummary.incoming === 1) showToast('Nova mensagem recebida.');
      if (messageSummary.incoming > 1) showToast(`${messageSummary.incoming} novas mensagens recebidas.`);
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

  if (searchInput) {
    searchInput.addEventListener('input', () => {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(async () => {
        const value = searchInput.value.trim();
        if (value) currentParams.set('search', value); else currentParams.delete('search');
        syncBrowserQuery();
        await poll();
      }, 280);
    });

    searchInput.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape' || searchInput.value === '') return;
      event.preventDefault();
      searchInput.value = '';
      currentParams.delete('search');
      syncBrowserQuery();
      window.clearTimeout(searchTimer);
      poll();
    });
  }

  wireAvatarImages(document);
  observeConversationAvatars(list);

  if (composerForm && attachmentInput) {
    attachmentOpen?.addEventListener('click', () => attachmentInput.click());
    attachmentInput.addEventListener('change', () => {
      const file = attachmentInput.files?.[0] || null;
      if (file) showAttachmentSelection(file); else resetAttachmentSelection();
    });
    attachmentRemove?.addEventListener('click', resetAttachmentSelection);

    ['dragenter', 'dragover'].forEach((name) => composerForm.addEventListener(name, (event) => {
      if (!event.dataTransfer?.types?.includes('Files')) return;
      event.preventDefault();
      composerForm.classList.add('is-dragging-file');
    }));
    ['dragleave', 'drop'].forEach((name) => composerForm.addEventListener(name, (event) => {
      if (name === 'drop') event.preventDefault();
      composerForm.classList.remove('is-dragging-file');
    }));
    composerForm.addEventListener('drop', (event) => {
      const file = event.dataTransfer?.files?.[0] || null;
      if (!file) return;
      if (typeof DataTransfer === 'undefined') {
        showToast('Use o botão de anexo para selecionar o arquivo neste navegador.');
        return;
      }
      const transfer = new DataTransfer();
      transfer.items.add(file);
      attachmentInput.files = transfer.files;
      showAttachmentSelection(file);
    });
  }

  document.addEventListener('change', (event) => {
    const select = event.target.closest?.('[data-audio-speed]');
    if (!select) return;
    const audio = select.closest('.message-attachment-audio')?.querySelector('audio');
    if (audio) audio.playbackRate = Number(select.value || 1);
  });

  if (composerForm && composerInput) {
    composerForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const text = composerInput.value.trim();
      const file = attachmentInput?.files?.[0] || null;
      if ((!text && !file) || composerForm.dataset.sending === '1') return;

      composerForm.dataset.sending = '1';
      if (composerButton) {
        composerButton.disabled = true;
        composerButton.dataset.originalLabel = composerButton.textContent || 'Enviar';
        composerButton.textContent = file ? 'Enviando arquivo...' : 'Enviando...';
      }

      try {
        const endpoint = file ? (composerForm.dataset.attachmentAction || composerForm.action) : composerForm.action;
        const response = await fetch(endpoint, {
          method: 'POST',
          body: new FormData(composerForm),
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          credentials: 'same-origin',
          cache: 'no-store'
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.ok === false) {
          throw new Error(payload.message || `HTTP ${response.status}`);
        }

        composerInput.value = '';
        resetAttachmentSelection();
        setConversationMode(payload.attendance_mode || 'human');
        await poll();
        if (thread) thread.scrollTop = thread.scrollHeight;
        composerInput.focus({ preventScroll: true });
        showToast(payload.message || 'Mensagem enviada.');
      } catch (error) {
        showToast(error?.message || 'Não foi possível enviar a mensagem.');
        composerInput.focus({ preventScroll: true });
      } finally {
        composerForm.dataset.sending = '0';
        if (composerButton) {
          composerButton.disabled = false;
          composerButton.textContent = composerButton.dataset.originalLabel || 'Enviar';
        }
      }
    });
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
      const instanceField = form.querySelector('input[name="instance_id"]');
      modal.dataset.instanceId = instanceField?.value || '';
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
        if (message && payload.message) {
          message.textContent = payload.message;
          message.hidden = false;
        }
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
    if (notification.type === 'communication') return;

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

(function () {
  document.querySelectorAll('.notification-rule-card input[name$="[enabled]"]').forEach((input) => {
    input.addEventListener('change', () => {
      input.closest('.notification-rule-card')?.classList.toggle('is-enabled', input.checked);
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
    field('credential_owner').value = 'tenant';
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
    field('credential_owner').value = button.dataset.credentialOwner || 'tenant';
    field('base_url').value = button.dataset.baseUrl || '';
    field('default_model').value = button.dataset.defaultModel || '';
    field('status').value = button.dataset.status || 'active';
    field('is_default').checked = button.dataset.isDefault === '1';
    apiKeyInput.value = '';
    apiKeyInput.required = false;
    if (eyebrow) eyebrow.textContent = 'Editar chave de acesso';
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
  const ownerFilter = document.querySelector('[data-ai-credential-owner-filter]');
  const clearButton = document.querySelector('[data-ai-credential-clear]');
  const visibleCount = document.querySelector('[data-ai-credential-visible-count]');
  const filterEmpty = document.querySelector('[data-ai-credential-filter-empty]');
  const cards = Array.from(document.querySelectorAll('[data-ai-credential-card]'));

  const normalize = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const applyFilters = () => {
    const query = normalize(searchInput?.value);
    const provider = providerFilter?.value || '';
    const status = statusFilter?.value || '';
    const owner = ownerFilter?.value || '';
    let shown = 0;
    cards.forEach((card) => {
      const matches = (!query || normalize(card.dataset.search).includes(query))
        && (!provider || card.dataset.provider === provider)
        && (!status || card.dataset.status === status)
        && (!owner || card.dataset.owner === owner);
      card.hidden = !matches;
      if (matches) shown += 1;
    });
    if (visibleCount) visibleCount.textContent = `${shown} registro(s)`;
    if (filterEmpty) filterEmpty.hidden = shown !== 0 || cards.length === 0;
  };

  searchInput?.addEventListener('input', applyFilters);
  providerFilter?.addEventListener('change', applyFilters);
  statusFilter?.addEventListener('change', applyFilters);
  ownerFilter?.addEventListener('change', applyFilters);
  clearButton?.addEventListener('click', () => {
    if (searchInput) searchInput.value = '';
    if (providerFilter) providerFilter.value = '';
    if (statusFilter) statusFilter.value = '';
    if (ownerFilter) ownerFilter.value = '';
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
    const createOnlySections = Array.from(instanceForm.querySelectorAll('[data-instance-create-only]'));
    const tenantField = instanceDrawer.querySelector('[data-instance-tenant-field]');
    const eyebrow = instanceDrawer.querySelector('[data-instance-drawer-eyebrow]');
    const title = instanceDrawer.querySelector('[data-instance-drawer-title]');
    const description = instanceDrawer.querySelector('[data-instance-drawer-description]');
    const apiHint = instanceDrawer.querySelector('[data-instance-api-hint]');
    const submit = instanceDrawer.querySelector('[data-instance-submit]');
    const submitLabel = submit?.querySelector('[data-instance-submit-label]');
    const rejectToggle = instanceForm.querySelector('[data-instance-reject-toggle]');
    const rejectMessageWrap = instanceForm.querySelector('[data-instance-reject-message-wrap]');
    const rejectMessage = instanceForm.querySelector('[data-instance-reject-message]');
    const eventInputs = Array.from(instanceForm.querySelectorAll('[data-instance-create-event]'));
    const eventCount = instanceForm.querySelector('[data-instance-event-count]');
    const advancedEvents = instanceForm.querySelector('.instance-advanced-section');

    const setSubmitLabel = (value) => {
      if (submitLabel) submitLabel.textContent = value;
      else if (submit) submit.textContent = value;
    };
    const syncRejectMessage = () => {
      const enabled = Boolean(rejectToggle?.checked);
      if (rejectMessageWrap) rejectMessageWrap.hidden = !enabled;
      if (rejectMessage) rejectMessage.disabled = !enabled;
    };
    const syncEventCount = () => {
      if (!eventCount) return;
      const selected = eventInputs.filter((input) => input.checked).length;
      eventCount.textContent = `${selected} evento${selected === 1 ? '' : 's'} selecionado${selected === 1 ? '' : 's'}`;
    };
    const syncDrawerState = () => {
      document.body.classList.toggle('has-instance-create-drawer', instanceDrawer.classList.contains('is-open'));
    };
    new MutationObserver(syncDrawerState).observe(instanceDrawer, { attributes: true, attributeFilter: ['class'] });
    rejectToggle?.addEventListener('change', syncRejectMessage);
    eventInputs.forEach((input) => input.addEventListener('change', syncEventCount));

    const reset = () => {
      instanceForm.reset();
      instanceForm.action = instanceForm.dataset.createAction || instanceForm.getAttribute('action') || '/instances';
      field('id').value = '0';
      field('instance_name').readOnly = false;
      field('api_key').required = false;
      field('api_key').value = '';
      createOnlySections.forEach((section) => { section.hidden = false; });
      if (tenantField) tenantField.hidden = false;
      field('tenant_id').required = true;
      if (eyebrow) eyebrow.textContent = 'Nova conexão';
      if (title) title.textContent = 'Criar WhatsApp';
      if (description) description.textContent = 'Crie a instância na Evolution e conecte o número sem sair do RS Connect.';
      if (apiHint) apiHint.textContent = 'Se EVOLUTION_DEFAULT_API_KEY estiver configurada, este campo pode ficar vazio.';
      setSubmitLabel('Criar conexão');
      submit?.classList.remove('is-submitting');
      if (submit) submit.disabled = false;
      syncRejectMessage();
      syncEventCount();
      if (advancedEvents) advancedEvents.open = false;
    };

    document.querySelectorAll('[data-instance-open]').forEach((button) => {
      button.addEventListener('click', () => {
        if (button.dataset.instanceOpen !== 'edit') {
          reset();
          return;
        }

        instanceForm.reset();
        instanceForm.action = instanceForm.dataset.updateAction || '/instances/update';
        field('id').value = button.dataset.id || '0';
        field('name').value = button.dataset.name || '';
        field('instance_name').value = button.dataset.instanceName || '';
        field('base_url').value = button.dataset.baseUrl || '';
        field('is_default').checked = button.dataset.isDefault === '1';
        field('api_key').value = '';
        field('api_key').required = false;
        field('instance_name').readOnly = button.dataset.managementMode === 'managed';
        createOnlySections.forEach((section) => { section.hidden = true; });
        if (tenantField) tenantField.hidden = true;
        field('tenant_id').required = false;
        if (eyebrow) eyebrow.textContent = 'Editar conexão';
        if (title) title.textContent = button.dataset.name || 'Atualizar conexão';
        if (description) description.textContent = button.dataset.managementMode === 'managed'
          ? 'A instância é gerenciada pelo RS Connect. O identificador remoto permanece bloqueado para evitar perda de vínculo.'
          : 'Atualize o cadastro local sem perder os vínculos existentes.';
        if (apiHint) apiHint.textContent = 'Deixe em branco para manter a chave atual.';
        setSubmitLabel('Salvar alterações');
        submit?.classList.remove('is-submitting');
        if (submit) submit.disabled = false;
        syncRejectMessage();
      });
    });

    instanceForm.addEventListener('submit', (event) => {
      if (!instanceForm.checkValidity()) return;
      if (submit?.disabled) {
        event.preventDefault();
        return;
      }
      if (submit) {
        submit.disabled = true;
        submit.classList.add('is-submitting');
      }
      setSubmitLabel(field('id')?.value && field('id').value !== '0' ? 'Salvando...' : 'Criando conexão...');
    });

    reset();
    syncDrawerState();
  }

  const settingsDrawer = document.getElementById('instance-settings-drawer');
  const settingsForm = settingsDrawer?.querySelector('[data-instance-settings-form]');
  if (settingsForm) {
    const settingsField = (name) => settingsForm.querySelector(`[data-instance-settings-field="${name}"]`);
    document.querySelectorAll('[data-instance-settings]').forEach((button) => {
      button.addEventListener('click', () => {
        settingsForm.reset();
        let data = {};
        try {
          data = JSON.parse(decodeURIComponent(button.dataset.instanceSettings || '%7B%7D'));
        } catch (error) {
          console.error('Não foi possível carregar as configurações da instância.', error);
        }

        const idField = settingsField('id');
        if (idField) idField.value = data.id || '';
        const title = settingsDrawer.querySelector('[data-instance-settings-title]');
        if (title) title.textContent = data.name ? `Configurar ${data.name}` : 'Configurar conexão';

        [
          'receive_messages', 'ignore_groups', 'ignore_status', 'ignore_broadcast',
          'ignore_newsletters', 'ignore_from_me', 'reject_calls', 'always_online',
          'read_messages', 'read_status', 'sync_full_history', 'webhook_enabled'
        ].forEach((name) => {
          const input = settingsField(name);
          if (input) input.checked = Number(data[name] || 0) === 1;
        });

        const rejectMessage = settingsField('reject_call_message');
        if (rejectMessage) rejectMessage.value = data.reject_call_message || '';
        const selectedEvents = Array.isArray(data.webhook_events) ? data.webhook_events : [];
        settingsForm.querySelectorAll('[data-instance-event]').forEach((input) => {
          input.checked = selectedEvents.includes(input.value);
        });
      });
    });
  }

  const instanceDeleteForm = document.querySelector('[data-instance-delete-form]');
  if (instanceDeleteForm) {
    const deleteDrawer = document.getElementById('instance-delete-drawer');
    const deleteField = (name) => instanceDeleteForm.querySelector(`[data-instance-delete-field="${name}"]`);
    const deleteSubmit = instanceDeleteForm.querySelector('[data-instance-delete-submit]');
    const deleteLoading = instanceDeleteForm.querySelector('[data-instance-delete-loading]');
    const deleteImpact = instanceDeleteForm.querySelector('[data-instance-delete-impact]');
    const deleteError = instanceDeleteForm.querySelector('[data-instance-delete-error]');
    const deleteMergeNote = instanceDeleteForm.querySelector('[data-instance-delete-merge-note]');
    const deleteReplacementHint = instanceDeleteForm.querySelector('[data-instance-delete-replacement-hint]');
    const deleteDiscardRow = instanceDeleteForm.querySelector('[data-instance-delete-discard-row]');
    const deleteDiscardAckRow = instanceDeleteForm.querySelector('[data-instance-delete-discard-ack]');
    const deleteRemoteRow = instanceDeleteForm.querySelector('[data-instance-delete-remote-row]');
    const deleteRemoteAckRow = instanceDeleteForm.querySelector('[data-instance-delete-remote-ack]');
    const deleteRemoteState = instanceDeleteForm.querySelector('[data-instance-delete-remote-state]');
    const deleteStatus = instanceDeleteForm.querySelector('[data-instance-delete-status]');
    const deleteDestinationSection = instanceDeleteForm.querySelector('[data-instance-delete-destination-section]');
    const deleteEyebrow = document.querySelector('[data-instance-delete-eyebrow]');
    const deleteTitle = document.querySelector('[data-instance-delete-title]');
    const deleteDescription = document.querySelector('[data-instance-delete-description]');
    const deleteSourceNote = instanceDeleteForm.querySelector('[data-instance-delete-source-note]');
    const deleteDestinationTitle = instanceDeleteForm.querySelector('[data-instance-delete-destination-title]');
    const deleteDestinationDescription = instanceDeleteForm.querySelector('[data-instance-delete-destination-description]');
    const deleteRemovalTitle = instanceDeleteForm.querySelector('[data-instance-delete-removal-title]');
    const deleteRemovalDescription = instanceDeleteForm.querySelector('[data-instance-delete-removal-description]');
    const deleteDependencyTitle = instanceDeleteForm.querySelector('[data-instance-delete-dependency-title]');
    const deleteDependencyDescription = instanceDeleteForm.querySelector('[data-instance-delete-dependency-description]');
    let deletePreviewState = null;
    let deleteRequestSequence = 0;

    const setDeleteMessage = (element, message, visible = true) => {
      if (!element) return;
      element.textContent = message || '';
      element.hidden = !visible || !message;
    };

    const setDeleteText = (element, message) => {
      if (element) element.textContent = message;
    };

    const resetDeletePresentation = (managed = false) => {
      instanceDeleteForm.dataset.deleteMode = 'assisted';
      instanceDeleteForm.classList.remove('is-local-only', 'is-local-transfer', 'is-local-discard');
      if (deleteDrawer) deleteDrawer.setAttribute('aria-label', 'Exclusão assistida de conexão');
      setDeleteText(deleteEyebrow, 'Exclusão assistida');
      setDeleteText(deleteTitle, 'Remover conexão com segurança');
      setDeleteText(
        deleteDescription,
        managed
          ? 'Esta conexão foi criada pelo RS Connect. O sistema verificará se ela ainda existe fora da plataforma.'
          : 'Esta conexão foi vinculada ao RS Connect. O sistema verificará se ela ainda existe fora da plataforma.'
      );
      setDeleteText(deleteSourceNote, 'O cadastro abaixo será removido depois da transferência validada.');
      if (deleteDestinationSection) deleteDestinationSection.hidden = false;
      setDeleteText(deleteDestinationTitle, 'Preservar os dados operacionais');
      setDeleteText(deleteDestinationDescription, 'Assistentes, contatos, conversas, campanhas e relatórios serão movidos para outra conexão da mesma empresa.');
      setDeleteText(deleteRemovalTitle, 'Defina o que será apagado');
      setDeleteText(deleteRemovalDescription, 'O RS Connect confirma primeiro se essa conexão ainda existe no serviço externo do WhatsApp.');
      setDeleteText(deleteDependencyTitle, 'Revisei os vínculos e o destino informado');
      setDeleteText(deleteDependencyDescription, 'Confirmo que os dados operacionais devem ser transferidos ou que esta conexão não possui vínculos.');
      if (deleteDiscardRow) deleteDiscardRow.hidden = true;
      if (deleteDiscardAckRow) deleteDiscardAckRow.hidden = true;
      const discard = deleteField('discard_dependencies');
      const acknowledgeDiscard = deleteField('acknowledge_discard');
      if (discard) discard.checked = false;
      if (acknowledgeDiscard) {
        acknowledgeDiscard.checked = false;
        acknowledgeDiscard.required = false;
      }
    };

    const availableReplacementCount = () => {
      const replacement = deleteField('replacement');
      return Array.from(replacement?.options || []).filter((option, index) => (
        index > 0 && !option.hidden && !option.disabled
      )).length;
    };

    const applyDeletePresentation = (payload) => {
      const remoteMissing = payload?.remote?.exists === false;
      const hasDependencies = Boolean(payload?.requires_replacement);

      if (deleteDiscardRow) deleteDiscardRow.hidden = !hasDependencies;

      if (!remoteMissing) {
        instanceDeleteForm.dataset.deleteMode = 'assisted';
        instanceDeleteForm.classList.remove('is-local-only', 'is-local-transfer', 'is-local-discard');
        if (deleteDrawer) deleteDrawer.setAttribute('aria-label', 'Exclusão assistida de conexão');
        setDeleteText(deleteEyebrow, 'Exclusão assistida');
        setDeleteText(deleteTitle, hasDependencies ? 'Definir destino dos dados e remover conexão' : 'Remover conexão com segurança');
        setDeleteText(deleteSourceNote, 'O cadastro abaixo será removido depois da validação dos dados vinculados.');
        if (deleteDestinationSection) deleteDestinationSection.hidden = !hasDependencies;
        setDeleteText(deleteDestinationTitle, 'Escolha como tratar os dados vinculados');
        setDeleteText(deleteDestinationDescription, 'Transfira para outra conexão ou, caso não exista outra disponível, confirme a remoção definitiva dos dados dependentes.');
        setDeleteText(deleteRemovalTitle, 'Defina o que será apagado');
        setDeleteText(deleteRemovalDescription, 'O RS Connect confirmou a situação externa. Escolha se a conexão também será removida do serviço do WhatsApp.');
        setDeleteText(deleteDependencyTitle, 'Revisei os vínculos e a opção escolhida');
        setDeleteText(deleteDependencyDescription, 'Confirmo a transferência para outra conexão ou a remoção dos dados vinculados.');
        return;
      }

      instanceDeleteForm.dataset.deleteMode = hasDependencies ? 'local-decision' : 'local-only';
      instanceDeleteForm.classList.remove('is-local-transfer', 'is-local-discard');
      instanceDeleteForm.classList.toggle('is-local-only', !hasDependencies);
      if (deleteDrawer) deleteDrawer.setAttribute('aria-label', 'Excluir cadastro local da conexão');
      setDeleteText(deleteEyebrow, hasDependencies ? 'Exclusão local assistida' : 'Exclusão somente local');
      setDeleteText(deleteTitle, hasDependencies ? 'Definir destino dos dados e excluir cadastro' : 'Excluir cadastro do RS Connect');
      setDeleteText(
        deleteDescription,
        hasDependencies
          ? 'A conexão já não existe fora da plataforma. Transfira os dados para outra conexão ou confirme a remoção dos dados vinculados.'
          : 'A conexão já não existe fora da plataforma. Será excluído somente o cadastro que permaneceu no RS Connect.'
      );
      setDeleteText(
        deleteSourceNote,
        hasDependencies
          ? 'A conexão externa já foi removida, mas este cadastro ainda possui dados operacionais vinculados.'
          : 'A conexão externa já foi removida e não existem vínculos operacionais que exijam transferência.'
      );
      if (deleteDestinationSection) deleteDestinationSection.hidden = !hasDependencies;
      setDeleteText(deleteDestinationTitle, 'Escolha como tratar os dados vinculados');
      setDeleteText(deleteDestinationDescription, 'Transfira os dados para outra conexão ou use a opção de remoção definitiva quando não houver uma conexão substituta.');
      setDeleteText(deleteRemovalTitle, 'Excluir somente do RS Connect');
      setDeleteText(deleteRemovalDescription, 'Nenhuma exclusão será enviada ao serviço externo porque a conexão já não existe lá.');
      setDeleteText(
        deleteDependencyTitle,
        hasDependencies ? 'Revisei os vínculos e a opção escolhida' : 'Confirmo a exclusão do cadastro local'
      );
      setDeleteText(
        deleteDependencyDescription,
        hasDependencies
          ? 'Confirmo a transferência para outra conexão ou a remoção definitiva dos dados vinculados.'
          : 'Confirmo que a conexão externa já não existe e que será removido somente o cadastro do RS Connect.'
      );
    };

    const syncDeleteEligibility = () => {
      const replacement = deleteField('replacement');
      const discardDependencies = deleteField('discard_dependencies');
      const acknowledgeDiscard = deleteField('acknowledge_discard');
      const confirmation = deleteField('confirmation');
      const deleteRemote = deleteField('delete_remote');
      const acknowledgeDependencies = deleteField('acknowledge_dependencies');
      const acknowledgeRemote = deleteField('acknowledge_remote_active');
      const expected = `EXCLUIR ${instanceDeleteForm.dataset.instanceName || ''}`;
      if (!deletePreviewState) {
        if (deleteSubmit) {
          deleteSubmit.disabled = true;
          deleteSubmit.textContent = deleteLoading && !deleteLoading.hidden
            ? 'Aguarde a verificação...'
            : 'Verificação necessária';
        }
        return;
      }

      const hasDependencies = Boolean(deletePreviewState?.requires_replacement);
      const remoteMissing = deletePreviewState?.remote?.exists === false;
      const remoteCheckUnavailable = deletePreviewState?.remote?.checked === false;
      const discarding = hasDependencies && Boolean(discardDependencies?.checked);
      const transferring = hasDependencies && Boolean(replacement?.value) && !discarding;
      const connectedWithoutRemoteDelete = Boolean(deletePreviewState?.requires_remote_ack)
        && !deleteRemote?.checked;
      const fallbackConnectedWithoutRemoteDelete = remoteCheckUnavailable
        && deletePreviewState?.source?.status === 'connected'
        && !deleteRemote?.checked;

      if (replacement) {
        replacement.disabled = discarding;
        replacement.required = hasDependencies && !discarding;
      }
      if (deleteDiscardAckRow) deleteDiscardAckRow.hidden = !discarding;
      if (acknowledgeDiscard) {
        acknowledgeDiscard.required = discarding;
        if (!discarding) acknowledgeDiscard.checked = false;
      }

      const needsRemoteAcknowledgement = !remoteMissing
        && (connectedWithoutRemoteDelete || fallbackConnectedWithoutRemoteDelete);
      if (deleteRemoteAckRow) deleteRemoteAckRow.hidden = !needsRemoteAcknowledgement;
      if (acknowledgeRemote) {
        acknowledgeRemote.required = needsRemoteAcknowledgement;
        if (!needsRemoteAcknowledgement) acknowledgeRemote.checked = false;
      }

      const strategySelected = !hasDependencies || transferring || discarding;
      const ready = Boolean(deletePreviewState?.ok)
        && strategySelected
        && Boolean(acknowledgeDependencies?.checked)
        && confirmation?.value.trim() === expected
        && (!discarding || Boolean(acknowledgeDiscard?.checked))
        && (!needsRemoteAcknowledgement || Boolean(acknowledgeRemote?.checked));

      instanceDeleteForm.classList.toggle('is-local-transfer', remoteMissing && transferring);
      instanceDeleteForm.classList.toggle('is-local-discard', remoteMissing && discarding);
      instanceDeleteForm.classList.toggle('is-local-only', remoteMissing && !hasDependencies);
      instanceDeleteForm.dataset.deleteMode = remoteMissing
        ? (discarding ? 'local-discard' : transferring ? 'local-transfer' : hasDependencies ? 'local-decision' : 'local-only')
        : (discarding ? 'assisted-discard' : transferring ? 'assisted-transfer' : 'assisted');

      if (hasDependencies) {
        if (discarding) {
          setDeleteText(deleteTitle, remoteMissing ? 'Excluir cadastro e remover dados vinculados' : 'Excluir conexão e remover dados vinculados');
          setDeleteText(deleteDependencyTitle, 'Confirmo a remoção definitiva dos dados vinculados');
          setDeleteText(deleteDependencyDescription, 'Assistentes, contatos e relatórios serão desvinculados; conversas, campanhas, vínculos de canal e eventos técnicos serão excluídos.');
        } else if (transferring) {
          setDeleteText(deleteTitle, remoteMissing ? 'Transferir dados e excluir cadastro' : 'Transferir dados e excluir conexão');
          setDeleteText(deleteDependencyTitle, 'Revisei os vínculos e a conexão substituta');
          setDeleteText(deleteDependencyDescription, 'Confirmo que os dados vinculados devem ser transferidos para a conexão escolhida antes da exclusão.');
        } else {
          setDeleteText(deleteTitle, remoteMissing ? 'Definir destino dos dados e excluir cadastro' : 'Definir destino dos dados e remover conexão');
          setDeleteText(deleteDependencyTitle, 'Escolha como tratar os dados vinculados');
          setDeleteText(deleteDependencyDescription, 'Selecione uma conexão substituta ou marque a opção de remover os dados vinculados.');
        }
      }

      if (deleteSubmit) {
        deleteSubmit.disabled = !ready;
        deleteSubmit.textContent = remoteMissing
          ? (discarding
            ? 'Excluir cadastro e dados vinculados'
            : transferring
              ? 'Transferir dados e excluir cadastro'
              : hasDependencies
                ? 'Escolha o destino dos dados'
                : 'Excluir cadastro do RS Connect')
          : (discarding
            ? 'Excluir conexão e dados vinculados'
            : transferring
              ? 'Transferir e excluir conexão'
              : hasDependencies
                ? 'Escolha o destino dos dados'
                : 'Excluir conexão');
      }
    };

    const renderDeletePreview = (payload) => {
      deletePreviewState = payload;
      if (deleteLoading) deleteLoading.hidden = true;
      if (deleteImpact) deleteImpact.hidden = false;
      setDeleteMessage(deleteError, '', false);

      Object.entries(payload.counts || {}).forEach(([key, value]) => {
        const target = instanceDeleteForm.querySelector(`[data-instance-delete-count="${key}"]`);
        if (target) target.textContent = new Intl.NumberFormat('pt-BR').format(Number(value || 0));
      });

      if (deleteStatus) {
        const labels = { connected: 'Conectada', disconnected: 'Desconectada', pending: 'Pendente' };
        deleteStatus.textContent = labels[payload.source?.status] || payload.source?.status || 'Verificada';
        deleteStatus.className = `badge badge-${payload.source?.status || 'warning'}`;
      }

      const remote = payload.remote || {};
      const deleteRemote = deleteField('delete_remote');
      if (remote.exists === false) {
        if (deleteStatus) {
          deleteStatus.textContent = 'Somente no RS Connect';
          deleteStatus.className = 'badge badge-warning';
        }
        if (deleteRemote) {
          deleteRemote.checked = false;
          deleteRemote.disabled = true;
        }
        if (deleteRemoteRow) deleteRemoteRow.hidden = true;
        if (deleteRemoteState) {
          deleteRemoteState.className = 'instance-delete-remote-state is-success';
          deleteRemoteState.textContent = remote.message || 'A conexão externa já não existe. Será removido somente o cadastro do RS Connect.';
        }
      } else {
        const unavailable = remote.checked === false;
        const connected = remote.connected === true;
        if (deleteStatus && remote.checked === true) {
          deleteStatus.textContent = connected ? 'Ativa fora do RS Connect' : 'Desconectada fora';
          deleteStatus.className = `badge ${connected ? 'badge-danger' : 'badge-warning'}`;
        } else if (deleteStatus && unavailable) {
          deleteStatus.textContent = 'Situação não confirmada';
          deleteStatus.className = 'badge badge-warning';
        }
        if (deleteRemote) deleteRemote.disabled = false;
        if (deleteRemoteRow) deleteRemoteRow.hidden = false;
        if (deleteRemoteState) {
          deleteRemoteState.className = `instance-delete-remote-state ${unavailable ? 'is-warning' : connected ? 'is-danger' : 'is-info'}`;
          deleteRemoteState.textContent = remote.message || 'A situação externa foi verificada.';
        }
      }

      applyDeletePresentation(payload);

      const conflicts = payload.conflicts || {};
      const notes = [];
      if (Number(conflicts.conversation_duplicates || 0) > 0) {
        notes.push(`${conflicts.conversation_duplicates} conversa(s) já existem no destino e terão os históricos consolidados.`);
      }
      if (Number(conflicts.agent_binding_duplicates || 0) > 0) {
        notes.push(`${conflicts.agent_binding_duplicates} vínculo(s) de assistente já existem no destino e serão unificados.`);
      }
      if (Number(payload.counts?.connection_events || 0) > 0) {
        notes.push('Os eventos técnicos da conexão antiga serão resumidos na auditoria e removidos com o cadastro.');
      }
      if (deleteMergeNote) {
        deleteMergeNote.textContent = notes.join(' ');
        deleteMergeNote.hidden = notes.length === 0;
      }
      if (deleteReplacementHint) {
        if (!payload.requires_replacement) {
          deleteReplacementHint.textContent = 'Não há vínculos operacionais que exijam transferência.';
        } else if (availableReplacementCount() > 0) {
          deleteReplacementHint.textContent = 'Selecione uma conexão substituta ou marque a opção de remover os dados vinculados.';
        } else {
          deleteReplacementHint.textContent = 'Não há outra conexão disponível. Marque a opção abaixo para remover os dados vinculados e continuar.';
        }
      }
      syncDeleteEligibility();
    };

    const loadDeletePreview = async () => {
      const instanceId = deleteField('id')?.value || '';
      const replacementId = deleteField('replacement')?.value || '';
      if (!instanceId) return;
      const sequence = ++deleteRequestSequence;
      deletePreviewState = null;
      if (deleteSubmit) {
        deleteSubmit.disabled = true;
        deleteSubmit.textContent = 'Aguarde a verificação...';
      }
      if (deleteLoading) {
        deleteLoading.hidden = false;
        deleteLoading.textContent = 'Consultando os vínculos atuais...';
      }
      if (deleteImpact) deleteImpact.hidden = true;
      setDeleteMessage(deleteError, '', false);
      setDeleteMessage(deleteMergeNote, '', false);

      const requestController = new AbortController();
      const requestTimeout = window.setTimeout(() => requestController.abort(), 20000);
      try {
        const url = new URL(instanceDeleteForm.dataset.previewEndpoint, window.location.origin);
        url.searchParams.set('instance_id', instanceId);
        if (replacementId) url.searchParams.set('replacement_instance_id', replacementId);
        const response = await fetch(url.toString(), {
          method: 'GET',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
          cache: 'no-store',
          signal: requestController.signal
        });
        const contentType = String(response.headers.get('content-type') || '').toLowerCase();
        if (!contentType.includes('application/json')) {
          throw new Error(response.redirected
            ? 'A sessão foi redirecionada. Atualize a página e tente novamente.'
            : 'O servidor não retornou a validação da exclusão em formato JSON.');
        }
        const payload = await response.json().catch(() => ({}));
        if (sequence !== deleteRequestSequence) return;
        if (!response.ok || !payload.ok) throw new Error(payload.message || 'Não foi possível validar os vínculos.');
        renderDeletePreview(payload);
      } catch (error) {
        if (sequence !== deleteRequestSequence) return;
        const message = error?.name === 'AbortError'
          ? 'A verificação demorou mais que o esperado. Tente novamente em alguns segundos.'
          : (error?.message || 'Não foi possível validar os vínculos.');
        if (deleteLoading) deleteLoading.hidden = true;
        if (deleteRemoteState) {
          deleteRemoteState.className = 'instance-delete-remote-state is-warning';
          deleteRemoteState.textContent = message;
        }
        setDeleteMessage(deleteError, message, true);
        syncDeleteEligibility();
      } finally {
        window.clearTimeout(requestTimeout);
      }
    };

    document.querySelectorAll('[data-instance-delete]').forEach((button) => {
      button.addEventListener('click', () => {
        instanceDeleteForm.reset();
        deletePreviewState = null;
        const id = deleteField('id');
        const replacement = deleteField('replacement');
        const discardDependencies = deleteField('discard_dependencies');
        const acknowledgeDiscard = deleteField('acknowledge_discard');
        const confirmation = deleteField('confirmation');
        const deleteRemote = deleteField('delete_remote');
        const name = document.querySelector('[data-instance-delete-name]');
        const hint = document.querySelector('[data-instance-delete-hint]');
        const managed = button.dataset.managementMode === 'managed';

        resetDeletePresentation(managed);
        if (id) id.value = button.dataset.id || '';
        instanceDeleteForm.dataset.instanceName = button.dataset.instanceName || '';
        if (name) name.textContent = button.dataset.name || 'Conexão';
        if (deleteRemote) {
          deleteRemote.checked = managed;
          deleteRemote.disabled = false;
        }
        if (deleteRemoteRow) deleteRemoteRow.hidden = false;
        if (deleteRemoteAckRow) deleteRemoteAckRow.hidden = true;
        if (deleteRemoteState) {
          deleteRemoteState.className = 'instance-delete-remote-state';
          deleteRemoteState.textContent = 'Verificando a conexão externa...';
        }
        if (confirmation) {
          confirmation.value = '';
          confirmation.placeholder = `EXCLUIR ${button.dataset.instanceName || ''}`;
        }
        if (hint) {
          hint.textContent = 'Digite exatamente: ';
          const strong = document.createElement('strong');
          strong.textContent = `EXCLUIR ${button.dataset.instanceName || ''}`;
          hint.appendChild(strong);
        }
        Array.from(replacement?.options || []).forEach((option, index) => {
          if (index === 0) return;
          const visible = option.dataset.tenantId === button.dataset.tenantId && option.value !== button.dataset.id;
          option.hidden = !visible;
          option.disabled = !visible;
        });
        if (replacement) {
          replacement.value = '';
          replacement.required = false;
          replacement.disabled = false;
        }
        if (discardDependencies) discardDependencies.checked = false;
        if (acknowledgeDiscard) {
          acknowledgeDiscard.checked = false;
          acknowledgeDiscard.required = false;
        }
        if (deleteDiscardRow) deleteDiscardRow.hidden = true;
        if (deleteDiscardAckRow) deleteDiscardAckRow.hidden = true;
        syncDeleteEligibility();
        loadDeletePreview();
      });
    });

    deleteField('replacement')?.addEventListener('change', () => {
      const replacement = deleteField('replacement');
      const discardDependencies = deleteField('discard_dependencies');
      const acknowledgeDiscard = deleteField('acknowledge_discard');
      if (replacement?.value && discardDependencies) {
        discardDependencies.checked = false;
        if (acknowledgeDiscard) acknowledgeDiscard.checked = false;
      }
      syncDeleteEligibility();
      loadDeletePreview();
    });
    deleteField('discard_dependencies')?.addEventListener('change', () => {
      const replacement = deleteField('replacement');
      const discardDependencies = deleteField('discard_dependencies');
      const acknowledgeDiscard = deleteField('acknowledge_discard');
      if (discardDependencies?.checked && replacement) replacement.value = '';
      if (!discardDependencies?.checked && acknowledgeDiscard) acknowledgeDiscard.checked = false;
      syncDeleteEligibility();
      loadDeletePreview();
    });
    ['delete_remote', 'acknowledge_dependencies', 'acknowledge_remote_active', 'acknowledge_discard', 'confirmation'].forEach((name) => {
      const input = deleteField(name);
      input?.addEventListener(input?.type === 'text' ? 'input' : 'change', syncDeleteEligibility);
      if (name === 'confirmation') input?.addEventListener('input', syncDeleteEligibility);
    });

    instanceDeleteForm.addEventListener('submit', (event) => {
      syncDeleteEligibility();
      if (deleteSubmit?.disabled) {
        event.preventDefault();
        setDeleteMessage(deleteError, 'Escolha como tratar os dados vinculados e conclua todas as confirmações antes de excluir.', true);
        return;
      }
      const remoteMissing = deletePreviewState?.remote?.exists === false;
      const hasDependencies = Boolean(deletePreviewState?.requires_replacement);
      const discarding = hasDependencies && Boolean(deleteField('discard_dependencies')?.checked);
      const transferring = hasDependencies && Boolean(deleteField('replacement')?.value) && !discarding;
      const confirmationMessage = discarding
        ? (remoteMissing
          ? 'Confirma a exclusão do cadastro local e a remoção definitiva das conversas, campanhas, vínculos e eventos desta conexão?'
          : 'Confirma a exclusão da conexão e a remoção definitiva das conversas, campanhas, vínculos e eventos vinculados?')
        : remoteMissing
          ? (transferring
            ? 'Confirma a transferência dos dados e a exclusão somente do cadastro desta conexão no RS Connect?'
            : 'Confirma a exclusão somente do cadastro desta conexão no RS Connect?')
          : (transferring
            ? 'Confirma a transferência dos dados e a exclusão definitiva desta conexão?'
            : 'Confirma a exclusão definitiva desta conexão?');
      if (!window.confirm(confirmationMessage)) {
        event.preventDefault();
        return;
      }
      if (deleteSubmit) {
        deleteSubmit.disabled = true;
        deleteSubmit.textContent = discarding
          ? 'Removendo dados e cadastro...'
          : remoteMissing
            ? (transferring ? 'Transferindo dados...' : 'Excluindo cadastro...')
            : (transferring ? 'Transferindo e excluindo...' : 'Excluindo conexão...');
      }
    });
  }

  const setupSimpleDrawer = (config) => {
    const drawer=document.getElementById(config.drawer); const form=drawer?.querySelector(config.form); if(!drawer||!form)return;
    const field=(name)=>form.querySelector(`[${config.attr}="${name}"]`);
    document.querySelectorAll(config.buttons).forEach((button)=>button.addEventListener('click',()=>config.fill({button,drawer,form,field})));
  };
  setupSimpleDrawer({drawer:'n8n-flow-drawer',form:'[data-n8n-form]',buttons:'[data-n8n-open]',attr:'data-n8n-field',fill:({button,drawer,form,field})=>{form.reset();field('id').value='0';field('status').value='active';form.querySelectorAll('input[name="events[]"]').forEach((input)=>{input.checked=input.value==='*';});const edit=button.dataset.n8nOpen==='edit';if(edit){field('id').value=button.dataset.id||'0';field('tenant_id').value=button.dataset.tenantId||'';field('flow_key').value=button.dataset.flowKey||'';field('name').value=button.dataset.name||'';field('description').value=button.dataset.description||'';field('status').value=button.dataset.status||'active';try{const events=JSON.parse(decodeURIComponent(button.dataset.events||'%5B%5D'));form.querySelectorAll('input[name="events[]"]').forEach((input)=>input.checked=events.includes(input.value));}catch(e){} drawer.querySelector('[data-n8n-eyebrow]').textContent='Editar fluxo';drawer.querySelector('[data-n8n-title]').textContent=button.dataset.name||'Atualizar automação';drawer.querySelector('[data-n8n-description]').textContent='A URL e o token atuais serão mantidos quando os campos ficarem vazios.';drawer.querySelector('[data-n8n-url-hint]').textContent='Deixe em branco para manter a URL atual.';drawer.querySelector('[data-n8n-submit]').textContent='Salvar alterações';}else{drawer.querySelector('[data-n8n-eyebrow]').textContent='Novo fluxo';drawer.querySelector('[data-n8n-title]').textContent='Configurar automação';drawer.querySelector('[data-n8n-description]').textContent='Defina a empresa, o webhook e quando este fluxo deve ser acionado.';drawer.querySelector('[data-n8n-url-hint]').textContent='Obrigatória no primeiro cadastro.';drawer.querySelector('[data-n8n-submit]').textContent='Salvar fluxo';}}});
  setupSimpleDrawer({drawer:'plan-drawer',form:'[data-plan-form]',buttons:'[data-plan-open]',attr:'data-plan-field',fill:({button,drawer,form,field})=>{
    form.reset();
    field('id').value='0';
    field('status').value='active';
    field('sort_order').value='50';
    field('commitment_discount_6').value='8';
    field('commitment_discount_12').value='15';
    form.querySelectorAll('[data-plan-limit]').forEach((input)=>input.value='');
    const edit=button.dataset.planOpen==='edit';
    if(edit){
      field('id').value=button.dataset.id||'0';
      field('plan_key').value=button.dataset.planKey||'';
      field('name').value=button.dataset.name||'';
      field('description').value=button.dataset.description||'';
      field('own_ai_monthly_price').value=button.dataset.ownPrice||button.dataset.price||'';
      field('rs_ai_monthly_price').value=button.dataset.rsPrice||button.dataset.price||'';
      field('monthly_price').value=button.dataset.rsPrice||button.dataset.price||'';
      field('commitment_discount_6').value=button.dataset.discount6||'8';
      field('commitment_discount_12').value=button.dataset.discount12||'15';
      field('status').value=button.dataset.status||'active';
      field('sort_order').value=button.dataset.sortOrder||'50';
      field('features').value=decodeURIComponent(button.dataset.features||'');
      try{
        const limits=JSON.parse(decodeURIComponent(button.dataset.limits||'%7B%7D'));
        Object.entries(limits).forEach(([key,value])=>{const input=form.querySelector(`[data-plan-limit="${CSS.escape(key)}"]`);if(input)input.value=value??'';});
      }catch(e){}
      drawer.querySelector('[data-plan-eyebrow]').textContent='Editar plano';
      drawer.querySelector('[data-plan-title]').textContent=button.dataset.name||'Atualizar plano';
      drawer.querySelector('[data-plan-submit]').textContent='Salvar alterações';
    }else{
      drawer.querySelector('[data-plan-eyebrow]').textContent='Novo plano';
      drawer.querySelector('[data-plan-title]').textContent='Criar pacote comercial';
      drawer.querySelector('[data-plan-submit]').textContent='Salvar plano';
    }
    const syncLegacyPrice=()=>{if(field('monthly_price'))field('monthly_price').value=field('rs_ai_monthly_price')?.value||field('own_ai_monthly_price')?.value||'0';};
    field('own_ai_monthly_price')?.addEventListener('input',syncLegacyPrice,{once:true});
    field('rs_ai_monthly_price')?.addEventListener('input',syncLegacyPrice,{once:true});
    syncLegacyPrice();
  }});

  const commercialPricing=document.querySelector('[data-commercial-pricing]');
  if(commercialPricing){
    let mode='rs_connect';
    let term='3';
    const money=(value)=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(value||0));
    const update=()=>{
      commercialPricing.querySelectorAll('[data-pricing-mode]').forEach((button)=>button.classList.toggle('is-active',button.dataset.pricingMode===mode));
      commercialPricing.querySelectorAll('[data-pricing-term]').forEach((button)=>button.classList.toggle('is-active',button.dataset.pricingTerm===term));
      const modeHelp=commercialPricing.querySelector('[data-pricing-mode-help]');
      const termHelp=commercialPricing.querySelector('[data-pricing-term-help]');
      const summary=commercialPricing.querySelector('[data-commercial-pricing-summary]');
      if(modeHelp)modeHelp.textContent=mode==='tenant'?'O cliente informa a própria chave e paga o consumo diretamente ao provedor.':'A RS Connect fornece a chave e inclui uma franquia mensal de respostas.';
      if(termHelp)termHelp.textContent=term==='3'?'Valor padrão com permanência mínima de 3 meses.':term==='6'?'8% de desconto mensal com permanência mínima de 6 meses.':'15% de desconto mensal com permanência mínima de 12 meses.';
      if(summary)summary.textContent=`${mode==='tenant'?'IA própria do cliente':'IA RS Connect'} · contrato mínimo de ${term} meses · cobrança mensal`;
      document.querySelectorAll('[data-commercial-plan]').forEach((card)=>{
        if(card.dataset.customQuote==='1')return;
        const base=Number(mode==='tenant'?card.dataset.ownPrice:card.dataset.rsPrice)||0;
        const discount=Number(card.dataset[`discount${term}`]||0);
        const monthly=Math.round(base*(1-discount/100)*100)/100;
        const channels=Math.max(1,Number(card.dataset.channels)||1);
        const total=Math.round(monthly*Number(term)*100)/100;
        const price=card.querySelector('[data-plan-price]');
        const termText=card.querySelector('[data-plan-term]');
        const unit=card.querySelector('[data-plan-unit]');
        const totalText=card.querySelector('[data-plan-total]');
        if(price)price.textContent=money(monthly);
        if(termText)termText.textContent=`Contrato mínimo de ${term} meses${discount>0?` · ${discount}% de desconto`:''}`;
        if(unit)unit.textContent=`${money(monthly/channels)} por canal`;
        if(totalText)totalText.textContent=`Total mínimo do contrato: ${money(total)}`;
        const aiLabel=card.querySelector('[data-plan-ai-label]');
        const aiValue=card.querySelector('[data-plan-ai-value]');
        const aiHint=card.querySelector('[data-plan-ai-hint]');
        const noteTitle=card.querySelector('[data-plan-ai-note-title]');
        const noteBody=card.querySelector('[data-plan-ai-note-body]');
        if(mode==='tenant'){
          if(aiLabel)aiLabel.textContent='Consumo da IA';
          if(aiValue)aiValue.textContent='Chave própria';
          if(aiHint)aiHint.textContent='cobrado diretamente pelo provedor';
          if(noteTitle)noteTitle.textContent='IA própria do cliente';
          if(noteBody)noteBody.textContent='A RS Connect monitora o uso, mas o custo dos tokens é pago pelo cliente ao provedor.';
        }else{
          if(aiLabel)aiLabel.textContent='Franquia de IA RS Connect';
          if(aiValue)aiValue.textContent=(Number(card.dataset.aiLimit)||0).toLocaleString('pt-BR');
          if(aiHint)aiHint.textContent='respostas automáticas por mês';
          if(noteTitle)noteTitle.textContent='IA fornecida pela RS Connect';
          if(noteBody)noteBody.textContent='A chave, o consumo e a franquia mensal ficam sob gestão da plataforma.';
        }
      });
    };
    commercialPricing.querySelectorAll('[data-pricing-mode]').forEach((button)=>button.addEventListener('click',()=>{mode=button.dataset.pricingMode||'rs_connect';update();}));
    commercialPricing.querySelectorAll('[data-pricing-term]').forEach((button)=>button.addEventListener('click',()=>{term=button.dataset.pricingTerm||'3';update();}));
    update();
  }
  setupSimpleDrawer({drawer:'subscription-drawer',form:'[data-subscription-form]',buttons:'[data-subscription-open]',attr:'data-subscription-field',fill:({button,drawer,form,field})=>{
    form.reset();
    field('subscription_id').value='0';
    field('billing_status').value='active';
    field('billing_cycle').value='monthly';
    field('ai_billing_mode').value='rs_connect';
    field('commitment_months').value='3';
    field('trial_days').value='7';
    field('trial_end_behavior').value='await_payment';
    field('trial_grace_days').value='3';
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
      field('ai_billing_mode').value=button.dataset.aiBillingMode||'rs_connect';
      field('commitment_months').value=button.dataset.commitmentMonths||'3';
      field('amount').value=button.dataset.amount||'';
      field('current_period_starts_at').value=button.dataset.periodStart||first;
      field('current_period_ends_at').value=button.dataset.periodEnd||last;
      field('next_billing_at').value=button.dataset.nextBilling||'';
      field('trial_ends_at').value=button.dataset.trialEnd||'';
      field('trial_days').value=button.dataset.trialDays||'7';
      field('trial_end_behavior').value=button.dataset.trialEndBehavior||'await_payment';
      field('trial_grace_days').value=button.dataset.trialGraceDays||'3';
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
  const subscriptionForm=document.querySelector('[data-subscription-form]');
  if(subscriptionForm){
    const subField=(name)=>subscriptionForm.querySelector(`[data-subscription-field="${name}"]`);
    const trialSettings=subscriptionForm.querySelector('[data-trial-settings]');
    const trialSummary=subscriptionForm.querySelector('[data-trial-summary]');
    const addDays=(iso,days)=>{if(!iso)return'';const [y,m,d]=iso.split('-').map(Number);const date=new Date(y,m-1,d);date.setDate(date.getDate()+days);return `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;};
    const formatDate=(iso)=>{if(!iso)return'—';const [y,m,d]=iso.split('-');return `${d}/${m}/${y}`;};
    const commercialSummary=subscriptionForm.querySelector('[data-subscription-commercial-summary]');
    const money=(value)=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(value||0));
    const refreshCommercial=(overwriteAmount=true)=>{
      const planSelect=subField('plan_id');
      const option=planSelect?.selectedOptions?.[0];
      const mode=subField('ai_billing_mode')?.value||'rs_connect';
      const months=String(subField('commitment_months')?.value||'3');
      if(!option||!option.value){if(commercialSummary)commercialSummary.textContent='Selecione um plano para calcular o valor.';return;}
      const base=Number(mode==='tenant'?option.dataset.ownPrice:option.dataset.rsPrice)||0;
      const discount=Number(option.dataset[`discount${months}`]||0);
      const monthly=Math.round(base*(1-discount/100)*100)/100;
      if(overwriteAmount&&subField('amount'))subField('amount').value=monthly.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
      if(commercialSummary){
        const label=mode==='tenant'?'IA própria do cliente':'IA RS Connect';
        commercialSummary.innerHTML=`<strong>${label}</strong><span>${money(monthly)}/mês · fidelidade de ${months} meses${discount>0?` · ${discount}% de desconto`:''} · total mínimo ${money(monthly*Number(months))}</span>`;
      }
    };
    const refreshTrial=()=>{
      const isTrial=subField('billing_status')?.value==='trialing';
      if(trialSettings)trialSettings.hidden=!isTrial;
      if(!isTrial)return;
      const start=subField('current_period_starts_at')?.value||'';
      const days=Math.max(1,Math.min(365,Number(subField('trial_days')?.value||7)));
      const end=addDays(start,days-1);
      const billing=addDays(end,1);
      if(subField('trial_ends_at'))subField('trial_ends_at').value=end;
      if(subField('current_period_ends_at'))subField('current_period_ends_at').value=end;
      if(subField('next_billing_at'))subField('next_billing_at').value=billing;
      const behavior=subField('trial_end_behavior')?.value||'await_payment';
      const grace=Math.max(0,Number(subField('trial_grace_days')?.value||0));
      if(trialSummary){
        const behaviorText=behavior==='activate'?'converter automaticamente para assinatura ativa':behavior==='suspend'?'suspender o acesso':'aguardar contratação/pagamento';
        trialSummary.textContent=`Teste de ${days} dia(s): ${formatDate(start)} a ${formatDate(end)}. Primeira cobrança em ${formatDate(billing)}. Depois: ${behaviorText}${behavior==='await_payment'&&grace>0?` com ${grace} dia(s) de tolerância`:''}.`;
      }
    };
    ['plan_id','ai_billing_mode','commitment_months'].forEach((name)=>{
      subField(name)?.addEventListener('change',()=>refreshCommercial(true));
    });
    ['billing_status','current_period_starts_at','trial_days','trial_end_behavior','trial_grace_days'].forEach((name)=>{
      subField(name)?.addEventListener('input',refreshTrial);
      subField(name)?.addEventListener('change',refreshTrial);
    });
    document.querySelectorAll('[data-subscription-open]').forEach((button)=>button.addEventListener('click',()=>window.setTimeout(()=>{refreshTrial();refreshCommercial(button.dataset.subscriptionOpen!=='edit');},0)));
    refreshTrial();
    refreshCommercial(false);
  }

  const autoSubscription=document.querySelector('[data-subscription-auto-open="1"]');
  if(autoSubscription){
    document.querySelector('[data-tab-target="subscriptions"]')?.click();
    window.setTimeout(()=>autoSubscription.click(),40);
  }

  document.querySelectorAll('[data-invoice-open]').forEach((button)=>button.addEventListener('click',()=>{const form=document.querySelector('[data-invoice-form]');if(!form)return;form.querySelector('[data-invoice-field="tenant_id"]').value=button.dataset.tenantId||'';form.querySelector('[data-invoice-field="subscription_id"]').value=button.dataset.subscriptionId||'';form.querySelector('[data-invoice-field="amount"]').value=button.dataset.amount||'';const title=document.querySelector('[data-invoice-title]');if(title)title.textContent=`Criar cobrança — ${button.dataset.tenantName||''}`;}));
  document.querySelectorAll('[data-payment-link-open]').forEach((button)=>button.addEventListener('click',()=>{const select=document.querySelector('[data-payment-link-invoice]');if(select&&button.dataset.invoiceId)select.value=button.dataset.invoiceId;}));
  document.querySelectorAll('[data-copy-value]').forEach((button)=>button.addEventListener('click',async()=>{const value=button.dataset.copyValue||'';if(!value)return;try{await navigator.clipboard.writeText(value);const original=button.textContent;button.textContent='Link copiado';window.setTimeout(()=>button.textContent=original,1800);}catch(error){window.prompt('Copie o link:',value);}}));
  const refreshGatewayGuidance=(drawer,editing=false)=>{
    if(!drawer)return;
    const provider=drawer.querySelector('[data-gateway-field="provider"]')?.value||'manual';
    const status=drawer.querySelector('[data-gateway-field="status"]')?.value||'inactive';
    const keyLabel=drawer.querySelector('[data-gateway-key-label]');
    const keyHint=drawer.querySelector('[data-gateway-key-hint]');
    const providerHint=drawer.querySelector('[data-gateway-provider-hint]');
    const baseUrlHint=drawer.querySelector('[data-gateway-base-url-hint]');
    const baseUrlInput=drawer.querySelector('[data-gateway-field="api_base_url"]');
    const apiKeyInput=drawer.querySelector('[data-gateway-field="api_key"]');
    const publicKeyField=drawer.querySelector('[data-gateway-public-key-field]');
    const webhookField=drawer.querySelector('[data-gateway-webhook-field]');
    const webhookLabel=drawer.querySelector('[data-gateway-webhook-label]');
    const webhookHint=drawer.querySelector('[data-gateway-webhook-hint]');
    const credentialState=drawer.querySelector('[data-gateway-credential-state]');
    const pagBankHelp=drawer.querySelector('[data-gateway-pagbank-help]');
    const webhookInput=drawer.querySelector('[data-gateway-field="webhook_secret"]');
    const isPagBank=provider==='pagbank';
    const isAsaas=provider==='asaas';
    const apiRequired=['asaas','mercadopago','stripe','pagbank'].includes(provider);
    const webhookRequired=['asaas','mercadopago','stripe','infinitepay','external'].includes(provider);
    const active=status==='active';
    const hasStoredApiKey=editing&&drawer.dataset.hasApiKey==='1';
    const hasStoredWebhookSecret=editing&&drawer.dataset.hasWebhookSecret==='1';

    if(keyLabel)keyLabel.textContent=isPagBank?'Token da API PagBank / PagSeguro':isAsaas?'API Key do Asaas':'Chave de acesso / Access Token';
    if(keyHint)keyHint.textContent=isPagBank
      ?(hasStoredApiKey?'Token já configurado. Deixe em branco para manter ou cole outro para substituir.':'Cole somente o Token da API obtido no PagBank, sem Authorization: ou Bearer.')
      :isAsaas
        ?(hasStoredApiKey?'API Key já configurada. Deixe em branco para manter ou cole a chave de Produção para substituir.':'Cole a API Key correspondente ao ambiente selecionado no Asaas.')
        :(hasStoredApiKey?'Chave já configurada. Deixe em branco para manter.':'Informe a chave do provedor.');
    if(apiKeyInput){
      apiKeyInput.required=active&&apiRequired&&!hasStoredApiKey;
      apiKeyInput.placeholder=hasStoredApiKey?'Chave já cadastrada — deixe vazio para manter':(isAsaas?'Cole a API Key do Asaas':'Cole a chave secreta fornecida pelo serviço');
      apiKeyInput.setCustomValidity('');
    }
    if(providerHint)providerHint.textContent=isPagBank
      ?'Gera links de cobrança por Pix, boleto ou cartão no Checkout PagBank.'
      :isAsaas
        ?'Use uma conta separada para Sandbox e outra para Produção. Cada ambiente possui sua própria API Key.'
        :'Configure as credenciais e a autenticação do webhook deste serviço.';
    if(baseUrlHint)baseUrlHint.textContent=(isPagBank||isAsaas)
      ?'Deixe vazio. O sistema usa automaticamente a URL oficial do ambiente selecionado.'
      :'Use somente quando o provedor exigir uma URL personalizada.';
    if(baseUrlInput){
      baseUrlInput.disabled=isAsaas;
      if(isAsaas){baseUrlInput.value='';baseUrlInput.placeholder='URL oficial definida automaticamente pelo RS Connect';}
      else if(isPagBank)baseUrlInput.placeholder='Deixe vazio para usar a URL oficial do PagBank';
      else baseUrlInput.placeholder='Deixe vazio para usar o padrão';
    }
    if(publicKeyField)publicKeyField.hidden=isAsaas||isPagBank;
    if(webhookField)webhookField.hidden=isPagBank;
    if(pagBankHelp)pagBankHelp.hidden=!isPagBank;
    if(webhookInput){
      webhookInput.disabled=isPagBank;
      webhookInput.required=!isPagBank&&active&&webhookRequired&&!hasStoredWebhookSecret;
      webhookInput.placeholder=hasStoredWebhookSecret?'Token já cadastrado — deixe vazio para manter':'Cole um token seguro para validar os webhooks';
      webhookInput.setCustomValidity('');
      if(isPagBank)webhookInput.value='';
    }
    if(webhookLabel)webhookLabel.textContent=provider==='asaas'?'authToken do webhook Asaas':provider==='stripe'?'Signing secret do endpoint Stripe':provider==='mercadopago'?'Assinatura secreta do Mercado Pago':'Segredo/token do webhook';
    if(webhookHint)webhookHint.textContent=hasStoredWebhookSecret?'Token já configurado. Deixe vazio para manter ou informe outro para substituir.':'Obrigatório para validar notificações do provedor.';
    if(credentialState){
      const missing=[];
      if(active&&apiRequired&&!hasStoredApiKey&&!apiKeyInput?.value.trim())missing.push('API Key');
      if(active&&webhookRequired&&!isPagBank&&!hasStoredWebhookSecret&&!webhookInput?.value.trim())missing.push('token do webhook');
      credentialState.textContent=missing.length
        ?`Para ativar, preencha: ${missing.join(' e ')}. Sem essas credenciais o registro será salvo como inativo.`
        :'Credenciais obrigatórias atendidas. A chave existente será mantida quando o campo ficar vazio.';
    }
  };
  setupSimpleDrawer({drawer:'gateway-drawer',form:'[data-gateway-form]',buttons:'[data-gateway-open]',attr:'data-gateway-field',fill:({button,drawer,form,field})=>{
    form.reset();drawer.dataset.hasApiKey='0';drawer.dataset.hasWebhookSecret='0';field('id').value='0';field('environment').value='production';field('status').value='inactive';field('is_default').checked=false;
    const edit=button.dataset.gatewayOpen==='edit';
    if(edit){field('id').value=button.dataset.id||'0';field('label').value=button.dataset.label||'';field('provider').value=button.dataset.provider||'manual';field('environment').value=button.dataset.environment||'production';field('status').value=button.dataset.status||'inactive';field('api_base_url').value=button.dataset.apiBaseUrl||'';field('public_key').value=button.dataset.publicKey||'';field('method').value=button.dataset.method||'UNDEFINED';field('is_default').checked=button.dataset.isDefault==='1';field('notes').value=decodeURIComponent(button.dataset.notes||'');field('api_key').value='';field('webhook_secret').value='';drawer.dataset.hasApiKey=button.dataset.hasApiKey||'0';drawer.dataset.hasWebhookSecret=button.dataset.hasWebhookSecret||'0';drawer.querySelector('[data-gateway-eyebrow]').textContent='Editar meio de pagamento';drawer.querySelector('[data-gateway-title]').textContent=button.dataset.label||'Atualizar pagamento';drawer.querySelector('[data-gateway-submit]').textContent='Salvar alterações';}
    else{drawer.querySelector('[data-gateway-eyebrow]').textContent='Novo meio de pagamento';drawer.querySelector('[data-gateway-title]').textContent='Configurar pagamento';drawer.querySelector('[data-gateway-submit]').textContent='Salvar meio de pagamento';}
    refreshGatewayGuidance(drawer,edit);
  }});
  ['provider','status','environment'].forEach((name)=>document.querySelector(`[data-gateway-field="${name}"]`)?.addEventListener('change',(event)=>{
    const drawer=event.target.closest('#gateway-drawer');
    refreshGatewayGuidance(drawer,Boolean(drawer?.querySelector('[data-gateway-field="id"]')?.value&&drawer.querySelector('[data-gateway-field="id"]')?.value!=='0'));
  }));
  ['api_key','webhook_secret'].forEach((name)=>document.querySelector(`[data-gateway-field="${name}"]`)?.addEventListener('input',(event)=>{
    const drawer=event.target.closest('#gateway-drawer');
    refreshGatewayGuidance(drawer,Boolean(drawer?.querySelector('[data-gateway-field="id"]')?.value&&drawer.querySelector('[data-gateway-field="id"]')?.value!=='0'));
  }));
  document.querySelector('[data-gateway-form]')?.addEventListener('submit',(event)=>{
    const form=event.currentTarget;
    const drawer=form.closest('#gateway-drawer');
    refreshGatewayGuidance(drawer,Boolean(drawer?.querySelector('[data-gateway-field="id"]')?.value&&drawer.querySelector('[data-gateway-field="id"]')?.value!=='0'));
    if(!form.checkValidity()){
      event.preventDefault();
      form.reportValidity();
    }
  });
  setupSimpleDrawer({drawer:'reminder-drawer',form:'[data-reminder-form]',buttons:'[data-reminder-open]',attr:'data-reminder-field',fill:({button,drawer,form,field})=>{form.reset();field('id').value='0';field('days_from_due').value='-3';field('status').value='active';field('message_template').value='Olá, {{empresa}}. Sua cobrança {{invoice_number}} no valor de {{valor}} vence em {{vencimento}}. Link: {{link_pagamento}}';const edit=button.dataset.reminderOpen==='edit';if(edit){field('id').value=button.dataset.id||'0';field('label').value=button.dataset.label||'';field('days_from_due').value=button.dataset.days||'0';field('status').value=button.dataset.status||'active';field('event_key').value=button.dataset.eventKey||'';field('channel').value=button.dataset.channel||'';field('auto_mark_overdue').checked=button.dataset.autoOverdue==='1';field('auto_suspend').checked=button.dataset.autoSuspend==='1';field('message_template').value=decodeURIComponent(button.dataset.message||'');drawer.querySelector('[data-reminder-eyebrow]').textContent='Editar regra';drawer.querySelector('[data-reminder-title]').textContent=button.dataset.label||'Atualizar aviso';drawer.querySelector('[data-reminder-submit]').textContent='Salvar alterações';}else{drawer.querySelector('[data-reminder-eyebrow]').textContent='Nova regra';drawer.querySelector('[data-reminder-title]').textContent='Criar aviso automático';drawer.querySelector('[data-reminder-submit]').textContent='Salvar regra';}}});
  setupSimpleDrawer({drawer:'user-drawer',form:'[data-user-form]',buttons:'[data-user-open]',attr:'data-user-field',fill:({button,drawer,form,field})=>{form.reset();form.action=`${window.location.origin}/users`;field('id').value='0';field('tenant_id').value='global';field('role').value='super_admin';field('password').required=true;if(field('whatsapp_signature_enabled'))field('whatsapp_signature_enabled').checked=true;drawer.querySelector('[data-user-status-field]').hidden=true;const edit=button.dataset.userOpen==='edit';if(edit){form.action=`${window.location.origin}/users/update`;field('id').value=button.dataset.id||'0';field('tenant_id').value=button.dataset.tenantId||'global';field('name').value=button.dataset.name||'';field('email').value=button.dataset.email||'';field('whatsapp_display_name').value=button.dataset.whatsappDisplayName||'';field('whatsapp_role_label').value=button.dataset.whatsappRoleLabel||'';field('whatsapp_signature_enabled').checked=button.dataset.whatsappSignatureEnabled==='1';field('role').value=button.dataset.role||'client_user';field('status').value=button.dataset.status||'active';field('password').value='';field('password').required=false;drawer.querySelector('[data-user-status-field]').hidden=false;drawer.querySelector('[data-user-eyebrow]').textContent='Editar usuário';drawer.querySelector('[data-user-title]').textContent=button.dataset.name||'Atualizar acesso';drawer.querySelector('[data-user-description]').textContent='Altere perfil, situação ou senha sem recriar o usuário.';drawer.querySelector('[data-user-password-hint]').textContent='Deixe em branco para manter a senha atual.';drawer.querySelector('[data-user-submit]').textContent='Salvar alterações';}else{drawer.querySelector('[data-user-eyebrow]').textContent='Novo usuário';drawer.querySelector('[data-user-title]').textContent='Criar acesso';drawer.querySelector('[data-user-description]').textContent='Defina a empresa, o perfil e os dados de entrada.';drawer.querySelector('[data-user-password-hint]').textContent='Obrigatória no primeiro cadastro.';drawer.querySelector('[data-user-submit]').textContent='Salvar usuário';}}});

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

/* RS Connect 36.5.4 — Equipe e acessos: cadastro e edição em drawer */
document.addEventListener('DOMContentLoaded', () => {
  const drawer = document.getElementById('client-user-drawer');
  const form = drawer?.querySelector('[data-client-user-form]');
  if (!drawer || !form) return;

  const field = (name) => form.querySelector(`[data-client-user-field="${name}"]`);
  const statusField = drawer.querySelector('[data-client-user-status-field]');
  const selfNote = drawer.querySelector('[data-client-user-self-note]');
  const eyebrow = drawer.querySelector('[data-client-user-eyebrow]');
  const title = drawer.querySelector('[data-client-user-title]');
  const description = drawer.querySelector('[data-client-user-description]');
  const passwordTitle = drawer.querySelector('[data-client-user-password-title]');
  const passwordLabel = drawer.querySelector('[data-client-user-password-label]');
  const passwordHint = drawer.querySelector('[data-client-user-password-hint]');
  const submit = drawer.querySelector('[data-client-user-submit]');

  const resetForCreate = () => {
    form.reset();
    form.action = form.dataset.storeAction || form.action;
    field('id').value = '0';
    field('role').value = 'client_user';
    field('status').value = 'active';
    field('password').value = '';
    field('password').required = true;
    if (field('whatsapp_display_name')) field('whatsapp_display_name').value = '';
    if (field('whatsapp_role_label')) field('whatsapp_role_label').value = '';
    if (field('whatsapp_signature_enabled')) field('whatsapp_signature_enabled').checked = true;
    if (statusField) statusField.hidden = true;
    if (selfNote) selfNote.hidden = true;
    if (eyebrow) eyebrow.textContent = 'Novo usuário';
    if (title) title.textContent = 'Adicionar acesso';
    if (description) description.textContent = 'Preencha os dados essenciais para liberar o acesso à sua equipe.';
    if (passwordTitle) passwordTitle.textContent = 'Senha inicial';
    if (passwordLabel) passwordLabel.textContent = 'Senha inicial';
    if (passwordHint) passwordHint.textContent = 'Obrigatória no primeiro cadastro.';
    if (submit) submit.textContent = 'Cadastrar usuário';
  };

  document.querySelectorAll('[data-client-user-open]').forEach((button) => {
    button.addEventListener('click', () => {
      const edit = button.dataset.clientUserOpen === 'edit';
      resetForCreate();

      if (edit) {
        form.action = form.dataset.updateAction || form.action;
        field('id').value = button.dataset.id || '0';
        field('name').value = button.dataset.name || '';
        field('email').value = button.dataset.email || '';
        field('role').value = button.dataset.role || 'client_user';
        field('status').value = button.dataset.status || 'active';
        if (field('whatsapp_display_name')) field('whatsapp_display_name').value = button.dataset.whatsappDisplayName || '';
        if (field('whatsapp_role_label')) field('whatsapp_role_label').value = button.dataset.whatsappRoleLabel || '';
        if (field('whatsapp_signature_enabled')) field('whatsapp_signature_enabled').checked = button.dataset.whatsappSignatureEnabled === '1';
        field('password').value = '';
        field('password').required = false;
        if (statusField) statusField.hidden = false;
        if (selfNote) selfNote.hidden = button.dataset.isSelf !== '1';
        if (eyebrow) eyebrow.textContent = 'Editar usuário';
        if (title) title.textContent = button.dataset.name || 'Atualizar acesso';
        if (description) description.textContent = 'Ajuste os dados do usuário sem abrir formulários dentro da lista.';
        if (passwordTitle) passwordTitle.textContent = 'Alterar senha';
        if (passwordLabel) passwordLabel.textContent = 'Nova senha';
        if (passwordHint) passwordHint.textContent = 'Deixe em branco para manter a senha atual.';
        if (submit) submit.textContent = 'Salvar alterações';
      }

      window.setTimeout(() => field('name')?.focus(), 80);
    });
  });

  resetForCreate();
});

/* =========================================================
   36.6.25 — Central de comunicação in-app
   ========================================================= */
(function () {
  const form = document.querySelector('[data-communication-compose]');
  const preview = document.querySelector('[data-communication-preview]');
  if (!form || !preview) return;

  const field = (name) => form.querySelector(`[data-communication-field="${name}"]`);
  const typeLabels = {
    information: 'Informação', maintenance: 'Manutenção', attention: 'Atenção', incident: 'Incidente', resolved: 'Resolvido'
  };
  const actionLabels = {
    none: 'Abrir mensagem', acknowledge: 'Confirmar leitura', reply: 'Abrir e responder'
  };
  function refreshPreview() {
    const type = field('type')?.value || 'information';
    const priority = field('priority')?.value || 'normal';
    const responseMode = field('response_mode')?.value || 'none';
    preview.dataset.priority = priority;
    const typeNode = preview.querySelector('[data-preview-type]');
    const titleNode = preview.querySelector('[data-preview-title]');
    const messageNode = preview.querySelector('[data-preview-message]');
    const actionNode = preview.querySelector('[data-preview-action]');
    if (typeNode) typeNode.textContent = `${typeLabels[type] || 'Informação'} · ${priority === 'critical' ? 'Prioridade crítica' : priority === 'important' ? 'Importante' : 'Normal'}`;
    if (titleNode) titleNode.textContent = field('title')?.value.trim() || 'Seu comunicado aparecerá aqui';
    if (messageNode) messageNode.textContent = field('message')?.value.trim() || 'Preencha título e mensagem para visualizar a experiência do cliente.';
    if (actionNode) actionNode.textContent = actionLabels[responseMode] || 'Abrir mensagem';
  }
  form.querySelectorAll('input,select,textarea').forEach((input) => input.addEventListener('input', refreshPreview));
  form.querySelectorAll('select').forEach((input) => input.addEventListener('change', refreshPreview));
  refreshPreview();
})();

(function () {
  const root = document.querySelector('[data-rs-communication-hub]');
  if (!root) return;

  const inboxUrl = root.dataset.inboxUrl || '';
  const readUrl = root.dataset.readUrl || '';
  const ackUrl = root.dataset.ackUrl || '';
  const respondUrl = root.dataset.respondUrl || '';
  const csrf = root.dataset.csrf || '';
  const floatBox = root.querySelector('[data-communication-float]');
  const bubble = root.querySelector('[data-communication-bubble]');
  const bubbleCount = root.querySelector('[data-communication-bubble-count]');
  const drawer = root.querySelector('[data-communication-drawer]');
  const backdrop = root.querySelector('[data-communication-drawer-backdrop]');
  const list = root.querySelector('[data-communication-list]');
  const thread = root.querySelector('[data-communication-thread]');
  const floatTitle = root.querySelector('[data-communication-float-title]');
  const floatMessage = root.querySelector('[data-communication-float-message]');
  const minimizedKey = 'rs-connect-communication-minimized';
  const latestSignalKey = 'rs-connect-communication-latest-signal';
  const requestedCommunicationId = Number(root.dataset.requestedCommunicationId || 0);
  let requestedCommunicationOpened = false;
  let currentPayload = { unread: 0, items: [], latest: null };
  const initialPayloadNode = root.querySelector('[data-communication-initial]');
  if (initialPayloadNode) {
    try {
      const parsedInitial = JSON.parse(initialPayloadNode.textContent || '{}');
      if (parsedInitial && typeof parsedInitial === 'object') currentPayload = parsedInitial;
    } catch (error) {
      currentPayload = { unread: 0, items: [], latest: null };
    }
  }
  let selectedId = 0;
  let polling = false;

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  const formatDate = (value) => {
    if (!value) return '';
    const parsed = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(parsed.getTime()) ? String(value) : new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(parsed);
  };
  const typeLabel = (type) => ({ information: 'Informação', maintenance: 'Manutenção', attention: 'Atenção', incident: 'Incidente', resolved: 'Resolvido' }[type] || 'Informação');
  const priorityLabel = (priority) => ({ normal: 'Normal', important: 'Importante', critical: 'Crítica' }[priority] || 'Normal');

  async function apiPost(url, fields) {
    const body = new FormData();
    body.append('_token', csrf);
    Object.entries(fields || {}).forEach(([key, value]) => body.append(key, value));
    const response = await fetch(url, {
      method: 'POST', body, credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store'
    });
    const payload = await response.json().catch(() => ({ ok: false, message: 'Resposta inválida do servidor.' }));
    if (!response.ok || !payload.ok) throw new Error(payload.message || 'Não foi possível concluir a ação.');
    return payload;
  }

  function updateFloating() {
    const unread = Number(currentPayload.unread || 0);
    root.hidden = unread < 1 && drawer?.hidden !== false;
    if (unread < 1) {
      if (floatBox) floatBox.hidden = true;
      if (bubble) bubble.hidden = true;
      return;
    }
    const latest = currentPayload.latest || currentPayload.items?.find((item) => Number(item.is_unread || 0) === 1) || null;
    if (bubbleCount) bubbleCount.textContent = String(Math.min(99, unread));
    if (floatTitle) floatTitle.textContent = latest?.title || 'Nova mensagem da RS Connect';
    if (floatMessage) floatMessage.textContent = latest?.message || 'Abra para ver os detalhes.';
    if (floatBox) {
      floatBox.classList.toggle('is-important', latest?.priority === 'important');
      floatBox.classList.toggle('is-critical', latest?.priority === 'critical');
    }
    const minimized = window.sessionStorage.getItem(minimizedKey) === '1';
    if (floatBox) floatBox.hidden = minimized;
    if (bubble) bubble.hidden = !minimized;
  }

  function renderList() {
    if (!list) return;
    const items = currentPayload.items || [];
    if (!items.length) {
      list.innerHTML = '<div class="empty-state-inline">Nenhuma mensagem ativa.</div>';
      return;
    }
    list.innerHTML = items.map((item) => `
      <button type="button" class="rs-communication-inbox-item${Number(item.is_unread || 0) === 1 ? ' is-unread' : ''}${Number(item.id) === Number(selectedId) ? ' is-active' : ''}" data-communication-item="${Number(item.id)}">
        <small>${escapeHtml(typeLabel(item.communication_type))} · ${escapeHtml(priorityLabel(item.priority))}</small>
        <strong>${escapeHtml(item.title)}</strong>
        <span>${escapeHtml(item.message)}</span>
        <small>${escapeHtml(formatDate(item.sent_at))}</small>
      </button>`).join('');
    list.querySelectorAll('[data-communication-item]').forEach((button) => {
      button.addEventListener('click', () => openThread(Number(button.dataset.communicationItem || 0)));
    });
  }

  function renderThread(data) {
    if (!thread) return;
    if (!data) {
      thread.innerHTML = '<div class="rs-communication-thread-empty">Selecione uma mensagem para ler os detalhes.</div>';
      return;
    }
    const replies = Array.isArray(data.replies) ? data.replies : [];
    const messages = [`
      <article class="rs-communication-thread-message is-rs">
        <strong>RS Connect</strong>
        <p>${escapeHtml(data.message).replace(/\n/g, '<br>')}</p>
        <small>${escapeHtml(formatDate(data.sent_at))}</small>
      </article>`, ...replies.map((reply) => `
      <article class="rs-communication-thread-message ${reply.direction === 'tenant_to_rs' ? 'is-client' : 'is-rs'}">
        <strong>${reply.direction === 'tenant_to_rs' ? escapeHtml(reply.user_name || 'Sua empresa') : 'Equipe RS'}</strong>
        <p>${escapeHtml(reply.message).replace(/\n/g, '<br>')}</p>
        <small>${escapeHtml(formatDate(reply.created_at))}</small>
      </article>`)].join('');

    let action = '<div class="rs-communication-action-status">Este comunicado é somente informativo.</div>';
    if (data.response_mode === 'acknowledge') {
      action = data.acknowledged_at
        ? '<div class="rs-communication-action-status is-success">Leitura confirmada.</div>'
        : '<button class="btn btn-primary" type="button" data-communication-ack>Confirmar leitura</button><div class="rs-communication-action-status" data-communication-action-status></div>';
    } else if (data.response_mode === 'reply') {
      action = `<form data-communication-reply-form>
        <textarea class="input" name="message" maxlength="3000" required placeholder="Escreva sua resposta para a equipe RS."></textarea>
        <button class="btn btn-primary" type="submit">Enviar resposta</button>
        <div class="rs-communication-action-status" data-communication-action-status></div>
      </form>`;
    }

    thread.innerHTML = `
      <div class="rs-communication-thread-head">
        <div class="rs-communication-thread-meta"><span class="badge">${escapeHtml(typeLabel(data.communication_type))}</span><span class="badge">Prioridade ${escapeHtml(priorityLabel(data.priority).toLowerCase())}</span></div>
        <h3>${escapeHtml(data.title)}</h3>
        <p>${data.expires_at ? `Disponível até ${escapeHtml(formatDate(data.expires_at))}.` : 'Esta mensagem permanece no histórico de notificações.'}</p>
      </div>
      <div class="rs-communication-thread-messages">${messages}</div>
      <div class="rs-communication-thread-actions">${action}</div>`;

    thread.querySelector('[data-communication-ack]')?.addEventListener('click', async (event) => {
      const button = event.currentTarget;
      const status = thread.querySelector('[data-communication-action-status]');
      button.disabled = true;
      try {
        await apiPost(ackUrl, { communication_id: data.id });
        if (status) { status.textContent = 'Leitura confirmada.'; status.classList.add('is-success'); }
        await poll(true);
        await loadThread(data.id, false);
      } catch (error) {
        if (status) { status.textContent = error.message; status.classList.add('is-error'); }
      } finally { button.disabled = false; }
    });

    thread.querySelector('[data-communication-reply-form]')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const replyForm = event.currentTarget;
      const textarea = replyForm.querySelector('textarea');
      const button = replyForm.querySelector('button[type="submit"]');
      const status = replyForm.querySelector('[data-communication-action-status]');
      button.disabled = true;
      try {
        const result = await apiPost(respondUrl, { communication_id: data.id, message: textarea.value });
        textarea.value = '';
        if (status) { status.textContent = result.message || 'Resposta enviada.'; status.classList.add('is-success'); }
        await poll(true);
        renderThread(result.thread || data);
      } catch (error) {
        if (status) { status.textContent = error.message; status.classList.add('is-error'); }
      } finally { button.disabled = false; }
    });
  }

  async function loadThread(id, markRead = true) {
    if (!id) return;
    selectedId = id;
    try {
      if (markRead) await apiPost(readUrl, { communication_id: id });
      const response = await fetch(`${inboxUrl}?communication_id=${encodeURIComponent(id)}`, {
        credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store'
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Não foi possível abrir a mensagem.');
      currentPayload = payload;
      renderList();
      renderThread(payload.thread || null);
      updateFloating();
    } catch (error) {
      if (thread) thread.innerHTML = `<div class="rs-communication-thread-empty">${escapeHtml(error.message || 'Não foi possível abrir a mensagem.')}</div>`;
    }
  }

  function openDrawer(id) {
    root.hidden = false;
    if (drawer) drawer.hidden = false;
    if (backdrop) backdrop.hidden = false;
    document.body.classList.add('has-communication-drawer');
    renderList();
    const target = Number(id || currentPayload.latest?.id || currentPayload.items?.[0]?.id || 0);
    if (target > 0) loadThread(target, true);
  }
  function closeDrawer() {
    if (drawer) drawer.hidden = true;
    if (backdrop) backdrop.hidden = true;
    document.body.classList.remove('has-communication-drawer');
    updateFloating();
  }

  async function poll(force = false) {
    if (polling && !force) return;
    polling = true;
    try {
      const response = await fetch(inboxUrl, {
        credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store'
      });
      if (!response.ok) return;
      const payload = await response.json();
      if (!payload?.ok) return;
      currentPayload = payload;
      const latest = payload.latest || null;
      const signal = latest ? `${latest.id}:${latest.last_reply_at || latest.sent_at || ''}` : '';
      const previousSignal = window.sessionStorage.getItem(latestSignalKey) || '';
      if (signal && signal !== previousSignal) {
        window.sessionStorage.setItem(latestSignalKey, signal);
        window.sessionStorage.removeItem(minimizedKey);
      }
      renderList();
      updateFloating();
    } catch (error) {
      // A Central continua disponível pelo histórico de notificações se o polling falhar.
    } finally { polling = false; }
  }

  root.querySelector('[data-communication-open]')?.addEventListener('click', () => openDrawer(currentPayload.latest?.id));
  root.querySelector('[data-communication-bubble]')?.addEventListener('click', () => { window.sessionStorage.removeItem(minimizedKey); openDrawer(currentPayload.latest?.id); });
  root.querySelector('[data-communication-minimize]')?.addEventListener('click', () => { window.sessionStorage.setItem(minimizedKey, '1'); updateFloating(); });
  root.querySelector('[data-communication-close]')?.addEventListener('click', closeDrawer);
  backdrop?.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && drawer && !drawer.hidden) closeDrawer(); });

  renderList();
  updateFloating();
  if (requestedCommunicationId > 0 && !requestedCommunicationOpened) {
    requestedCommunicationOpened = true;
    openDrawer(requestedCommunicationId);
  }
  poll();
  window.setInterval(() => { if (document.visibilityState === 'visible') poll(); }, 10000);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') poll(true); });
})();

(function () {
  function refreshDay(row) {
    const toggle = row.querySelector('[data-business-day-toggle]');
    const enabled = Boolean(toggle?.checked);
    row.classList.toggle('is-enabled', enabled);
    row.querySelectorAll('[data-business-day-time]').forEach((input) => {
      input.disabled = !enabled;
      input.setAttribute('aria-disabled', enabled ? 'false' : 'true');
    });
  }

  document.querySelectorAll('[data-business-hours-editor]').forEach((editor) => {
    editor.querySelectorAll('[data-business-hours-day]').forEach((row) => {
      refreshDay(row);
      row.querySelector('[data-business-day-toggle]')?.addEventListener('change', () => refreshDay(row));
    });
  });
})();

(function () {
  document.querySelectorAll('[data-contact-filter-form]').forEach((form) => {
    const search = form.querySelector('[data-contact-search]');
    if (!search) return;

    let timer = null;
    let lastSubmitted = String(search.value || '').trim();

    const submit = () => {
      const current = String(search.value || '').trim();
      if (current === lastSubmitted) return;
      lastSubmitted = current;
      form.classList.add('is-searching');
      form.requestSubmit();
    };

    search.addEventListener('input', () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(submit, 450);
    });

    search.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter') return;
      window.clearTimeout(timer);
      lastSubmitted = '__force_submit__';
    });

    form.querySelectorAll('select').forEach((select) => {
      select.addEventListener('change', () => {
        form.classList.add('is-searching');
        form.requestSubmit();
      });
    });
  });
})();

// Prompt Studio 36.6.35 — geração determinística e revisão antes de criar o agente.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-prompt-studio]').forEach(function (form) {
        var button = form.querySelector('[data-prompt-generate]');
        var status = form.querySelector('[data-prompt-status]');
        var output = form.querySelector('textarea[name="system_prompt"]');
        var warningsBox = form.querySelector('[data-prompt-warnings]');
        var generated = form.querySelector('[data-prompt-generated]');
        var answersField = form.querySelector('[data-prompt-answers]');
        var warningsField = form.querySelector('[data-prompt-warnings-json]');
        if (!button || !output) return;

        var value = function (name) {
            var field = form.querySelector('[name="' + name + '"]');
            return field ? String(field.value || '').trim() : '';
        };
        var renderWarnings = function (warnings) {
            if (!warningsBox) return;
            warningsBox.innerHTML = '';
            if (!Array.isArray(warnings) || warnings.length === 0) {
                warningsBox.hidden = false;
                warningsBox.innerHTML = '<div class="prompt-studio-alert is-success"><strong>Estrutura validada</strong><span>Nenhum conflito operacional foi encontrado.</span></div>';
                return;
            }
            warnings.forEach(function (warning) {
                var item = document.createElement('div');
                item.className = 'prompt-studio-alert is-' + (warning.level || 'info');
                var strong = document.createElement('strong');
                strong.textContent = warning.level === 'warning' ? 'Atenção' : (warning.level === 'attention' ? 'Revisar' : 'Informação');
                var span = document.createElement('span');
                span.textContent = warning.message || '';
                item.appendChild(strong);
                item.appendChild(span);
                warningsBox.appendChild(item);
            });
            warningsBox.hidden = false;
        };

        button.addEventListener('click', async function () {
            var endpoint = form.getAttribute('data-prompt-endpoint');
            if (!endpoint) return;
            var agentName = value('name');
            var role = value('segment');
            var objective = value('service_objective');
            if (!agentName || !role || !objective) {
                if (status) status.textContent = 'Preencha nome, área e objetivo antes de gerar.';
                return;
            }

            button.disabled = true;
            if (status) status.textContent = 'Organizando instruções e validando conflitos...';
            try {
                var data = new FormData(form);
                data.set('agent_name', agentName);
                data.set('role', role);
                data.set('objective', objective);
                data.set('tone', value('tone_of_voice'));
                data.set('custom_rules', value('assistant_rules'));
                var response = await fetch(endpoint, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                var payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Falha ao gerar o prompt.');
                output.value = payload.prompt || '';
                if (generated) generated.value = '1';
                if (answersField) answersField.value = JSON.stringify(payload.answers || {});
                if (warningsField) warningsField.value = JSON.stringify(payload.warnings || []);
                renderWarnings(payload.warnings || []);
                if (status) status.textContent = 'Prompt gerado. Revise o conteúdo antes de criar o assistente.';
                output.dispatchEvent(new Event('input', { bubbles: true }));
            } catch (error) {
                if (status) status.textContent = error && error.message ? error.message : 'Não foi possível gerar o prompt.';
            } finally {
                button.disabled = false;
            }
        });
    });
});

/* =========================================================
   36.6.38 — Evolution: status exclusivamente em tempo real
   ========================================================= */
(function () {
  const cards = Array.from(document.querySelectorAll('[data-instance-status-card]'));
  if (cards.length === 0) return;

  let running = false;
  let timer = 0;
  const statusEndpoint = cards[0].dataset.statusEndpoint || '/instances/status-feed';

  function classForStatus(status) {
    if (status === 'connected') return 'connected';
    if (status === 'pending') return 'pending';
    return 'disconnected';
  }

  function formatDetail(item) {
    const parts = [];
    if (item.connection_state) parts.push(item.connection_state);
    if (item.profile_name) parts.push(item.profile_name);
    if (item.profile_phone) parts.push(item.profile_phone);
    if (item.reason) parts.push(item.reason);
    return parts.join(' · ') || 'Aguardando atualização da Evolution';
  }

  function applyItem(item) {
    const card = cards.find((candidate) => Number(candidate.dataset.instanceId || 0) === Number(item.id || 0));
    if (!card) return;

    card.dataset.status = item.status || 'disconnected';
    const badge = card.querySelector('[data-instance-status-badge]');
    if (badge) {
      badge.textContent = item.status_label || 'Desconectado';
      badge.classList.remove('badge-connected', 'badge-pending', 'badge-disconnected');
      badge.classList.add('badge-' + classForStatus(item.status));
    }
    const detail = card.querySelector('[data-instance-status-detail]');
    if (detail) detail.textContent = formatDetail(item);

    const qrForm = card.querySelector('[data-qr-code-form]');
    const connectedNote = card.querySelector('.channel-connected-note');
    if (item.status === 'connected') {
      if (qrForm) qrForm.hidden = true;
      if (connectedNote) connectedNote.hidden = false;
    } else {
      if (qrForm) qrForm.hidden = false;
      if (connectedNote) connectedNote.hidden = true;
    }

    const modal = document.querySelector('[data-qr-code-modal]');
    if (modal && !modal.hidden && Number(modal.dataset.instanceId || 0) === Number(item.id || 0)) {
      if (item.status === 'connected') {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('has-modal-open');
        if (typeof window.showToast === 'function') window.showToast('WhatsApp conectado com sucesso.');
        else document.dispatchEvent(new CustomEvent('rs:toast', { detail: { message: 'WhatsApp conectado com sucesso.' } }));
      } else if (item.qr_ready && item.qr_code) {
        const image = modal.querySelector('[data-qr-image]');
        const loading = modal.querySelector('[data-qr-loading]');
        if (loading) loading.hidden = true;
        if (image) {
          image.src = item.qr_code;
          image.hidden = false;
        }
      }
    }
  }

  async function poll() {
    const deleteDrawer = document.getElementById('instance-delete-drawer');
    if (running || document.hidden || deleteDrawer?.classList.contains('is-open')) return;
    running = true;
    try {
      const response = await fetch(statusEndpoint, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false) return;
      (payload.items || []).forEach(applyItem);
    } catch (_) {
      // A tela mantém o último estado conhecido e tenta novamente.
    } finally {
      running = false;
    }
  }

  function schedule() {
    window.clearTimeout(timer);
    timer = window.setTimeout(async () => {
      await poll();
      schedule();
    }, 3500);
  }

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) poll();
  });
  poll();
  schedule();
})();

/* RS Connect 36.8.2 — visualizações Dia, Semana e Mês da agenda */
(() => {
  const viewToolbar = document.querySelector('[data-calendar-preference-key]');
  const preferenceKey = String(viewToolbar?.dataset.calendarPreferenceKey || 'rs_calendar_view_0');
  const viewLinks = document.querySelectorAll('[data-calendar-view-link]');
  viewLinks.forEach((link) => {
    link.addEventListener('click', () => {
      const view = String(link.dataset.calendarViewLink || 'list');
      try { window.localStorage.setItem(preferenceKey, view); } catch (_) {}
      document.cookie = `${encodeURIComponent(preferenceKey)}=${encodeURIComponent(view)}; path=/; max-age=31536000; SameSite=Lax`;
    });
  });

  const board = document.querySelector('[data-calendar-board]');
  if (!board) return;

  const source = board.querySelector('[data-calendar-events]');
  const content = board.querySelector('[data-calendar-content]');
  const loading = board.querySelector('[data-calendar-loading]');
  const dialog = document.querySelector('[data-calendar-event-dialog]');
  if (!source || !content) return;

  let events = [];
  try {
    const parsed = JSON.parse(source.textContent || '[]');
    events = Array.isArray(parsed) ? parsed : [];
  } catch (_) {
    events = [];
  }

  const parseLocalDate = (value) => {
    const normalized = String(value || '').trim();
    if (!normalized) return null;
    const date = new Date(normalized.length === 10 ? `${normalized}T00:00:00` : normalized);
    return Number.isNaN(date.getTime()) ? null : date;
  };
  const dateKey = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  };
  const sameDate = (event, date) => {
    const startsAt = parseLocalDate(event.starts_at);
    return startsAt ? dateKey(startsAt) === dateKey(date) : false;
  };
  const formatTime = (value) => {
    const date = parseLocalDate(value);
    return date ? new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(date) : '—';
  };
  const formatDate = (value, options = {}) => {
    const date = value instanceof Date ? value : parseLocalDate(value);
    return date ? new Intl.DateTimeFormat('pt-BR', options).format(date) : '—';
  };
  const formatPeriod = (event) => {
    const start = parseLocalDate(event.starts_at);
    const end = parseLocalDate(event.ends_at);
    if (!start) return 'Horário não definido';
    const dateLabel = formatDate(start, { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
    return `${dateLabel} · ${formatTime(start)}${end ? ` às ${formatTime(end)}` : ''}`;
  };
  const statusClass = (status) => String(status || 'scheduled').replace(/[^a-z0-9_-]/gi, '');
  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  events = events
    .map((event) => ({ ...event, _start: parseLocalDate(event.starts_at), _end: parseLocalDate(event.ends_at) }))
    .filter((event) => event._start)
    .sort((a, b) => a._start - b._start);

  const openEvent = (event) => {
    if (!dialog) return;
    const setText = (selector, value) => {
      const target = dialog.querySelector(selector);
      if (target) target.textContent = value;
    };
    setText('[data-calendar-dialog-status]', event.status_label || 'Agendamento');
    setText('[data-calendar-dialog-title]', event.title || 'Compromisso');
    setText('[data-calendar-dialog-time]', formatPeriod(event));
    setText('[data-calendar-dialog-contact]', event.contact_name || 'Sem contato');
    setText('[data-calendar-dialog-owner]', event.owner_name || 'Não definido');
    setText('[data-calendar-dialog-location]', event.location_label || 'A definir');
    setText('[data-calendar-dialog-description]', event.description || 'Sem descrição.');
    const openLink = dialog.querySelector('[data-calendar-dialog-open]');
    const googleLink = dialog.querySelector('[data-calendar-dialog-google]');
    if (openLink) openLink.href = event.list_url || '#';
    if (googleLink) googleLink.href = event.google_url || '#';
    dialog.className = `calendar-event-dialog calendar-dialog-status-${statusClass(event.status)}`;
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  };

  const eventButton = (event, compact = false) => {
    const button = element('button', `calendar-event-card calendar-event-${statusClass(event.status)}${compact ? ' is-compact' : ''}`);
    button.type = 'button';
    button.dataset.calendarEventId = String(event.id || '');
    button.setAttribute('aria-label', `${formatTime(event.starts_at)}. ${event.title}. ${event.contact_name}. ${event.owner_name}.`);

    const time = element('span', 'calendar-event-time', compact ? formatTime(event.starts_at) : `${formatTime(event.starts_at)} – ${formatTime(event.ends_at)}`);
    const title = element('strong', 'calendar-event-title', event.title || 'Compromisso');
    const contact = element('span', 'calendar-event-contact', event.contact_name || 'Sem contato');
    button.append(time, title);
    if (!compact) {
      button.append(contact, element('span', 'calendar-event-owner', event.owner_name || 'Não definido'));
      button.append(element('span', 'calendar-event-status', event.status_label || 'Agendado'));
    }
    button.addEventListener('click', () => openEvent(event));
    return button;
  };

  const emptyState = (message) => element('div', 'calendar-visual-empty', message);

  const renderDay = () => {
    const anchor = parseLocalDate(board.dataset.calendarAnchor) || new Date();
    const dayEvents = events.filter((event) => sameDate(event, anchor));
    const wrapper = element('div', 'calendar-day-view');
    const heading = element('div', 'calendar-day-heading');
    heading.append(
      element('strong', '', formatDate(anchor, { weekday: 'long', day: '2-digit', month: 'long' })),
      element('span', '', `${dayEvents.length} compromisso${dayEvents.length === 1 ? '' : 's'}`)
    );
    wrapper.appendChild(heading);
    const list = element('div', 'calendar-day-events');
    dayEvents.forEach((event) => list.appendChild(eventButton(event)));
    wrapper.appendChild(dayEvents.length ? list : emptyState('Nenhum compromisso neste dia.'));
    return wrapper;
  };

  const renderWeek = () => {
    const rangeStart = parseLocalDate(board.dataset.calendarRangeStart) || new Date();
    const wrapper = element('div', 'calendar-week-scroll');
    const grid = element('div', 'calendar-week-grid');
    for (let offset = 0; offset < 7; offset += 1) {
      const day = new Date(rangeStart);
      day.setDate(rangeStart.getDate() + offset);
      const dayEvents = events.filter((event) => sameDate(event, day));
      const column = element('section', `calendar-week-day${dateKey(day) === dateKey(new Date()) ? ' is-today' : ''}`);
      const header = element('header', 'calendar-week-day-header');
      header.append(
        element('span', '', formatDate(day, { weekday: 'short' })),
        element('strong', '', formatDate(day, { day: '2-digit', month: 'short' })),
        element('small', '', String(dayEvents.length))
      );
      column.appendChild(header);
      const list = element('div', 'calendar-week-events');
      dayEvents.forEach((event) => list.appendChild(eventButton(event)));
      column.appendChild(dayEvents.length ? list : emptyState('Livre'));
      grid.appendChild(column);
    }
    wrapper.appendChild(grid);
    return wrapper;
  };

  const renderMonth = () => {
    const rangeStart = parseLocalDate(board.dataset.calendarRangeStart) || new Date();
    const rangeEnd = parseLocalDate(board.dataset.calendarRangeEnd) || rangeStart;
    const anchor = parseLocalDate(board.dataset.calendarAnchor) || new Date();
    const wrapper = element('div', 'calendar-month-scroll');
    const grid = element('div', 'calendar-month-grid');
    ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'].forEach((label) => grid.appendChild(element('div', 'calendar-month-weekday', label)));
    const cursor = new Date(rangeStart);
    while (cursor <= rangeEnd) {
      const day = new Date(cursor);
      const dayEvents = events.filter((event) => sameDate(event, day));
      const cell = element('section', 'calendar-month-day');
      if (day.getMonth() !== anchor.getMonth()) cell.classList.add('is-outside');
      if (dateKey(day) === dateKey(new Date())) cell.classList.add('is-today');
      const header = element('header', 'calendar-month-day-header');
      header.appendChild(element('strong', '', String(day.getDate())));
      if (dayEvents.length) header.appendChild(element('small', '', `${dayEvents.length}`));
      cell.appendChild(header);
      const list = element('div', 'calendar-month-events');
      dayEvents.slice(0, 3).forEach((event) => list.appendChild(eventButton(event, true)));
      if (dayEvents.length > 3) list.appendChild(element('span', 'calendar-month-more', `+${dayEvents.length - 3} compromisso(s)`));
      cell.appendChild(list);
      grid.appendChild(cell);
      cursor.setDate(cursor.getDate() + 1);
    }
    wrapper.appendChild(grid);
    return wrapper;
  };

  const view = String(board.dataset.calendarView || 'week');
  const rendered = view === 'day' ? renderDay() : (view === 'month' ? renderMonth() : renderWeek());
  content.replaceChildren(rendered);
  content.hidden = false;
  if (loading) loading.hidden = true;

  dialog?.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });
})();

// RS Connect 36.17.2 — mantém a inicial como fallback quando uma foto estática expira.
document.querySelectorAll('[data-static-contact-avatar]').forEach((image) => {
  const hideBrokenImage = () => image.remove();
  image.addEventListener('error', hideBrokenImage, { once: true });
  if (image.complete && image.naturalWidth < 1) hideBrokenImage();
});


// RS Connect 36.18.1 — busca rápida de módulos no cabeçalho.
document.addEventListener('DOMContentLoaded', () => {
  const shell = document.querySelector('[data-module-search]');
  if (!shell) return;
  const input = shell.querySelector('[data-module-search-input]');
  const results = shell.querySelector('[data-module-search-results]');
  if (!input || !results) return;
  const links = Array.from(document.querySelectorAll('.sidebar-nav a.nav-link')).map((link) => ({
    label: (link.querySelector('span')?.textContent || link.textContent || '').trim(),
    href: link.getAttribute('href') || '#'
  })).filter((item, index, all) => item.label && all.findIndex((x) => x.href === item.href) === index);
  const normalize = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const close = () => { results.hidden = true; results.innerHTML = ''; };
  const render = () => {
    const query = normalize(input.value.trim());
    if (!query) { close(); return; }
    const matches = links.filter((item) => normalize(item.label).includes(query)).slice(0, 8);
    results.innerHTML = matches.length
      ? matches.map((item, i) => `<a class="topbar-search-result${i === 0 ? ' is-active' : ''}" href="${item.href}"><span>${item.label.replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}</span><small>Abrir</small></a>`).join('')
      : '<div class="topbar-search-empty">Nenhum módulo encontrado.</div>';
    results.hidden = false;
  };
  input.addEventListener('input', render);
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') { close(); input.blur(); }
    if (event.key === 'Enter') {
      const first = results.querySelector('a');
      if (first) { event.preventDefault(); window.location.href = first.href; }
    }
  });
  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault(); shell.classList.add('is-open'); input.focus(); input.select();
    }
  });
  document.addEventListener('click', (event) => { if (!shell.contains(event.target)) close(); });
});

// RS Connect 36.18.2 — mantém vínculo e principal do canal coerentes no editor do assistente.
document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;
    const option = input.closest('.agent-channel-option');
    if (!option) return;

    const linkInput = option.querySelector('input[name="instance_ids[]"]');
    const primaryInput = option.querySelector('input[name="primary_instance_ids[]"]');
    if (!(linkInput instanceof HTMLInputElement) || !(primaryInput instanceof HTMLInputElement)) return;

    if (input === primaryInput && primaryInput.checked) {
        linkInput.checked = true;
    }
    if (input === linkInput && !linkInput.checked) {
        primaryInput.checked = false;
    }
});

// RS Connect 36.20.1 — camada de linguagem simples para textos estáticos e conteúdos carregados dinamicamente.
(() => {
  const exactLabels = new Map([
    ['attention', 'Precisa de atenção'],
    ['healthy', 'Dentro do esperado'],
    ['critical', 'Atenção urgente'],
    ['loss', 'Custos acima da receita'],
    ['unconfigured', 'Dados incompletos'],
    ['Fallback geral', 'Assistente de apoio'],
    ['Webhook', 'Atualizações automáticas'],
    ['Webhooks', 'Atualizações automáticas'],
    ['Prompt', 'Instruções do assistente'],
    ['Tokens', 'Unidades de uso da IA'],
    ['Token', 'Chave de segurança'],
    ['Credenciais', 'Chaves de acesso'],
    ['Credencial', 'Chave de acesso'],
    ['Provedor', 'Serviço'],
    ['Gateway', 'Meio de pagamento'],
    ['Gateways', 'Meios de pagamento'],
    ['Instância', 'Conexão'],
    ['Instâncias', 'Conexões'],
    ['Governança', 'Controle'],
    ['Rentabilidade', 'Resultado financeiro'],
    ['MRR estimado', 'Receita mensal estimada'],
    ['MRR', 'Receita mensal'],
  ]);

  const phraseReplacements = [
    [/\btelemetria técnica\b/giu, 'dados detalhados de uso'],
    [/\btelemetria\b/giu, 'dados de uso'],
    [/\bgovernança\b/giu, 'controle'],
    [/\brentabilidade\b/giu, 'resultado financeiro'],
    [/\bMRR\b/g, 'receita mensal'],
    [/\bfallback geral\b/giu, 'assistente de apoio'],
    [/\bfallback interno\b/giu, 'opção interna de apoio'],
    [/\bfallback\b/giu, 'opção de apoio'],
    [/\bwebhooks?\b/giu, 'atualizações automáticas'],
    [/\bAdmin API Key\b/giu, 'chave administrativa'],
    [/\bAPI Key\b/giu, 'chave de acesso'],
    [/\bcredenciais?\b/giu, 'chaves de acesso'],
    [/\bprovedores\b/giu, 'serviços'],
    [/\bprovedor\b/giu, 'serviço'],
    [/\bgateways?\b/giu, 'meios de pagamento'],
    [/\bprompt studio\b/giu, 'criador de instruções'],
    [/\bprompts?\b/giu, 'instruções do assistente'],
    [/\btokens de entrada\b/giu, 'informações processadas'],
    [/\btokens de saída\b/giu, 'respostas geradas'],
    [/\btokens em cache\b/giu, 'informações reaproveitadas'],
    [/\btotal de tokens\b/giu, 'uso total da IA'],
    [/\btokens?\b/giu, 'unidades de uso da IA'],
    [/\bcache exato\b/giu, 'respostas reaproveitadas'],
    [/\bcache\b/giu, 'dados reaproveitados'],
    [/\bmigrations?\b/giu, 'atualizações do banco'],
    [/\bcron\b/giu, 'rotina automática'],
    [/\bendpoints?\b/giu, 'endereços do serviço'],
    [/\bpayloads?\b/giu, 'dados enviados'],
    [/\btenant_id\b/giu, 'identificador da empresa'],
    [/\btenants?\b/giu, 'empresas'],
    [/\bworkers?\b/giu, 'rotinas automáticas'],
    [/\bruntime\b/giu, 'execução'],
    [/\bthresholds?\b/giu, 'limites'],
    [/\bthroughput\b/giu, 'volume processado'],
    [/\bprovisionamento\b/giu, 'criação da conexão'],
    [/\binstâncias?\b/giu, 'conexões'],
    [/\bfranquia de IA\b/giu, 'limite de respostas de IA'],
    [/\bfranquia RS\b/giu, 'limite de IA pago pela RS'],
  ];

  const shouldSkip = (node) => {
    const parent = node.parentElement;
    return !parent || Boolean(parent.closest('code, pre, script, style, textarea, [data-keep-technical-language]'));
  };

  const translateText = (value) => {
    const trimmed = String(value || '').trim();
    if (!trimmed) return value;
    if (exactLabels.has(trimmed)) {
      return String(value).replace(trimmed, exactLabels.get(trimmed));
    }
    let translated = String(value);
    phraseReplacements.forEach(([pattern, replacement]) => {
      translated = translated.replace(pattern, replacement);
    });
    return translated;
  };

  const translateAttributes = (root) => {
    const elements = [];
    if (root instanceof Element) elements.push(root);
    if (root instanceof Document || root instanceof DocumentFragment || root instanceof Element) {
      elements.push(...root.querySelectorAll('[placeholder], [title], [aria-label]'));
    }
    elements.forEach((element) => {
      ['placeholder', 'title', 'aria-label'].forEach((attribute) => {
        if (!element.hasAttribute(attribute) || element.closest('[data-keep-technical-language]')) return;
        const current = element.getAttribute(attribute) || '';
        const translated = translateText(current);
        if (translated !== current) element.setAttribute(attribute, translated);
      });
    });
  };

  const applyToRoot = (root) => {
    if (!(root instanceof Node)) return;
    translateAttributes(root);
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((node) => {
      if (shouldSkip(node)) return;
      const translated = translateText(node.nodeValue);
      if (translated !== node.nodeValue) node.nodeValue = translated;
    });
  };

  const start = () => {
    applyToRoot(document.body);
    const observer = new MutationObserver((records) => {
      records.forEach((record) => {
        if (record.type === 'characterData') applyToRoot(record.target.parentElement || record.target);
        record.addedNodes.forEach(applyToRoot);
      });
    });
    observer.observe(document.body, { childList: true, characterData: true, subtree: true });
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();

// RS Connect 36.20.5 — ajuda contextual, onboarding e acessibilidade.
(function () {
  const ready = (callback) => {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', callback, { once: true });
    else callback();
  };

  ready(() => {
    const drawer = document.querySelector('[data-context-help-drawer]');
    const backdrop = document.querySelector('[data-context-help-backdrop]');
    const openButtons = document.querySelectorAll('[data-context-help-open]');
    const closeButtons = document.querySelectorAll('[data-context-help-close]');
    const live = document.querySelector('[data-app-live-region]');
    const main = document.getElementById('main-content');
    const readingButton = document.querySelector('[data-reading-mode-toggle]');
    const motionButton = document.querySelector('[data-reduced-motion-toggle]');
    let lastFocused = null;

    const announce = (message) => {
      if (!live) return;
      live.textContent = '';
      window.setTimeout(() => { live.textContent = message; }, 20);
    };

    const focusable = () => drawer
      ? Array.from(drawer.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
        .filter((element) => !element.hidden && element.offsetParent !== null)
      : [];

    const openHelp = () => {
      if (!drawer || !backdrop) return;
      lastFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      drawer.hidden = false;
      backdrop.hidden = false;
      document.body.classList.add('context-help-open');
      window.requestAnimationFrame(() => {
        drawer.classList.add('is-open');
        backdrop.classList.add('is-open');
        focusable()[0]?.focus();
      });
      announce('Ajuda desta página aberta.');
      try { window.localStorage.setItem('rs-context-help-seen', '1'); } catch (_) {}
    };

    const closeHelp = () => {
      if (!drawer || !backdrop || drawer.hidden) return;
      drawer.classList.remove('is-open');
      backdrop.classList.remove('is-open');
      document.body.classList.remove('context-help-open');
      window.setTimeout(() => {
        drawer.hidden = true;
        backdrop.hidden = true;
      }, 180);
      lastFocused?.focus?.();
      announce('Ajuda fechada.');
    };

    openButtons.forEach((button) => button.addEventListener('click', openHelp));
    closeButtons.forEach((button) => button.addEventListener('click', closeHelp));
    backdrop?.addEventListener('click', closeHelp);

    document.addEventListener('keydown', (event) => {
      const target = event.target;
      const editing = target instanceof HTMLElement && Boolean(target.closest('input, textarea, select, [contenteditable="true"]'));
      if (event.key === '?' && !editing && drawer?.hidden) {
        event.preventDefault();
        openHelp();
        return;
      }
      if (event.key === 'Escape' && drawer && !drawer.hidden) {
        event.preventDefault();
        closeHelp();
        return;
      }
      if (event.key === 'Tab' && drawer && !drawer.hidden) {
        const items = focusable();
        if (!items.length) return;
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    });

    document.querySelectorAll('.nav-link.is-active').forEach((link) => link.setAttribute('aria-current', 'page'));
    document.querySelector('.skip-link')?.addEventListener('click', () => window.setTimeout(() => main?.focus(), 10));

    const applyPreference = (button, storageKey, htmlClass) => {
      if (!button) return;
      let active = false;
      try { active = window.localStorage.getItem(storageKey) === '1'; } catch (_) {}
      const render = () => {
        document.documentElement.classList.toggle(htmlClass, active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      };
      button.addEventListener('click', () => {
        active = !active;
        try { window.localStorage.setItem(storageKey, active ? '1' : '0'); } catch (_) {}
        render();
        announce(active ? 'Preferência ativada.' : 'Preferência desativada.');
      });
      render();
    };

    applyPreference(readingButton, 'rs-reading-comfort', 'reading-comfort');
    applyPreference(motionButton, 'rs-reduce-motion', 'reduce-motion');

    // Pequena orientação exibida apenas uma vez, sem bloquear o trabalho.
    try {
      if (openButtons.length && window.localStorage.getItem('rs-context-help-seen') !== '1') {
        const button = openButtons[0];
        button.classList.add('has-help-hint');
        button.setAttribute('title', 'Ajuda desta página — clique aqui ou pressione ?');
      }
    } catch (_) {}
  });
})();

// RS Connect 36.20.6 — exclusão assistida detecta conexão externa ausente.

// RS Connect 36.20.7 — exclusão local clara, com transferência segura quando houver vínculos.

// RS Connect 36.20.8 — prévia de exclusão sem bloqueio de sessão e com timeout visível.
// RS Connect 36.20.9 — exclusão sem conexão substituta com confirmação destrutiva explícita.

// Marcador histórico da v36.20.6: botão "Remover do RS Connect" substituído por textos mais claros na v36.20.7.

// Marcador histórico v36.20.6: 'Remover do RS Connect'.

/* ENT-030 — ações declarativas compatíveis com CSP sem JavaScript inline. */
document.addEventListener('DOMContentLoaded', () => {
  document.addEventListener('submit', (event) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    if (!form || !form.dataset.confirm) return;
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  }, true);

  document.addEventListener('change', (event) => {
    const field = event.target instanceof Element ? event.target.closest('[data-auto-submit]') : null;
    if (!(field instanceof HTMLSelectElement) || !field.form) return;
    if (typeof field.form.requestSubmit === 'function') field.form.requestSubmit();
    else field.form.submit();
  });

  document.addEventListener('click', (event) => {
    const actionTarget = event.target instanceof Element ? event.target.closest('[data-page-action]') : null;
    if (actionTarget) {
      event.preventDefault();
      if (actionTarget.dataset.pageAction === 'reload') window.location.reload();
      if (actionTarget.dataset.pageAction === 'print') window.print();
      return;
    }

    const submitTarget = event.target instanceof Element ? event.target.closest('[data-submit-form]') : null;
    if (!submitTarget) return;
    const formId = submitTarget.dataset.submitForm || '';
    const form = formId ? document.getElementById(formId) : null;
    if (form instanceof HTMLFormElement) {
      event.preventDefault();
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
    }
  });
});

/* RS Connect 36.20.15.2 — prévia de cores do White Label sem handlers inline. */
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-white-label-form]');
  const preview = document.querySelector('.white-label-preview-card');
  if (!(form instanceof HTMLFormElement) || !(preview instanceof HTMLElement)) return;

  form.querySelectorAll('input[type="color"][data-preview-color]').forEach((field) => {
    if (!(field instanceof HTMLInputElement)) return;
    const output = field.closest('.white-label-color-card')?.querySelector('output');
    const refresh = () => {
      const value = String(field.value || '').toUpperCase();
      if (output instanceof HTMLOutputElement) output.value = value;
      const property = field.dataset.previewColor || '';
      if (property) preview.style.setProperty(property, field.value);
    };
    field.addEventListener('input', refresh);
    field.addEventListener('change', refresh);
    refresh();
  });
});
