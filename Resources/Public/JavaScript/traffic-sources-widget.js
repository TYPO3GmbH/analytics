import { escHtml, positionTooltip, readStorage, writeStorage } from '@t3g/analytics/widget-utils.js';

const storagePrefix = 'tx-analytics-traffic-sources';

// ─── Tooltip ──────────────────────────────────────────────────────────────────

function getOrCreateTooltip() {
  const id = 'tx-analytics-traffic-sources-tooltip-global';
  let tooltip = document.getElementById(id);
  if (!tooltip) {
    tooltip = document.createElement('div');
    tooltip.id = id;
    tooltip.className = 'tx-analytics-traffic-sources-tooltip';
    tooltip.style.display = 'none';
    document.body.appendChild(tooltip);
  }
  return tooltip;
}

function getLabels(element) {
  const widget = element.closest('.tx-analytics-traffic-sources');
  return {
    share: widget?.dataset.tsLabelShare || '% from all',
    change: widget?.dataset.tsLabelChange || 'Change',
    sessions: widget?.dataset.tsLabelSessions || 'Sessions',
  };
}

function renderTooltip(element, label, value, tone, count, change, changeTone) {
  const tooltip = getOrCreateTooltip();
  const labels = getLabels(element);
  let html = `<div class="tx-analytics-traffic-sources-tooltip-header">`
    + `<span class="tx-analytics-traffic-sources-tooltip-dot tx-analytics-traffic-sources-tone-${escHtml(tone)}"></span>`
    + `<span class="tx-analytics-traffic-sources-tooltip-name">${escHtml(label)}</span>`
    + `</div>`;
  if (count) {
    html += `<div class="tx-analytics-traffic-sources-tooltip-row">`
      + `<span class="tx-analytics-traffic-sources-tooltip-row-label">${escHtml(labels.sessions)}</span>`
      + `<span class="tx-analytics-traffic-sources-tooltip-row-value">${Number(count).toLocaleString()}</span>`
      + `</div>`;
  }
  html += `<div class="tx-analytics-traffic-sources-tooltip-row">`
    + `<span class="tx-analytics-traffic-sources-tooltip-row-label">${escHtml(labels.share)}</span>`
    + `<span class="tx-analytics-traffic-sources-tooltip-row-value">${escHtml(value)}%</span>`
    + `</div>`;
  if (change) {
    html += `<div class="tx-analytics-traffic-sources-tooltip-row tx-analytics-traffic-sources-change-${escHtml(changeTone)}">`
      + `<span class="tx-analytics-traffic-sources-tooltip-row-label">${escHtml(labels.change)}</span>`
      + `<span class="tx-analytics-traffic-sources-tooltip-row-value">${escHtml(change)} ${escHtml(labels.sessions)}</span>`
      + `</div>`;
  }
  tooltip.innerHTML = html;
  return tooltip;
}

function hideTooltip() {
  const tooltip = document.getElementById('tx-analytics-traffic-sources-tooltip-global');
  if (tooltip) tooltip.style.display = 'none';
}

function initSegmentInteraction(segment) {
  const startAngle = parseFloat(
    (segment.getAttribute('transform') ?? '').match(/rotate\(\s*([\d.eE+\-]+)/)?.[1] ?? '0'
  );
  const radius = parseFloat(segment.getAttribute('r') ?? '20');
  const arcLength = parseFloat(
    (segment.getAttribute('stroke-dasharray') ?? '').match(/[\d.]+/)?.[0] ?? '0'
  );

  // Store geometry for hit-testing after the SVG transform attribute is removed
  segment._tsStartAngle = startAngle;
  segment._tsRadius = radius;
  segment._tsArcLength = arcLength;

  segment.removeAttribute('transform');
  segment.style.transform = `rotate(${startAngle}deg)`;
  segment._tsActive = `rotate(${startAngle}deg) scale(1.075)`;
  segment._tsBase = `rotate(${startAngle}deg)`;
}

// Geometric hit-test: which segment is under (clientX, clientY)?
// Uses original arc geometry so the result is stable even while segments animate.
function getSegmentAtPoint(svg, clientX, clientY) {
  const svgRect = svg.getBoundingClientRect();
  if (!svgRect.width || !svgRect.height) return null;
  const svgX = (clientX - svgRect.left) / svgRect.width * 100;
  const svgY = (clientY - svgRect.top) / svgRect.height * 100;
  const deltaX = svgX - 50;
  const deltaY = svgY - 50;
  const distanceFromCenter = Math.sqrt(deltaX * deltaX + deltaY * deltaY);

  for (const segment of svg.querySelectorAll('.tx-analytics-traffic-sources-donut-segment[data-ts-label]')) {
    const radius = segment._tsRadius ?? parseFloat(segment.getAttribute('r') ?? '20');
    const strokeWidth = parseFloat(segment.getAttribute('stroke-width') ?? '4');
    if (distanceFromCenter < radius - strokeWidth / 2 - 1 || distanceFromCenter > radius + strokeWidth / 2 + 1) continue;

    const startAngle = segment._tsStartAngle ?? 0;
    const arcLength = segment._tsArcLength ?? 0;
    const arcAngleDeg = (arcLength / (2 * Math.PI * radius)) * 360;

    let cursorAngle = Math.atan2(deltaY, deltaX) * 180 / Math.PI;
    if (cursorAngle < 0) cursorAngle += 360;

    const normalizedStartAngle = ((startAngle % 360) + 360) % 360;
    let relativeAngle = cursorAngle - normalizedStartAngle;
    if (relativeAngle < 0) relativeAngle += 360;
    if (relativeAngle <= arcAngleDeg) return segment;
  }
  return null;
}

function initDonutHover(svg) {
  if (svg._donutHoverInit) return;
  svg._donutHoverInit = true;

  svg.querySelectorAll('.tx-analytics-traffic-sources-donut-segment[data-ts-label]').forEach(segment => {
    initSegmentInteraction(segment);
  });

  let activeSegment = null;

  function setActive(segment, clientX, clientY) {
    if (segment === activeSegment) {
      if (activeSegment) positionTooltip(getOrCreateTooltip(), clientX, clientY);
      return;
    }
    if (activeSegment) {
      activeSegment.style.transform = activeSegment._tsBase;
      activeSegment.classList.remove('is-active');
    }
    activeSegment = segment;
    if (segment) {
      segment.style.transform = segment._tsActive;
      segment.classList.add('is-active');
      const tooltip = renderTooltip(
        segment,
        segment.dataset.tsLabel ?? '',
        segment.dataset.tsValue ?? '',
        segment.dataset.tsTone ?? '',
        segment.dataset.tsCount ?? '',
        segment.dataset.tsChange ?? '',
        segment.dataset.tsChangeTone ?? ''
      );
      positionTooltip(tooltip, clientX, clientY);
    } else {
      hideTooltip();
    }
  }

  svg.addEventListener('mousemove', (e) => setActive(getSegmentAtPoint(svg, e.clientX, e.clientY), e.clientX, e.clientY));
  svg.addEventListener('mouseleave', () => setActive(null, 0, 0));
}

function initTooltips(container) {
  // Donut segments: SVG-level delegation with geometric hit-test to prevent
  // flicker caused by the segment animating away from the cursor on pull-out
  const svgs = new Set();
  container.querySelectorAll('.tx-analytics-traffic-sources-donut-segment[data-ts-label]').forEach(segment => {
    const svg = segment.closest('svg');
    if (svg) svgs.add(svg);
  });
  svgs.forEach(svg => initDonutHover(svg));

  // List rows: standard per-element listeners (no movement, no flicker)
  container.querySelectorAll('.tx-analytics-traffic-sources-row[data-ts-label]').forEach((target) => {
    if (target.dataset.tsTooltipInit) return;
    target.dataset.tsTooltipInit = '1';
    target.addEventListener('mouseenter', (e) => {
      const tooltip = renderTooltip(
        target,
        target.dataset.tsLabel ?? '',
        target.dataset.tsValue ?? '',
        target.dataset.tsTone ?? '',
        target.dataset.tsCount ?? '',
        target.dataset.tsChange ?? '',
        target.dataset.tsChangeTone ?? ''
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
    || 'default';
}

function siteKey(widget) {
  return `${storagePrefix}:site:${resolveWidgetIdentifier(widget)}`;
}

function daysKey(widget) {
  return `${storagePrefix}:days:${resolveWidgetIdentifier(widget)}`;
}

async function loadContent(widget) {
  const ajaxUrl = TYPO3?.settings?.ajaxUrls?.analytics_traffic_sources_content;
  if (!ajaxUrl) {
    return;
  }

  const container = widget.querySelector('.tx-analytics-traffic-sources-sections-container');
  if (!container) {
    return;
  }

  const site = widget.dataset.site ?? '';
  const days = widget.dataset.days ?? '30';

  container.classList.add('tx-analytics-traffic-sources-loading');

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
      container.innerHTML = data.html;
      initTooltips(container);
      const link = widget.closest('.widget-content')?.querySelector('.widget-content-footer a');
      if (link instanceof HTMLAnchorElement && typeof data.showAllUrl === 'string') {
        link.href = data.showAllUrl || '#';
      }
    }
  } catch {
    // fail silently
  } finally {
    container.classList.remove('tx-analytics-traffic-sources-loading');
  }
}

function initializeWidget(widget) {
  const siteSelect = widget.querySelector('.tx-analytics-traffic-sources-site-select');
  const periodSelect = widget.querySelector('.tx-analytics-traffic-sources-period-select');

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
    loadContent(widget);
  } else {
    initTooltips(widget);
  }

  siteSelect?.addEventListener('change', () => {
    writeStorage(siteKey(widget), siteSelect.value);
    widget.dataset.site = siteSelect.value;
    loadContent(widget);
  });

  periodSelect?.addEventListener('change', () => {
    writeStorage(daysKey(widget), periodSelect.value);
    widget.dataset.days = periodSelect.value;
    loadContent(widget);
  });

  return true;
}

function initializeWidgets(root = document) {
  root.querySelectorAll('.tx-analytics-traffic-sources').forEach((widget) => {
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
