/* RS Connect v36.4.7 — gráficos executivos coloridos com preenchimento seguro, sem dependências externas. */
(() => {
  'use strict';

  const NS = 'http://www.w3.org/2000/svg';

  const parseSeries = (element) => {
    try {
      let raw = element.dataset.series || '[]';
      if (element.dataset.seriesB64) {
        const binary = atob(element.dataset.seriesB64);
        const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0));
        raw = new TextDecoder('utf-8').decode(bytes);
      }
      const value = JSON.parse(raw);
      return Array.isArray(value) ? value : [];
    } catch (error) {
      console.error('[RS Connect] Não foi possível interpretar os dados do gráfico.', error);
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

  const niceCeil = (value) => {
    if (value <= 5) return 5;
    if (value <= 10) return 10;
    if (value <= 20) return 20;
    if (value <= 50) return Math.ceil(value / 10) * 10;
    if (value <= 100) return Math.ceil(value / 20) * 20;
    const magnitude = 10 ** Math.floor(Math.log10(value));
    return Math.ceil(value / magnitude) * magnitude;
  };

  const createTooltip = (element) => {
    const tooltip = document.createElement('div');
    tooltip.className = 'report-chart-tooltip';
    element.appendChild(tooltip);
    return tooltip;
  };

  const tooltipHtml = (row, series) => {
    const labels = { total: 'Total', incoming: 'Recebidas', ai: 'IA' };
    return [
      `<strong>${String(row.label || '')}</strong>`,
      ...series.map((item) => `<span>${labels[item.key] || item.key}<b>${Number(row[item.key] || 0).toLocaleString('pt-BR')}</b></span>`),
    ].join('');
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

    const width = 960;
    const height = element.classList.contains('is-compact') ? 250 : 300;
    const pad = { top: 18, right: 20, bottom: 38, left: 44 };
    const plotWidth = width - pad.left - pad.right;
    const plotHeight = height - pad.top - pad.bottom;
    const values = data.flatMap((row) => series.map((item) => Number(row[item.key] || 0)));
    const maxValue = Math.max(1, ...values);
    const niceMax = niceCeil(maxValue);

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
      svg.appendChild(svgNode('line', {
        x1: pad.left,
        y1: yy,
        x2: width - pad.right,
        y2: yy,
        class: 'chart-grid-line',
      }));
      const label = svgNode('text', {
        x: pad.left - 9,
        y: yy + 4,
        class: 'chart-axis-label',
        'text-anchor': 'end',
      });
      label.textContent = Math.round(value).toLocaleString('pt-BR');
      svg.appendChild(label);
    }

    const labelStep = Math.max(1, Math.ceil(data.length / 6));
    data.forEach((row, index) => {
      if (index % labelStep !== 0 && index !== data.length - 1) return;
      const label = svgNode('text', {
        x: x(index),
        y: height - 10,
        class: 'chart-axis-label',
        'text-anchor': 'middle',
      });
      label.textContent = String(row.label || '');
      svg.appendChild(label);
    });

    // v36.4.7: o preenchimento é um path separado da linha. Isso evita o bug
    // anterior em que a própria linha recebia fill e formava uma mancha sólida.
    const gradientColors = {
      'series-total': '#146498',
      'series-incoming': '#02948e',
      'series-ai': '#7658b3',
    };
    const chartId = `report-chart-${Math.random().toString(36).slice(2, 9)}`;
    const defs = svgNode('defs');

    series.forEach((item, seriesIndex) => {
      const gradientId = `${chartId}-area-${seriesIndex}`;
      const gradient = svgNode('linearGradient', {
        id: gradientId,
        x1: '0',
        y1: '0',
        x2: '0',
        y2: '1',
      });
      const color = gradientColors[item.className] || '#146498';
      gradient.appendChild(svgNode('stop', { offset: '0%', 'stop-color': color, 'stop-opacity': item.className === 'series-total' ? '.22' : '.14' }));
      gradient.appendChild(svgNode('stop', { offset: '100%', 'stop-color': color, 'stop-opacity': '.015' }));
      defs.appendChild(gradient);
      item.gradientId = gradientId;
    });
    svg.appendChild(defs);

    series.forEach((item) => {
      const commands = data.map((row, index) => `${index === 0 ? 'M' : 'L'} ${x(index)} ${y(row[item.key])}`).join(' ');
      const baseline = y(0);
      const areaCommands = `${commands} L ${x(data.length - 1)} ${baseline} L ${x(0)} ${baseline} Z`;
      const area = svgNode('path', {
        d: areaCommands,
        class: `chart-area ${item.className}`,
        fill: `url(#${item.gradientId})`,
      });
      svg.appendChild(area);

      const line = svgNode('path', {
        d: commands,
        class: `chart-line ${item.className}`,
        fill: 'none',
      });
      svg.appendChild(line);

      data.forEach((row, index) => {
        const value = Number(row[item.key] || 0);
        if (value <= 0) return;
        const dot = svgNode('circle', {
          cx: x(index),
          cy: y(value),
          r: 3.5,
          class: `chart-dot ${item.className}`,
        });
        svg.appendChild(dot);
      });
    });

    const hoverLine = svgNode('line', {
      x1: pad.left,
      y1: pad.top,
      x2: pad.left,
      y2: pad.top + plotHeight,
      class: 'chart-hover-line',
      opacity: 0,
    });
    svg.appendChild(hoverLine);

    const overlay = svgNode('rect', {
      x: pad.left,
      y: pad.top,
      width: plotWidth,
      height: plotHeight,
      fill: 'transparent',
      style: 'cursor:crosshair',
    });
    svg.appendChild(overlay);

    element.innerHTML = '';
    element.appendChild(svg);
    const tooltip = createTooltip(element);

    const showAt = (event) => {
      const rect = svg.getBoundingClientRect();
      const relativeX = Math.max(0, Math.min(rect.width, event.clientX - rect.left));
      const svgX = (relativeX / rect.width) * width;
      const ratio = Math.max(0, Math.min(1, (svgX - pad.left) / plotWidth));
      const index = Math.round(ratio * Math.max(0, data.length - 1));
      const row = data[index];
      if (!row) return;

      const xx = x(index);
      hoverLine.setAttribute('x1', String(xx));
      hoverLine.setAttribute('x2', String(xx));
      hoverLine.setAttribute('opacity', '1');

      tooltip.innerHTML = tooltipHtml(row, series);
      tooltip.classList.add('is-visible');

      const elementRect = element.getBoundingClientRect();
      const tooltipWidth = 170;
      const left = Math.min(elementRect.width - tooltipWidth - 10, Math.max(10, event.clientX - elementRect.left + 12));
      const top = Math.max(10, event.clientY - elementRect.top - 82);
      tooltip.style.left = `${left}px`;
      tooltip.style.top = `${top}px`;
    };

    overlay.addEventListener('mousemove', showAt);
    overlay.addEventListener('mouseleave', () => {
      hoverLine.setAttribute('opacity', '0');
      tooltip.classList.remove('is-visible');
    });
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
    label.textContent = element.dataset.center ? 'participação' : 'total';
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
