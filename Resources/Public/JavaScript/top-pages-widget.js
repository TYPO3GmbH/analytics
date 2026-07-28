import { escHtml, positionTooltip, readStorage, writeStorage } from '@t3g/analytics/widget-utils.js';

const storagePrefix = 'tx-analytics-top-pages';

// ─── Tooltip ──────────────────────────────────────────────────────────────────

function getOrCreateTooltip() {
  const id = 'tx-analytics-top-pages-tooltip-global';
  let tooltip = document.getElementById(id);
  if (!tooltip) {
    tooltip = document.createElement('div');
    tooltip.id = id;
    tooltip.className = 'tx-analytics-top-pages-tooltip';
    tooltip.style.display = 'none';
    document.body.appendChild(tooltip);
  }
  return tooltip;
}

function getLabels(element) {
  const widget = element.closest('.tx-analytics-top-pages');
  return {
    views: widget?.dataset.tpLabelViews || 'Views',
    share: widget?.dataset.tpLabelShare || '% from all',
    change: widget?.dataset.tpLabelChange || 'Change',
  };
}

function renderTooltip(element, title, count, share, trend, trendDirection) {
  const tooltip = getOrCreateTooltip();
  const labels = getLabels(element);
  let html = `<div class="tx-analytics-top-pages-tooltip-header">${escHtml(title)}</div>`;
  if (count) {
    html += `<div class="tx-analytics-top-pages-tooltip-row">`
      + `<span class="tx-analytics-top-pages-tooltip-row-label">${escHtml(labels.views)}</span>`
      + `<span class="tx-analytics-top-pages-tooltip-row-value">${escHtml(count)}</span>`
      + `</div>`;
  }
  html += `<div class="tx-analytics-top-pages-tooltip-row">`
    + `<span class="tx-analytics-top-pages-tooltip-row-label">${escHtml(labels.share)}</span>`
    + `<span class="tx-analytics-top-pages-tooltip-row-value">${escHtml(share)}%</span>`
    + `</div>`;
  if (trend) {
    html += `<div class="tx-analytics-top-pages-tooltip-row tx-analytics-top-pages-trend-${escHtml(trendDirection)}">`
      + `<span class="tx-analytics-top-pages-tooltip-row-label">${escHtml(labels.change)}</span>`
      + `<span class="tx-analytics-top-pages-tooltip-row-value">${escHtml(trend)}</span>`
      + `</div>`;
  }
  tooltip.innerHTML = html;
  return tooltip;
}

function hideTooltip() {
  const tooltip = document.getElementById('tx-analytics-top-pages-tooltip-global');
  if (tooltip) tooltip.style.display = 'none';
}

function initTooltips(container) {
  container.querySelectorAll('.tx-analytics-top-pages-row[data-tp-title]').forEach((target) => {
    if (target.dataset.tpTooltipInit) return;
    target.dataset.tpTooltipInit = '1';
    target.addEventListener('mouseenter', (e) => {
      const tooltip = renderTooltip(
        target,
        target.dataset.tpTitle ?? '',
        target.dataset.tpCount ?? '',
        target.dataset.tpShare ?? '',
        target.dataset.tpTrend ?? '',
        target.dataset.tpTrendDirection ?? 'neutral'
      );
      positionTooltip(tooltip, e.clientX, e.clientY);
    });
    target.addEventListener('mousemove', (e) => positionTooltip(getOrCreateTooltip(), e.clientX, e.clientY));
    target.addEventListener('mouseleave', hideTooltip);
  });
}

function resolveWidgetIdentifier(widget) {
  return widget.closest('.dashboard-item')?.dataset.widgetIdentifier
    || widget.closest('.dashboard-item')?.dataset.widgetHash
    || widget.querySelector('.tx-analytics-top-pages-site-select')?.id
    || 'default';
}

function siteKey(widget) {
  return `${storagePrefix}:site:${resolveWidgetIdentifier(widget)}`;
}

function daysKey(widget) {
  return `${storagePrefix}:days:${resolveWidgetIdentifier(widget)}`;
}

async function loadPageList(widget) {
  const ajaxUrl = TYPO3?.settings?.ajaxUrls?.analytics_top_pages_content;
  if (!ajaxUrl) {
    return;
  }

  const listContainer = widget.querySelector('.tx-analytics-top-pages-list-container');
  if (!listContainer) {
    return;
  }

  const site = widget.dataset.site ?? '';
  const days = widget.dataset.days ?? '7';

  listContainer.classList.add('tx-analytics-top-pages-loading');

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
      listContainer.innerHTML = data.html;
      initTooltips(listContainer);
      const link = widget.closest('.widget-content')?.querySelector('.widget-content-footer a');
      if (link instanceof HTMLAnchorElement && typeof data.showAllUrl === 'string') {
        link.href = data.showAllUrl || '#';
      }
    }
  } catch {
    // fail silently
  } finally {
    listContainer.classList.remove('tx-analytics-top-pages-loading');
  }
}

function initializeWidget(widget) {
  const siteSelect = widget.querySelector('.tx-analytics-top-pages-site-select');
  const periodSelect = widget.querySelector('.tx-analytics-top-pages-period-select');

  // Restore stored values
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
    loadPageList(widget);
  } else {
    initTooltips(widget);
  }

  siteSelect?.addEventListener('change', () => {
    writeStorage(siteKey(widget), siteSelect.value);
    widget.dataset.site = siteSelect.value;
    loadPageList(widget);
  });

  periodSelect?.addEventListener('change', () => {
    writeStorage(daysKey(widget), periodSelect.value);
    widget.dataset.days = periodSelect.value;
    loadPageList(widget);
  });

  return true;
}

function initializeWidgets(root = document) {
  root.querySelectorAll('.tx-analytics-top-pages').forEach((widget) => {
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
