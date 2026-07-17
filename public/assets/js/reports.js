/* ZIP 34.2 — navegação dos relatórios em cards */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-report-section-link]').forEach((link) => {
    link.addEventListener('click', (event) => {
      const selector = link.getAttribute('href') || '';
      if (!selector.startsWith('#')) return;
      const target = document.querySelector(selector);
      if (!target) return;
      event.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      window.history.replaceState({}, '', selector);
      target.classList.add('is-highlighted');
      window.setTimeout(() => target.classList.remove('is-highlighted'), 900);
    });
  });
});
