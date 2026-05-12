import Notification from '@typo3/backend/notification.js';

async function handleFormAjax(e, onSuccess) {
    const form = e.target;
    e.preventDefault();

    const btn = form.querySelector('[type="submit"]');
    btn.disabled = true;

    try {
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });
        const data = await response.json();
        if (data.success) {
            onSuccess(data);
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
        handleFormAjax(e, function (data) {
            Notification.success(data.title ?? '', data.message ?? '', 3);
            setTimeout(function () { location.reload(); }, 1500);
        });
    } else if (form.classList.contains('tx-analytics-status-form')) {
        handleFormAjax(e, function (data) {
            Notification.success(data.title ?? '', '', 3);
            setTimeout(function () { location.reload(); }, 1500);
        });
    }
});
