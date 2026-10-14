export function escHtml(text) {
  return String(text ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

export function positionTooltip(tooltip, clientX, clientY) {
  tooltip.style.left = '-9999px';
  tooltip.style.top = '-9999px';
  tooltip.style.display = 'block';
  const tooltipWidth = tooltip.offsetWidth;
  const tooltipHeight = tooltip.offsetHeight;
  const viewportWidth = window.innerWidth;
  const viewportHeight = window.innerHeight;
  let x = clientX + 14;
  let y = clientY - Math.round(tooltipHeight / 2);
  if (x + tooltipWidth > viewportWidth - 8) x = clientX - tooltipWidth - 14;
  x = Math.max(8, x);
  y = Math.max(8, Math.min(y, viewportHeight - tooltipHeight - 8));
  tooltip.style.left = x + 'px';
  tooltip.style.top = y + 'px';
}

export function readStorage(key) {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

export function writeStorage(key, value) {
  try {
    localStorage.setItem(key, value);
  } catch {
    // ignore (e.g. Safari private mode)
  }
}

// ─── Accessible tooltip ───────────────────────────────────────────────────────
// Shows the text of [data-tx-analytics-tooltip] on hover and on keyboard focus.
// The trigger itself carries the text for screen readers (aria-label or visually
// hidden text), so the visual tooltip is hidden from the accessibility tree.
// The tooltip lives in <body>: widgets use container queries, which would make
// position: fixed relative to the widget instead of the viewport.

const TOOLTIP_ID = 'tx-analytics-tooltip-global';
const TOOLTIP_HIDE_DELAY = 150;
let tooltipTrigger = null;
let tooltipHideTimer = 0;

function getOrCreateGlobalTooltip() {
  let tooltip = document.getElementById(TOOLTIP_ID);
  if (!tooltip) {
    tooltip = document.createElement('div');
    tooltip.id = TOOLTIP_ID;
    tooltip.className = 'tx-analytics-tooltip';
    tooltip.setAttribute('aria-hidden', 'true');
    tooltip.hidden = true;
    // Keeps the tooltip open while the pointer moves onto it (WCAG 1.4.13).
    tooltip.addEventListener('mouseenter', () => window.clearTimeout(tooltipHideTimer));
    tooltip.addEventListener('mouseleave', () => scheduleHideTooltip());
    document.body.appendChild(tooltip);
  }
  return tooltip;
}

function showTooltip(trigger) {
  window.clearTimeout(tooltipHideTimer);
  const text = trigger.dataset.txAnalyticsTooltip ?? '';
  if (text === '') {
    return;
  }
  const tooltip = getOrCreateGlobalTooltip();
  tooltip.textContent = text;
  tooltip.hidden = false;
  tooltipTrigger = trigger;

  // Above the trigger, centered; below it when there is no room above.
  const gap = 6;
  const rect = trigger.getBoundingClientRect();
  const width = tooltip.offsetWidth;
  const height = tooltip.offsetHeight;
  const x = Math.max(8, Math.min(rect.left + rect.width / 2 - width / 2, window.innerWidth - width - 8));
  const y = rect.top - height - gap >= 8 ? rect.top - height - gap : rect.bottom + gap;
  tooltip.style.left = x + 'px';
  tooltip.style.top = y + 'px';
}

function hideTooltip() {
  window.clearTimeout(tooltipHideTimer);
  const tooltip = document.getElementById(TOOLTIP_ID);
  if (tooltip) {
    tooltip.hidden = true;
  }
  tooltipTrigger = null;
}

function scheduleHideTooltip() {
  window.clearTimeout(tooltipHideTimer);
  tooltipHideTimer = window.setTimeout(hideTooltip, TOOLTIP_HIDE_DELAY);
}

function closestTrigger(target) {
  return target instanceof Element ? target.closest('[data-tx-analytics-tooltip]') : null;
}

export function initTooltips() {
  if (document.documentElement.dataset.txAnalyticsTooltips === 'true') {
    return;
  }
  document.documentElement.dataset.txAnalyticsTooltips = 'true';

  document.addEventListener('mouseover', (e) => {
    const trigger = closestTrigger(e.target);
    if (trigger) showTooltip(trigger);
  });
  document.addEventListener('mouseout', (e) => {
    const trigger = closestTrigger(e.target);
    if (trigger && !trigger.contains(e.relatedTarget)) scheduleHideTooltip();
  });
  document.addEventListener('focusin', (e) => {
    const trigger = closestTrigger(e.target);
    if (trigger) showTooltip(trigger);
  });
  document.addEventListener('focusout', (e) => {
    if (closestTrigger(e.target)) hideTooltip();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && tooltipTrigger) hideTooltip();
  });
  // The page scrolls under a fixed tooltip, so it would point at the wrong place.
  window.addEventListener('scroll', hideTooltip, { passive: true, capture: true });
}
