const storagePrefix = 'tx-analytics-traffic-graph-site';

function resolveWidgetIdentifier(widget) {
  return widget.closest('.dashboard-item')?.dataset.widgetIdentifier
    || widget.closest('.dashboard-item')?.dataset.widgetHash
    || 'default';
}

function storageKey(widget) {
  return `${storagePrefix}:${resolveWidgetIdentifier(widget)}`;
}

function applySite(widget, siteIdentifier) {
  widget.dataset.site = siteIdentifier;
  const select = widget.querySelector('.tx-analytics-traffic-graph-site-select');
  if (select instanceof HTMLSelectElement && select.value !== siteIdentifier) {
    select.value = siteIdentifier;
  }
}

function initializeWidget(widget) {
  const select = widget.querySelector('.tx-analytics-traffic-graph-site-select');
  if (!(select instanceof HTMLSelectElement)) {
    return;
  }

  const key = storageKey(widget);
  const storedValue = localStorage.getItem(key);
  if (storedValue !== null && Array.from(select.options).some((option) => option.value === storedValue)) {
    applySite(widget, storedValue);
  }

  select.addEventListener('change', () => {
    localStorage.setItem(key, select.value);
    applySite(widget, select.value);
  });
}

function initializeWidgets(root = document) {
  root.querySelectorAll('.tx-analytics-traffic-graph').forEach((widget) => {
    if (widget instanceof HTMLElement && widget.dataset.initialized !== 'true') {
      widget.dataset.initialized = 'true';
      initializeWidget(widget);
    }
  });
}

initializeWidgets();

document.addEventListener('widgetContentRendered', (event) => {
  const target = event.target instanceof Element ? event.target : document;
  initializeWidgets(target);
});
