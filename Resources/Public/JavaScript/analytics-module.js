import Notification from '@typo3/backend/notification.js';
import ImmediateAction from '@typo3/backend/action-button/immediate-action.js';
import Persistent from '@typo3/backend/storage/persistent.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

const STORAGE_KEY = 'tx-analytics-notification';

// Read back by BackendModuleController on the next request.
function storeCollapsed(key, collapsed) {
    Persistent.set('tx_analytics.collapsed.' + key, collapsed ? '1' : '0');
}

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
    e.preventDefault();
    await submitFormAjax(e.target, e.submitter, getNotificationData);
}

async function submitFormAjax(form, submitter, getNotificationData) {
    const btn = submitter ?? form.querySelector('[type="submit"]');
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
        const getNotificationData = (data) => ({ title: data.title ?? '', message: data.message ?? '', dashboardUri: data.dashboardUri ?? null });
        const mode = form.querySelector('.t3js-analytics-registration-mode:checked')?.value;
        if (mode !== 'attach') {
            handleFormAjax(e, getNotificationData);
            return;
        }
        // The data of the site goes into the analytics of the active site for good, so the user confirms first.
        e.preventDefault();
        const submitter = e.submitter;
        const siteName = form.dataset.siteName ?? '';
        const targetName = form.querySelector('select[name="targetSiteIdentifier"] option:checked')?.dataset.siteName ?? '';
        Modal.confirm(
            fillPlaceholders(form.dataset.attachConfirmTitle, siteName, targetName),
            fillPlaceholders(form.dataset.attachConfirmMessage, siteName, targetName),
            SeverityEnum.error,
            [
                { text: form.dataset.attachConfirmCancel ?? 'Cancel', active: true, btnClass: 'btn-default', name: 'cancel', trigger: (event, modal) => modal.hideModal() },
                {
                    text: form.dataset.attachConfirmOk ?? 'OK',
                    btnClass: 'btn-danger',
                    name: 'ok',
                    trigger: (event, modal) => {
                        modal.hideModal();
                        submitFormAjax(form, submitter, getNotificationData);
                    },
                },
            ]
        );
    } else if (form.classList.contains('tx-analytics-status-form')) {
        handleFormAjax(e, (data) => ({ title: data.title ?? '', message: '' }));
    } else if (form.classList.contains('tx-analytics-detach-form')) {
        // The site stops tracking, so the user confirms first.
        e.preventDefault();
        const submitter = e.submitter;
        Modal.confirm(form.dataset.confirmTitle ?? '', form.dataset.confirmMessage ?? '', SeverityEnum.warning, [
            { text: form.dataset.confirmCancel ?? 'Cancel', active: true, btnClass: 'btn-default', name: 'cancel', trigger: (event, modal) => modal.hideModal() },
            {
                text: form.dataset.confirmOk ?? 'OK',
                btnClass: 'btn-warning',
                name: 'ok',
                trigger: (event, modal) => {
                    modal.hideModal();
                    submitFormAjax(form, submitter, (data) => ({ title: data.title ?? '', message: data.message ?? '' }));
                },
            },
        ]);
    }
});

showPendingNotification();

// Fills the numbered placeholders %1$s (site) and %2$s (active site) of a label.
function fillPlaceholders(label, siteName, targetName) {
    // Replacement functions keep a "$" in a site name literal.
    return String(label ?? '').replaceAll('%1$s', () => siteName).replaceAll('%2$s', () => targetName);
}

// Disabled fieldsets keep the inactive mode's required fields out of validation and FormData.
document.addEventListener('change', function (e) {
    const radio = e.target.closest('.t3js-analytics-registration-mode');
    if (!radio) return;
    radio.form.querySelectorAll('[data-registration-mode]').forEach(fieldset => {
        const active = fieldset.dataset.registrationMode === radio.value;
        fieldset.hidden = !active;
        fieldset.disabled = !active;
    });
});

// Collapsible site groups and site detail rows
document.addEventListener('click', function (e) {
    const toggle = e.target.closest('.t3js-analytics-collapse');
    if (!toggle) return;
    const target = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!target) return;

    // "Register" only opens the row; a second click must not close the form it just showed.
    const expand = toggle.dataset.collapseAction === 'open' || target.hidden;
    target.hidden = !expand;
    if (toggle.dataset.collapsedKey) {
        storeCollapsed(toggle.dataset.collapsedKey, !expand);
    }
    document.querySelectorAll('.t3js-analytics-collapse').forEach(t => {
        if (t.getAttribute('aria-controls') === target.id) {
            t.setAttribute('aria-expanded', String(expand));
        }
    });
    if (expand && !toggle.classList.contains('tx-analytics-collapse-toggle')) {
        // A focused radio looks selected, so the mode group itself takes focus.
        target.querySelector('.tx-analytics-registration-mode, input:not([type="hidden"])')?.focus();
    }
});

// Plans
async function initPlans() {
    const root = document.getElementById('tx-analytics-plans-root');
    if (!root) return;

    const creditsFormat = root.dataset.i18nCredits;
    const badgeTrialText = root.dataset.i18nBadgeTrial;
    const hideText = root.dataset.i18nHide;
    const showText = root.dataset.i18nShow;

    try {
        const resp = await fetch(root.dataset.plansUrl);
        const { plans, contactEmail, showCustomPlan, locale } = await resp.json();
        if (!Array.isArray(plans) || plans.length === 0) {
            document.getElementById('tx-analytics-plans-section')?.remove();
            return;
        }
        renderPlans(root, plans, creditsFormat, badgeTrialText, contactEmail ?? 'support@typo3.com', showCustomPlan !== false, locale ?? 'en', hideText, showText);
    } catch {
        document.getElementById('tx-analytics-plans-section')?.remove();
    }
}

function cloneTpl(id) {
    return document.getElementById(id).content.cloneNode(true);
}

function renderPlans(root, plans, creditsFormat, badgeTrialText, contactEmail, showCustomPlan, locale, hideText, showText) {
    const header = cloneTpl('tpl-plans-header').firstElementChild;

    const grid = document.createElement('div');
    grid.className = 'card-container tx-analytics-plans-card-container';
    for (const plan of plans) {
        grid.appendChild(buildPlanCard(plan, creditsFormat, badgeTrialText, locale));
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

    const collapseBtn = header.querySelector('.tx-analytics-collapse-toggle');
    const collapseLink = header.querySelector('.tx-analytics-plans-collapse-link');
    const periodToggle = header.querySelector('.tx-analytics-plans-toggle');

    const setCollapsed = collapsed => {
        collapseBtn.setAttribute('aria-expanded', String(!collapsed));
        collapseLink.textContent = collapsed ? showText : hideText;
        periodToggle.hidden = collapsed;
        grid.hidden = collapsed;
        vatNotice.hidden = collapsed;
    };

    const toggleCollapsed = () => {
        const collapsed = collapseBtn.getAttribute('aria-expanded') === 'true';
        storeCollapsed('plans', collapsed);
        setCollapsed(collapsed);
    };

    collapseBtn.addEventListener('click', toggleCollapsed);
    collapseLink.addEventListener('click', toggleCollapsed);

    setCollapsed(root.dataset.collapsed === '1');
}

function buildPlanCard(plan, creditsFormat, badgeTrialText, locale) {
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
        setPrice(priceFrag.querySelector('[data-slot="monthly-price"]'), plan.monthlyPrice, plan.currency, locale);
        const strikeEl = priceFrag.querySelector('[data-slot="strike-price"]');
        if (plan.monthlyPrice) {
            setPrice(strikeEl, plan.monthlyPrice, plan.currency, locale);
        } else {
            strikeEl.remove();
        }
        setPrice(priceFrag.querySelector('[data-slot="yearly-price"]'), plan.monthlyEquiv ?? plan.yearlyPrice ?? 0, plan.currency, locale);
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
        dashIcon.closest('.list-group-item').classList.add('tx-analytics-plan-features-item--muted');
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

function setPrice(el, price, currency, locale) {
    let formatter;
    try {
        formatter = new Intl.NumberFormat(locale, { style: 'currency', currency });
    } catch {
        formatter = new Intl.NumberFormat('en', { style: 'currency', currency });
    }
    for (const part of formatter.formatToParts(price)) {
        if (part.type === 'currency') {
            const span = document.createElement('span');
            span.className = 'tx-analytics-plan-price-currency';
            span.textContent = part.value;
            el.appendChild(span);
        } else {
            el.append(part.value);
        }
    }
}

function fmt(str, ...args) {
    let i = 0;
    return String(str ?? '').replace(/%s/g, () => String(args[i++] ?? '')).replace(/%%/g, '%');
}

initPlans();
