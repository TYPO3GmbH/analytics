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
