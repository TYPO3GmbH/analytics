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

// Plans
async function initPlans() {
    const root = document.getElementById('tx-analytics-plans-root');
    if (!root) return;
    const i18n = {
        heading:               root.dataset.i18nHeading,
        toggleMonthly:         root.dataset.i18nToggleMonthly,
        toggleYearly:          root.dataset.i18nToggleYearly,
        free:                  root.dataset.i18nFree,
        trialNote:             root.dataset.i18nTrialNote,
        credits:               root.dataset.i18nCredits,
        apiAccess:             root.dataset.i18nApiAccess,
        dashboards:            root.dataset.i18nDashboards,
        perMonth:              root.dataset.i18nPerMonth,
        perYear:               root.dataset.i18nPerYear,
        badgeTrial:            root.dataset.i18nBadgeTrial,
        customName:            root.dataset.i18nCustomName,
        customTagline:         root.dataset.i18nCustomTagline,
        customContact:         root.dataset.i18nCustomContact,
        customEmailSubject:    root.dataset.i18nCustomEmailSubject,
        customCredits:         root.dataset.i18nCustomCredits,
    };
    try {
        const resp = await fetch(root.dataset.plansUrl);
        const { plans } = await resp.json();
        if (!Array.isArray(plans) || plans.length === 0) {
            document.getElementById('tx-analytics-plans-section')?.remove();
            return;
        }
        renderPlans(root, plans, i18n);
    } catch {
        document.getElementById('tx-analytics-plans-section')?.remove();
    }
}

function renderPlans(root, plans, i18n) {
    const header = document.createElement('div');
    header.className = 'tx-analytics-plans-header';
    header.innerHTML = `
        <h2 class="tx-analytics-site-group-title" style="margin:0">${esc(i18n.heading)}</h2>
        <div class="tx-analytics-plans-toggle" role="group">
            <button type="button" class="tx-analytics-plans-toggle-btn active" data-period="monthly">${esc(i18n.toggleMonthly)}</button>
            <button type="button" class="tx-analytics-plans-toggle-btn" data-period="yearly">${esc(i18n.toggleYearly)}</button>
        </div>`;

    const grid = document.createElement('div');
    grid.className = 'card-container tx-analytics-plans-card-container';
    plans.forEach(plan => grid.insertAdjacentHTML('beforeend', planCardHtml(plan, i18n)));
    grid.insertAdjacentHTML('beforeend', customPlanCardHtml(i18n));

    const total = plans.length + 1;
    grid.style.setProperty('--plan-count', total);
    grid.style.setProperty('--plan-cols-narrow', Math.min(total, 3));

    root.appendChild(header);
    root.appendChild(grid);

    header.querySelector('.tx-analytics-plans-toggle').addEventListener('click', e => {
        const btn = e.target.closest('[data-period]');
        if (!btn) return;
        const period = btn.dataset.period;
        header.querySelectorAll('.tx-analytics-plans-toggle-btn').forEach(b => b.classList.toggle('active', b === btn));
        grid.querySelectorAll('.tx-analytics-price-period').forEach(el => {
            el.hidden = el.dataset.period !== period;
        });
    });
}

function planCardHtml(plan, i18n) {
    const title = plan.isTrial ? esc(i18n.badgeTrial) : esc(plan.displayName);
    let prices;
    if (plan.isFree) {
        const sublabel = plan.isTrial
            ? `<span class="tx-analytics-plan-price-sublabel">${esc(i18n.trialNote)}</span>`
            : `<span class="tx-analytics-plan-price-sublabel" style="visibility:hidden" aria-hidden="true">&nbsp;</span>`;
        const freeContent = `
                <span class="tx-analytics-plan-price-amount">${esc(i18n.free)}</span>
                ${sublabel}
                <span class="tx-analytics-plan-price-sublabel tx-analytics-plan-price-yearly-total" style="visibility:hidden" aria-hidden="true">&nbsp;</span>`;
        prices = `
            <div class="tx-analytics-price-period" data-period="monthly">${freeContent}</div>
            <div class="tx-analytics-price-period" data-period="yearly" hidden>${freeContent}</div>`;
    } else {
        const yearlyTotalLine = plan.yearlyPrice
            ? `<span class="tx-analytics-plan-price-sublabel tx-analytics-plan-price-yearly-total">€ ${esc(plan.yearlyPrice)} ${esc(i18n.perYear)}</span>`
            : '';
        prices = `
            <div class="tx-analytics-price-period" data-period="monthly">
                <span class="tx-analytics-plan-price-amount">€ ${esc(plan.monthlyPrice)}</span>
                <span class="tx-analytics-plan-price-sublabel">${esc(i18n.perMonth)}</span>
                ${yearlyTotalLine ? `<span class="tx-analytics-plan-price-sublabel tx-analytics-plan-price-yearly-total" style="visibility:hidden" aria-hidden="true">&nbsp;</span>` : ''}
            </div>
            <div class="tx-analytics-price-period" data-period="yearly" hidden>
                <span class="tx-analytics-plan-price-amount">€ ${esc(plan.monthlyEquiv ?? plan.yearlyPrice ?? '')}</span>
                <span class="tx-analytics-plan-price-sublabel">${esc(i18n.perMonth)}</span>
                ${yearlyTotalLine}
            </div>`;
    }
    const check = `<span class="tx-analytics-plan-icon tx-analytics-plan-icon--check">✓</span>`;
    const minus = `<span class="tx-analytics-plan-icon tx-analytics-plan-icon--minus">–</span>`;
    return `
        <div class="card tx-analytics-plan-card">
            <div class="card-header">
                <div class="card-header-body">
                    <h2 class="card-title">${title}</h2>
                </div>
            </div>
            <div class="tx-analytics-plan-prices">${prices}</div>
            <ul class="list-group list-group-flush tx-analytics-plan-features">
                <li class="list-group-item">${check} ${fmt(i18n.credits, plan.touchpointsFormatted)}</li>
                <li class="list-group-item">${check} ${esc(i18n.apiAccess)}</li>
                <li class="list-group-item">${plan.hasOwnDashboards ? check : minus} ${esc(i18n.dashboards)}</li>
            </ul>
        </div>`;
}

function customPlanCardHtml(i18n) {
    const check = `<span class="tx-analytics-plan-icon tx-analytics-plan-icon--check">✓</span>`;
    const mailtoHref = `mailto:support@typo3.com?subject=${encodeURIComponent(i18n.customEmailSubject ?? 'Custom Plan Inquiry')}`;
    return `
        <div class="card tx-analytics-plan-card tx-analytics-plan-card--custom">
            <div class="card-header">
                <div class="card-header-body">
                    <h2 class="card-title">${esc(i18n.customName)}</h2>
                </div>
            </div>
            <div class="tx-analytics-plan-prices tx-analytics-plan-prices--custom">
                <div class="tx-analytics-plan-custom-cta">
                    <a href="${mailtoHref}" target="_blank" rel="noreferrer" class="btn btn-primary btn-sm tx-analytics-plan-contact-btn">${esc(i18n.customContact)}</a>
                    <span class="tx-analytics-plan-price-custom-tagline">${esc(i18n.customTagline)}</span>
                    <span class="tx-analytics-plan-price-sublabel tx-analytics-plan-price-yearly-total" style="visibility:hidden" aria-hidden="true">&nbsp;</span>
                </div>
            </div>
            <ul class="list-group list-group-flush tx-analytics-plan-features">
                <li class="list-group-item">${check} ${esc(i18n.customCredits)}</li>
                <li class="list-group-item">${check} ${esc(i18n.apiAccess)}</li>
                <li class="list-group-item">${check} ${esc(i18n.dashboards)}</li>
            </ul>
        </div>`;
}

function esc(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
}

function fmt(str, ...args) {
    let i = 0;
    return String(str ?? '').replace(/%s/g, () => esc(String(args[i++] ?? ''))).replace(/%%/g, '%');
}

initPlans();
