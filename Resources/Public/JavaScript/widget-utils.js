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
