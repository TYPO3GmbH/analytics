const storagePrefix = 'tx-analytics-traffic-graph';

function resolveWidgetIdentifier(widget) {
  return widget.closest('.dashboard-item')?.dataset.widgetIdentifier
    || widget.closest('.dashboard-item')?.dataset.widgetHash
    || widget.querySelector('.tx-analytics-traffic-graph-site-select')?.id
    || 'default';
}

function siteKey(widget) {
  return `${storagePrefix}:site:${resolveWidgetIdentifier(widget)}`;
}

function daysKey(widget) {
  return `${storagePrefix}:days:${resolveWidgetIdentifier(widget)}`;
}

function readStorage(key) {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function writeStorage(key, value) {
  try {
    localStorage.setItem(key, value);
  } catch {
    // ignore (e.g. Safari private mode)
  }
}

// ─── Tooltip ──────────────────────────────────────────────────────────────────

function getOrCreateGlobalTooltip() {
  const id = 'tx-analytics-traffic-graph-tooltip-global';
  let el = document.getElementById(id);
  if (!el) {
    el = document.createElement('div');
    el.id = id;
    el.className = 'tx-analytics-traffic-graph-tooltip';
    el.style.display = 'none';
    document.body.appendChild(el);
  }
  return el;
}

function renderTooltipContent(tooltip, data) {
  const { date, metrics } = data;
  let html = `<div class="tx-analytics-traffic-graph-tooltip-date">${escapeHtml(date)}</div>`;
  for (const m of (metrics || [])) {
    html += `<div class="tx-analytics-traffic-graph-tooltip-row">`
          + `<span class="tx-analytics-traffic-graph-tooltip-dot" data-tone="${escapeHtml(m.tone || '')}"></span>`
          + `<span class="tx-analytics-traffic-graph-tooltip-label">${escapeHtml(m.label || '')}</span>`
          + `<span class="tx-analytics-traffic-graph-tooltip-value">${escapeHtml(m.value || '')}</span>`
          + `</div>`;
  }
  tooltip.innerHTML = html;
}

function positionTooltip(tooltip, clientX, clientY) {
  // Render off-screen first so offsetWidth/Height are computed.
  tooltip.style.left = '-9999px';
  tooltip.style.top = '-9999px';
  tooltip.style.display = 'block';

  const tw = tooltip.offsetWidth;
  const th = tooltip.offsetHeight;
  const vw = window.innerWidth;
  const vh = window.innerHeight;

  let x = clientX + 14;
  let y = clientY - Math.round(th / 2);

  if (x + tw > vw - 8) x = clientX - tw - 14;
  x = Math.max(8, x);
  y = Math.max(8, Math.min(y, vh - th - 8));

  tooltip.style.left = x + 'px';
  tooltip.style.top = y + 'px';
}

function showHoverIndicator(tooltipRect) {
  const svg = tooltipRect.closest('svg');
  if (!svg) return;

  const rx = parseFloat(tooltipRect.getAttribute('x') ?? '0');
  const rw = parseFloat(tooltipRect.getAttribute('width') ?? '0');
  const cx = rx + rw / 2; // SVG user-unit x of the data point

  // Update hover line in SVG coordinates.
  const line = svg.querySelector('.tx-analytics-sparkline-hover-line');
  if (line) {
    line.setAttribute('x1', cx);
    line.setAttribute('x2', cx);
    line.setAttribute('visibility', 'visible');
  }

  // HTML dots — rendered outside the SVG so they stay circular (SVG circles distort
  // to ellipses under preserveAspectRatio="none").
  const tones = (svg.dataset.tones ?? '').split(',').filter(Boolean);
  const svgBB = svg.getBoundingClientRect();
  const scaleX = svgBB.width / 100;   // viewBox width = 100
  const scaleY = svgBB.height / 32;   // viewBox height = 32

  // Container for the HTML dots — positioned over the SVG.
  const chartArea = svg.closest('.tx-analytics-traffic-graph-chart-area');
  if (!chartArea) return;

  let dotContainer = chartArea.querySelector('.tx-analytics-hover-dot-layer');
  if (!dotContainer) {
    dotContainer = document.createElement('div');
    dotContainer.className = 'tx-analytics-hover-dot-layer';
    chartArea.appendChild(dotContainer);
  }
  dotContainer.style.display = 'block';

  // Sync dot count to dataset count.
  while (dotContainer.children.length < tones.length) {
    dotContainer.appendChild(document.createElement('span'));
  }
  while (dotContainer.children.length > tones.length) {
    dotContainer.removeChild(dotContainer.lastChild);
  }

  tones.forEach((tone, i) => {
    const yAttr = tooltipRect.getAttribute(`data-y-${i}`);
    const dot = dotContainer.children[i];
    if (!dot || yAttr === null) return;
    dot.className = 'tx-analytics-sparkline-hover-dot';
    dot.setAttribute('data-tone', tone);
    // Convert SVG user units to px offsets within chartArea.
    const dotX = cx * scaleX;
    const dotY = parseFloat(yAttr) * scaleY;
    dot.style.left = dotX + 'px';
    dot.style.top = dotY + 'px';
  });
}

function hideHoverIndicator(svg) {
  if (!svg) return;
  svg.querySelector('.tx-analytics-sparkline-hover-line')?.setAttribute('visibility', 'hidden');
  // Hide the HTML dot layer.
  const chartArea = svg.closest('.tx-analytics-traffic-graph-chart-area');
  const layer = chartArea?.querySelector('.tx-analytics-hover-dot-layer');
  if (layer) layer.style.display = 'none';
}

function hideGlobalTooltip() {
  const el = document.getElementById('tx-analytics-traffic-graph-tooltip-global');
  if (el) el.style.display = 'none';
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function initChartTooltips(widget) {
  if (widget._tooltipsInitialized) return;
  widget._tooltipsInitialized = true;

  // Attach to the stable widget root so the listener survives AJAX chart reloads
  // (chartContainer.innerHTML replacement would detach a chartArea-level listener).
  widget.addEventListener('mousemove', (e) => {
    const rect = e.target.closest('[data-tooltip]');
    if (!rect || !rect.dataset.tooltip) {
      hideGlobalTooltip();
      hideHoverIndicator(e.target.closest('svg'));
      return;
    }
    try {
      const data = JSON.parse(rect.dataset.tooltip);
      const tooltip = getOrCreateGlobalTooltip();
      renderTooltipContent(tooltip, data);
      positionTooltip(tooltip, e.clientX, e.clientY);
    } catch {
      hideGlobalTooltip();
    }
    showHoverIndicator(rect);
  });

  widget.addEventListener('mouseleave', () => {
    hideGlobalTooltip();
    widget.querySelectorAll('svg').forEach(hideHoverIndicator);
  });
}

// ─── Legend toggle ─────────────────────────────────────────────────────────────

function setDatasetVisible(chartArea, key, visible) {
  if (!chartArea) return;
  chartArea.querySelectorAll(`[data-dataset-key="${CSS.escape(key)}"]`).forEach((el) => {
    el.style.display = visible ? '' : 'none';
  });
}

function initLegendToggles(widget) {
  const legend = widget.querySelector('.tx-analytics-traffic-graph-legend');
  if (!legend) return;

  const chartArea = widget.querySelector('.tx-analytics-traffic-graph-chart-area');

  if (!widget._hiddenDatasets) {
    widget._hiddenDatasets = new Set();
  }

  legend.querySelectorAll('.tx-analytics-traffic-graph-legend-item[data-dataset-key]').forEach((item) => {
    const key = item.dataset.datasetKey;
    if (!key) return;

    // Re-apply hidden state after chart reload.
    if (widget._hiddenDatasets.has(key)) {
      item.classList.add('tx-analytics-traffic-graph-legend-item--disabled');
      item.setAttribute('aria-pressed', 'false');
      setDatasetVisible(chartArea, key, false);
    }

    item.addEventListener('click', () => {
      const isHidden = widget._hiddenDatasets.has(key);
      if (isHidden) {
        widget._hiddenDatasets.delete(key);
        item.classList.remove('tx-analytics-traffic-graph-legend-item--disabled');
        item.setAttribute('aria-pressed', 'true');
        setDatasetVisible(chartArea, key, true);
      } else {
        widget._hiddenDatasets.add(key);
        item.classList.add('tx-analytics-traffic-graph-legend-item--disabled');
        item.setAttribute('aria-pressed', 'false');
        setDatasetVisible(chartArea, key, false);
      }
    });
  });
}

// ─── Chart loading ─────────────────────────────────────────────────────────────

async function loadChart(widget) {
  const ajaxUrl = TYPO3?.settings?.ajaxUrls?.analytics_traffic_graph_content;
  if (!ajaxUrl) {
    return;
  }

  const chartContainer = widget.querySelector('.tx-analytics-traffic-graph-chart-container');
  if (!chartContainer) {
    return;
  }

  const site = widget.dataset.site ?? '';
  const days = widget.dataset.days ?? '30';

  chartContainer.classList.add('tx-analytics-traffic-graph-loading');

  try {
    const url = new URL(ajaxUrl, window.location.origin);
    url.searchParams.set('site', site);
    url.searchParams.set('days', days);

    const response = await fetch(url.toString(), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!response.ok) {
      return;
    }

    const data = await response.json();
    if (data.status === 'ok') {
      chartContainer.innerHTML = data.html;
      // Re-attach legend toggles and re-apply hidden state after chart HTML replacement.
      initLegendToggles(widget);
      const link = widget.closest('.widget-content')?.querySelector('.widget-content-footer a');
      if (link instanceof HTMLAnchorElement && typeof data.showAllUrl === 'string') {
        link.href = data.showAllUrl || '#';
      }
    }
  } catch {
    // fail silently
  } finally {
    chartContainer.classList.remove('tx-analytics-traffic-graph-loading');
  }
}

// ─── Widget init ───────────────────────────────────────────────────────────────

function initializeWidget(widget) {
  const siteSelect = widget.querySelector('.tx-analytics-traffic-graph-site-select');
  const periodSelect = widget.querySelector('.tx-analytics-traffic-graph-period-select');

  const storedSite = readStorage(siteKey(widget));
  const storedDays = readStorage(daysKey(widget));

  let needsReload = false;

  if (storedSite !== null && siteSelect instanceof HTMLSelectElement) {
    if (Array.from(siteSelect.options).some((o) => o.value === storedSite)) {
      siteSelect.value = storedSite;
      if (storedSite !== widget.dataset.site) {
        widget.dataset.site = storedSite;
        needsReload = true;
      }
    }
  }

  if (storedDays !== null && periodSelect instanceof HTMLSelectElement) {
    if (Array.from(periodSelect.options).some((o) => o.value === storedDays)) {
      periodSelect.value = storedDays;
      if (storedDays !== widget.dataset.days) {
        widget.dataset.days = storedDays;
        needsReload = true;
      }
    }
  }

  if (needsReload) {
    loadChart(widget);
  }

  siteSelect?.addEventListener('change', () => {
    writeStorage(siteKey(widget), siteSelect.value);
    widget.dataset.site = siteSelect.value;
    loadChart(widget);
  });

  periodSelect?.addEventListener('change', () => {
    writeStorage(daysKey(widget), periodSelect.value);
    widget.dataset.days = periodSelect.value;
    loadChart(widget);
  });

  initLegendToggles(widget);
  initChartTooltips(widget);

  return true;
}

function initializeWidgets(root = document) {
  // querySelectorAll does not match the root element itself, so include it explicitly
  // when the event fires directly on a widget element (e.g. widgetContentRendered).
  const isSelfWidget = root instanceof HTMLElement && root.matches('.tx-analytics-traffic-graph');
  const candidates = [
    ...(isSelfWidget ? [root] : []),
    ...root.querySelectorAll('.tx-analytics-traffic-graph'),
  ];
  candidates.forEach((widget) => {
    if (widget instanceof HTMLElement && widget.dataset.initialized !== 'true') {
      if (initializeWidget(widget)) {
        widget.dataset.initialized = 'true';
      }
    }
  });
}

initializeWidgets();

document.addEventListener('widgetContentRendered', (event) => {
  const target = event.target instanceof Element ? event.target : document;
  initializeWidgets(target);
});
