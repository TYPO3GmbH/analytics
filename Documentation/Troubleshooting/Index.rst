..  include:: /Includes.rst.txt

..  _troubleshooting:

===============
Troubleshooting
===============

..  contents::
    :local:
    :depth: 1


Widgets show "No data available"
=================================

**Possible causes:**

*   The selected period has no recorded visits yet. Try a longer period
    (e.g. 30 days), or wait until the tracking script has collected data.
*   The cached API response does not yet contain data. The cache TTL is
    controlled by :confval:`pageAnalyticsCacheTtl` (default: 1 hour).


Tracking script is not injected on the frontend
================================================

The tracking script is injected automatically when a site is successfully
registered. If it is missing:

*   Confirm that registration completed and the site status is **active**.
    A ``pending`` or ``cancelled`` status means tracking is not yet live.
*   Make sure no caching layer (reverse proxy, CDN) is serving a stale page
    that was cached before the site was registered.


Registration fails or returns an error
=======================================

*   Only backend administrators and users with the **Analytics Manager** custom
    option can register sites. See :ref:`access-control`.
*   Verify that the server can reach the TYPO3 Analytics API. Firewall rules or
    outbound proxy configurations may block the request.
*   Check :guilabel:`Admin Tools → Log` for the specific API error returned.


"Access denied" flash message in the backend module
====================================================

The action requires the **Analytics Manager** permission. Grant it by opening
:guilabel:`System → Backend Users → Backend Groups`, editing the relevant group,
switching to the :guilabel:`Custom Options` tab, and enabling
:guilabel:`Analytics → Analytics Manager`.

See :ref:`access-control` for the full list of restricted actions.


Page Performance Bar does not appear
======================================

*   The bar is only shown in the :guilabel:`Web → Page` module when a single
    language is selected. It is hidden in language-comparison mode (when
    multiple languages are displayed side by side).
*   The bar loads asynchronously. If the page module loads but the bar never
    appears, open the browser developer tools and check the network tab for a
    failed AJAX request.
*   Confirm the site is registered and its status is **active**.


Dashboard iframe is blank or shows an error
============================================

*   The iframe URL is retrieved from the TYPO3 Analytics API on each request.
    If the API is unreachable, the iframe cannot be loaded. Check
    :guilabel:`Admin Tools → Log` for API errors.
*   Non-manager backend users receive a read-only dashboard link. This is
    expected behaviour — they can view data but cannot change settings.


Tracking has stopped / credits exhausted
=========================================

When a site's credits are used up, tracking stops immediately — no further
visits are recorded until the credit balance resets or the plan is upgraded.
The backend module shows a warning on the affected site card.

*   Check the credit usage displayed on the site card.
*   Use the :guilabel:`Manage Plan` link to upgrade the subscription or wait
    for the monthly credit reset (the reset date is shown on the site card).
*   Use the :guilabel:`Refresh status` button to fetch the current credit
    balance bypassing the 24-hour cache, for example to check whether a reset
    has already occurred.



Demo data does not appear
==========================

The :confval:`demoData` setting only takes effect when the TYPO3 application
context is ``Development``. If demo data is enabled but the context is
``Production`` or ``Testing``, the extension falls back to real API calls —
which means no data is shown if the site is not yet registered or has no
recorded visits.

*   Verify the application context is set to ``Development``, for example by
    checking the web server environment variable ``TYPO3_CONTEXT``.


Further assistance
==================

If your issue is not covered above, contact the TYPO3 Analytics support team
at `support@typo3.com <mailto:support@typo3.com?subject=TYPO3%20Analytics%20Support%20Request>`__.
