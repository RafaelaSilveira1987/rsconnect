/* RS Connect v36.4.1 — gráficos executivos sem dependências externas. */
(() => {
  'use strict';

  const NS = 'http://www.w3.org/2000/svg';

  const parseSeries = (element) => {
    try {
      const raw = element.dataset.series || '[]';
      const value = JSON.parse(raw);
      return Array.isArray(value) ? value : [];
    } catch (_) {
      return [];
    }
  };

  const svgNode = (name, attributes = {}) => {
    const node = document.createElementNS(NS, name);
    Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, String(value)));
    return node;
  };

  const emptyChart = (element, message = 'Sem dados para o período selecionado.') => {
    element.innerHTML = '';
    const empty = document.createElement('div');
    empty.className = 'report-chart-empty';
    empty.textContent = message;
    element.appendChild(empty);
  };

  const renderLineChart = (element) => {
    const data = parseSeries(element);
    if (!data.length) {
      emptyChart(element);
      return;
    }

    const singleSeries = element.dataset.singleSeries || '';
    const series = singleSeries
      ? [{ key: singleSeries, className: 'series-total' }]
      : [
          { key: 'total', className: 'series-total' },
          { key: 'incoming', className: 'series-incoming' },
          { key: 'ai', className: 'series-ai' },
        ];

    const width = 900;
    const height = element.classList.contains('is-compact') ? 260 : 320;
    const pad = { top: 22, right: 26, bottom: 44, left: 48 };
    const plotWidth = width - pad.left - pad.right;
    const plotHeight = height - pad.top - pad.bottom;
    const values = data.flatMap((row) => series.map((item) => Number(row[item.key] || 0)));
    const maxValue = Math.max(1, ...values);
    const niceMax = Math.max(1, Math.ceil(maxValue / 5) * 5);

    const x = (index) => pad.left + (data.length <= 1 ? plotWidth / 2 : (index / (data.length - 1)) * plotWidth);
    const y = (value) => pad.top + plotHeight - (Number(value || 0) / niceMax) * plotHeight;

    const svg = svgNode('svg', {
      viewBox: `0 0 ${width} ${height}`,
      role: 'img',
      'aria-label': element.getAttribute('aria-label') || 'Gráfico de linhas',
      preserveAspectRatio: 'none',
    });
    svg.classList.add('report-line-svg');

    for (let step = 0; step <= 4; step += 1) {
      const value = (niceMax / 4) * step;
      const yy = y(value);
      svg.appendChild(svgNode('line', { x1: pad.left, y1: yy, x2: width - pad.right, y2: yy, class: 'chart-grid-line' }));
      const label = svgNode('text', { x: pad.left - 10, y: yy + 4, class: 'chart-axis-label', 'text-anchor': 'end' });
      label.textContent = Math.round(value).toLocaleString('pt-BR');
      svg.appendChild(label);
    }

    const labelStep = Math.max(1, Math.ceil(data.length / 7));
    data.forEach((row, index) => {
      if (index % labelStep !== 0 && index !== data.length - 1) return;
      const label = svgNode('text', { x: x(index), y: height - 13, class: 'chart-axis-label', 'text-anchor': 'middle' });
      label.textContent = String(row.label || '');
      svg.appendChild(label);
    });

    series.forEach((item) => {
      const points = data.map((row, index) => `${x(index)},${y(row[item.key])}`).join(' ');
      const line = svgNode('polyline', { points, class: `chart-line ${item.className}`, fill: 'none' });
      svg.appendChild(line);

      data.forEach((row, index) => {
        const value = Number(row[item.key] || 0);
        const dot = svgNode('circle', { cx: x(index), cy: y(value), r: 3.6, class: `chart-dot ${item.className}` });
        const title = svgNode('title');
        title.textContent = `${row.label}: ${value.toLocaleString('pt-BR')}`;
        dot.appendChild(title);
        svg.appendChild(dot);
      });
    });

    element.innerHTML = '';
    element.appendChild(svg);
  };

  const renderDonut = (element) => {
    const data = parseSeries(element).filter((item) => Number(item.value || 0) > 0);
    const total = data.reduce((sum, item) => sum + Number(item.value || 0), 0);
    if (!total) {
      emptyChart(element, 'Sem volume suficiente para comparar.');
      return;
    }

    const size = 220;
    const center = size / 2;
    const radius = 78;
    const circumference = 2 * Math.PI * radius;
    const svg = svgNode('svg', { viewBox: `0 0 ${size} ${size}`, role: 'img', 'aria-label': 'Gráfico de distribuição' });
    svg.classList.add('report-donut-svg');
    svg.appendChild(svgNode('circle', { cx: center, cy: center, r: radius, class: 'donut-track' }));

    let offset = 0;
    data.forEach((item, index) => {
      const ratio = Number(item.value || 0) / total;
      const dash = ratio * circumference;
      const circle = svgNode('circle', {
        cx: center,
        cy: center,
        r: radius,
        class: `donut-segment donut-segment-${(index % 5) + 1}`,
        'stroke-dasharray': `${dash} ${circumference - dash}`,
        'stroke-dashoffset': -offset,
      });
      const title = svgNode('title');
      title.textContent = `${item.label}: ${Number(item.value || 0).toLocaleString('pt-BR')}`;
      circle.appendChild(title);
      svg.appendChild(circle);
      offset += dash;
    });

    const value = svgNode('text', { x: center, y: center - 2, class: 'donut-center-value', 'text-anchor': 'middle' });
    value.textContent = element.dataset.center || total.toLocaleString('pt-BR');
    svg.appendChild(value);
    const label = svgNode('text', { x: center, y: center + 22, class: 'donut-center-label', 'text-anchor': 'middle' });
    label.textContent = element.dataset.center ? 'principal' : 'total';
    svg.appendChild(label);

    element.innerHTML = '';
    element.appendChild(svg);
  };

  const initNavigation = () => {
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
  };

  document.addEventListener('DOMContentLoaded', () => {
    initNavigation();
    document.querySelectorAll('[data-report-line-chart]').forEach(renderLineChart);
    document.querySelectorAll('[data-report-donut]').forEach(renderDonut);
  });
})();
