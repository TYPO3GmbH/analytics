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

    const creditsFormat = root.dataset.i18nCredits;
    const badgeTrialText = root.dataset.i18nBadgeTrial;

    try {
        const resp = await fetch(root.dataset.plansUrl);
        const { plans, contactEmail, showCustomPlan } = await resp.json();
        if (!Array.isArray(plans) || plans.length === 0) {
            document.getElementById('tx-analytics-plans-section')?.remove();
            return;
        }
        renderPlans(root, plans, creditsFormat, badgeTrialText, contactEmail ?? 'support@typo3.com', showCustomPlan !== false);
    } catch {
        document.getElementById('tx-analytics-plans-section')?.remove();
    }
}

function cloneTpl(id) {
    return document.getElementById(id).content.cloneNode(true);
}

function renderPlans(root, plans, creditsFormat, badgeTrialText, contactEmail, showCustomPlan) {
    const header = cloneTpl('tpl-plans-header').firstElementChild;

    const grid = document.createElement('div');
    grid.className = 'card-container tx-analytics-plans-card-container';
    for (const plan of plans) {
        grid.appendChild(buildPlanCard(plan, creditsFormat, badgeTrialText));
    }
    if (showCustomPlan) {
        grid.appendChild(buildCustomCard(contactEmail));
    }

    const total = plans.length + (showCustomPlan ? 1 : 0);
    grid.style.setProperty('--plan-count', total);
    grid.style.setProperty('--plan-cols-narrow', Math.min(total, 3));

    const vatNotice = cloneTpl('tpl-plans-vat-notice').firstElementChild;

    root.appendChild(header);
    root.appendChild(grid);
    root.appendChild(vatNotice);

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

function buildPlanCard(plan, creditsFormat, badgeTrialText) {
    const frag = cloneTpl('tpl-plan-card');
    const card = frag.firstElementChild;

    card.querySelector('[data-slot="title"]').textContent =
        plan.isTrial ? badgeTrialText : plan.displayName;

    const pricesContainer = card.querySelector('.tx-analytics-plan-prices');
    if (plan.isFree) {
        const priceFrag = cloneTpl('tpl-price-free');
        if (plan.isTrial) {
            priceFrag.querySelectorAll('.tx-analytics-plan-price-sublabel--trial').forEach(el => { el.hidden = false; });
            priceFrag.querySelectorAll('.tx-analytics-plan-price-sublabel--nontrial').forEach(el => el.remove());
        } else {
            priceFrag.querySelectorAll('.tx-analytics-plan-price-sublabel--trial').forEach(el => el.remove());
        }
        pricesContainer.append(priceFrag);
    } else {
        const priceFrag = cloneTpl('tpl-price-paid');
        setPrice(priceFrag.querySelector('[data-slot="monthly-price"]'), plan.monthlyPrice);
        const strikeEl = priceFrag.querySelector('[data-slot="strike-price"]');
        if (plan.monthlyPrice) {
            setPrice(strikeEl, plan.monthlyPrice);
        } else {
            strikeEl.remove();
        }
        setPrice(priceFrag.querySelector('[data-slot="yearly-price"]'), plan.monthlyEquiv ?? plan.yearlyPrice ?? '');
        pricesContainer.append(priceFrag);
    }

    const creditsLi = card.querySelector('[data-slot="credits"]');
    creditsLi.append(document.createTextNode(' ' + fmt(creditsFormat, plan.touchpointsFormatted)));

    const dashIcon = card.querySelector('[data-slot="dashboards-icon"]');
    if (plan.hasOwnDashboards) {
        dashIcon.classList.add('tx-analytics-plan-icon--check');
        dashIcon.textContent = '✓';
    } else {
        dashIcon.classList.add('tx-analytics-plan-icon--minus');
        dashIcon.textContent = '–';
    }

    return card;
}

function buildCustomCard(contactEmail) {
    const frag = cloneTpl('tpl-plan-card-custom');
    const card = frag.firstElementChild;
    const btn = card.querySelector('.tx-analytics-plan-contact-btn');
    btn.href = `mailto:${contactEmail}?subject=${encodeURIComponent(btn.dataset.emailSubject ?? '')}`;
    return card;
}

function setPrice(el, price) {
    el.append(`€ ${price}`);
    const sup = document.createElement('sup');
    sup.className = 'tx-analytics-plan-price-sup';
    sup.textContent = '*';
    el.appendChild(sup);
}

function fmt(str, ...args) {
    let i = 0;
    return String(str ?? '').replace(/%s/g, () => String(args[i++] ?? '')).replace(/%%/g, '%');
}

initPlans();
