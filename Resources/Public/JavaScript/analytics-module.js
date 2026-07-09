import Notification from '@typo3/backend/notification.js';
import ImmediateAction from '@typo3/backend/action-button/immediate-action.js';

const STORAGE_KEY = 'tx-analytics-notification';

function showPendingNotification() {
    const pending = sessionStorage.getItem(STORAGE_KEY);
    if (!pending) return;
    sessionStorage.removeItem(STORAGE_KEY);
    try {
        const { title, message, dashboardUri } = JSON.parse(pending);
        // Only follow same-origin relative backend paths, never absolute/protocol-relative/javascript: URLs.
        const safeUri = typeof dashboardUri === 'string' && /^\/(?!\/)/.test(dashboardUri) ? dashboardUri : null;
        const actions = safeUri
            ? [{ label: 'Dashboard', action: new ImmediateAction(() => { window.location.href = safeUri; }) }]
            : [];
        Notification.success(title, message, 5, actions);
    } catch {
        // ignore malformed storage entry
    }
}

async function handleFormAjax(e, getNotificationData) {
    const form = e.target;
    e.preventDefault();

    const btn = form.querySelector('[type="submit"]');
    btn.disabled = true;

    try {
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });
        const data = await response.json();
        if (data.success) {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(getNotificationData(data)));
            location.reload();
        } else {
            Notification.error(data.title ?? 'Error', data.message ?? '', 0);
            btn.disabled = false;
        }
    } catch {
        Notification.error('Error', 'An unexpected error occurred.', 0);
        btn.disabled = false;
    }
}

document.addEventListener('submit', function (e) {
    const form = e.target;
    if (form.classList.contains('tx-analytics-registration-form')) {
        handleFormAjax(e, (data) => ({ title: data.title ?? '', message: data.message ?? '', dashboardUri: data.dashboardUri ?? null }));
    } else if (form.classList.contains('tx-analytics-status-form')) {
        handleFormAjax(e, (data) => ({ title: data.title ?? '', message: '' }));
    }
});

showPendingNotification();
