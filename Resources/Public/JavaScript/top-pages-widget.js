const storagePrefix = 'tx-analytics-top-pages-site';

function resolveWidgetIdentifier(widget) {
  return widget.closest('.dashboard-item')?.dataset.widgetIdentifier
    || widget.closest('.dashboard-item')?.dataset.widgetHash
    || widget.querySelector('.tx-analytics-top-pages-site-select')?.id
    || 'default';
}

function storageKey(widget) {
  return `${storagePrefix}:${resolveWidgetIdentifier(widget)}`;
}

function applySite(widget, siteIdentifier) {
  widget.dataset.site = siteIdentifier;
  const select = widget.querySelector('.tx-analytics-top-pages-site-select');
  if (select instanceof HTMLSelectElement && select.value !== siteIdentifier) {
    select.value = siteIdentifier;
  }
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

function initializeWidget(widget) {
  const select = widget.querySelector('.tx-analytics-top-pages-site-select');
  if (!(select instanceof HTMLSelectElement)) {
    return false;
  }

  const key = storageKey(widget);
  const storedValue = readStorage(key);
  if (storedValue !== null && Array.from(select.options).some((option) => option.value === storedValue)) {
    applySite(widget, storedValue);
  }

  select.addEventListener('change', () => {
    writeStorage(key, select.value);
    applySite(widget, select.value);
  });

  widget.querySelector('.tx-analytics-top-pages-link')?.addEventListener('click', (event) => {
    event.preventDefault();
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
