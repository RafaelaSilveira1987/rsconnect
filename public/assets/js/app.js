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
