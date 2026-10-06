async function reloadPerformanceBar(section, days) {
    const ajaxUrl = TYPO3?.settings?.ajaxUrls?.analytics_page_performance_content;
    if (!ajaxUrl) {
        return;
    }

    const pageId = section.dataset.pageId;
    const languageId = section.dataset.languageId ?? '0';
    const loadingOverlay = section.querySelector('.tx-analytics-performance-loading-overlay');

    section.classList.add('tx-analytics-performance-bar--loading');
    section.setAttribute('aria-busy', 'true');
    loadingOverlay?.removeAttribute('aria-hidden');

    try {
        const url = new URL(ajaxUrl, window.location.origin);
        url.searchParams.set('pageId', pageId);
        url.searchParams.set('days', String(days));
        url.searchParams.set('languageId', languageId);

        const response = await fetch(url.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
            section.classList.remove('tx-analytics-performance-bar--loading');
            section.removeAttribute('aria-busy');
            loadingOverlay?.setAttribute('aria-hidden', 'true');
            return;
        }

        const data = await response.json();
        if (typeof data.html === 'string') {
            const temp = document.createElement('div');
            temp.innerHTML = data.html;
            const newSection = temp.firstElementChild;
            if (newSection instanceof HTMLElement) {
                section.replaceWith(newSection);
            }
        }
    } catch {
        section.classList.remove('tx-analytics-performance-bar--loading');
        section.removeAttribute('aria-busy');
        loadingOverlay?.setAttribute('aria-hidden', 'true');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.tx-analytics-performance-bar[data-page-id]').forEach((section) => {
        if (!(section instanceof HTMLElement)) return;
        reloadPerformanceBar(section, parseInt(section.dataset.days ?? '7', 10));
    });
});

// Event delegation on document survives section replacement after AJAX updates.
document.addEventListener('change', (e) => {
    const select = e.target;
    if (!(select instanceof HTMLSelectElement)) return;
    if (!select.closest('.tx-analytics-performance-period-form')) return;

    const section = select.closest('.tx-analytics-performance-bar');
    if (!section || !(section instanceof HTMLElement) || !section.dataset.pageId) {
        select.form?.submit();
        return;
    }

    e.preventDefault();
    reloadPerformanceBar(section, select.value);
});

document.addEventListener('click', (e) => {
    const toggle = e.target instanceof Element ? e.target.closest('.tx-analytics-performance-details-toggle') : null;
    if (!(toggle instanceof HTMLElement)) return;

    const section = toggle.closest('.tx-analytics-performance-bar');
    const details = document.getElementById(toggle.getAttribute('aria-controls') ?? '');
    if (!section || !details) return;

    const expanded = toggle.getAttribute('aria-expanded') !== 'true';
    toggle.setAttribute('aria-expanded', String(expanded));
    const label = toggle.querySelector('.tx-analytics-performance-details-toggle-label');
    if (label) {
        label.textContent = (expanded ? toggle.dataset.labelHide : toggle.dataset.labelShow) ?? '';
    }
    details.hidden = !expanded;
    section.classList.toggle('tx-analytics-performance-bar--expanded', expanded);

    import('@typo3/backend/storage/persistent.js')
        .then(({ default: Persistent }) => Persistent.set('tx_analytics.pagePerformanceExpanded', expanded ? '1' : '0'))
        .catch(() => {});
});
