/* RS Connect v36.5.3 — preenchimento de endereço por CEP. */
(() => {
  'use strict';

  const root = document.querySelector('[data-company-address]');
  if (!root) return;

  const cepInput = root.querySelector('[data-cep-input]');
  const status = root.querySelector('[data-cep-status]');
  const indicator = root.querySelector('.cep-lookup-indicator');
  if (!cepInput) return;

  const fields = {
    street: root.querySelector('[name="address_line"]'),
    number: root.querySelector('[name="address_number"]'),
    district: root.querySelector('[name="district"]'),
    city: root.querySelector('[name="city"]'),
    state: root.querySelector('[name="state"]'),
  };

  let debounceTimer = null;
  let activeController = null;
  let lastLookup = '';

  const digitsOnly = (value) => String(value || '').replace(/\D/g, '').slice(0, 8);
  const formatCep = (value) => {
    const digits = digitsOnly(value);
    return digits.length > 5 ? `${digits.slice(0, 5)}-${digits.slice(5)}` : digits;
  };

  const setStatus = (message, state = '') => {
    if (status) {
      status.textContent = message;
      status.classList.remove('is-loading', 'is-success', 'is-error');
      if (state) status.classList.add(`is-${state}`);
    }
    if (indicator) {
      indicator.classList.toggle('is-loading', state === 'loading');
      indicator.classList.toggle('is-success', state === 'success');
      indicator.classList.toggle('is-error', state === 'error');
    }
  };

  const fill = (field, value) => {
    if (field && typeof value === 'string' && value.trim() !== '') {
      field.value = value.trim();
      field.dispatchEvent(new Event('input', { bubbles: true }));
      field.dispatchEvent(new Event('change', { bubbles: true }));
    }
  };

  const lookupCep = async (force = false) => {
    const cep = digitsOnly(cepInput.value);
    cepInput.value = formatCep(cep);

    if (cep.length === 0) {
      setStatus('Digite o CEP para preencher o endereço automaticamente.');
      return;
    }
    if (cep.length !== 8) {
      if (force) setStatus('Informe um CEP com 8 números.', 'error');
      return;
    }
    if (!force && cep === lastLookup) return;

    if (activeController) activeController.abort();
    activeController = new AbortController();
    setStatus('Buscando endereço…', 'loading');

    try {
      const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`, {
        signal: activeController.signal,
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);

      const data = await response.json();
      if (data.erro === true) {
        lastLookup = '';
        setStatus('CEP não encontrado. Confira os números ou preencha o endereço manualmente.', 'error');
        return;
      }

      lastLookup = cep;
      cepInput.value = formatCep(data.cep || cep);
      fill(fields.street, data.logradouro || '');
      fill(fields.district, data.bairro || '');
      fill(fields.city, data.localidade || '');
      fill(fields.state, data.uf || '');
      setStatus('Endereço encontrado. Complete o número e, se necessário, o complemento.', 'success');

      if (fields.number && !fields.number.value.trim()) fields.number.focus();
    } catch (error) {
      if (error && error.name === 'AbortError') return;
      lastLookup = '';
      setStatus('Não foi possível consultar o CEP agora. Você pode preencher o endereço manualmente.', 'error');
    } finally {
      activeController = null;
    }
  };

  cepInput.value = formatCep(cepInput.value);
  cepInput.addEventListener('input', () => {
    cepInput.value = formatCep(cepInput.value);
    clearTimeout(debounceTimer);
    const cep = digitsOnly(cepInput.value);
    if (cep.length === 8) {
      debounceTimer = window.setTimeout(() => lookupCep(false), 350);
    } else {
      lastLookup = '';
      if (cep.length === 0) setStatus('Digite o CEP para preencher o endereço automaticamente.');
    }
  });
  cepInput.addEventListener('blur', () => lookupCep(true));
})();
