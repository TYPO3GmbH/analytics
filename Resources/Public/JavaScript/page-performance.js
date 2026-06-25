async function reloadPerformanceBar(section, days) {
    const ajaxUrl = TYPO3?.settings?.ajaxUrls?.analytics_page_performance_content;
    if (!ajaxUrl) {
        return;
    }

    const pageId = section.dataset.pageId;
    const languageId = section.dataset.languageId ?? '0';

    section.classList.add('tx-analytics-performance-bar--loading');

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
    }
}

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
