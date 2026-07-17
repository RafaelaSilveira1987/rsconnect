/* ZIP 34.1 — comportamento autônomo dos relatórios */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.executive-report-page [data-tabs]').forEach((tabs) => {
    if (tabs.dataset.reportTabsBound === '1') return;
    tabs.dataset.reportTabsBound = '1';
    const buttons = Array.from(tabs.querySelectorAll('[data-tab-target]'));
    const container = tabs.parentElement;
    buttons.forEach((button) => {
      button.addEventListener('click', () => {
        const target = button.dataset.tabTarget || '';
        buttons.forEach((item) => item.classList.toggle('is-active', item === button));
        container?.querySelectorAll('[data-tab-panel]').forEach((panel) => {
          panel.hidden = panel.dataset.tabPanel !== target;
        });
        const url = new URL(window.location.href);
        url.searchParams.set('section', target);
        window.history.replaceState({}, '', url);
      });
    });
  });
});
