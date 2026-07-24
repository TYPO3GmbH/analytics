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

*   The site has not been registered yet, or registration is still pending.
    Check the status in :guilabel:`Sites → TYPO3 Analytics → Status`.
*   The selected period has no recorded visits. Try a longer period (e.g. 30 days).
*   The tracking script is not injected. Verify that the TYPO3 Analytics Site Set
    is added to the site configuration under
    :guilabel:`Site Management → Sites → Sets`.
*   Demo data mode is active but the TYPO3 application context is not
    ``Development``. The ``demoData`` setting only takes effect in the
    Development context.


Tracking script is not injected on the frontend
================================================

*   Confirm that the **TYPO3 Analytics** Site Set is listed under
    :guilabel:`Site Management → Sites → Sets` for the affected site.
*   Check that the site status is **active** in the backend module. A
    ``pending`` or ``cancelled`` status means tracking is not yet live.
*   Make sure no caching layer (reverse proxy, CDN) is serving a stale page
    that was cached before the Site Set was added.


Registration fails or returns an error
=======================================

*   Only backend administrators and users with the **Analytics Manager** custom
    option can register sites. See :ref:`access-control`.
*   Verify that the server can reach the TYPO3 Analytics API. Firewall rules or
    outbound proxy configurations may block the request.
*   Check :guilabel:`Admin Tools → Log` for flash messages that contain the
    specific API error returned.


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
    If the API is unreachable, the iframe cannot be loaded.
*   Non-manager users are granted a read-only ``watcher`` role. If the
    Analytics dashboard itself shows a permission error, confirm the user's
    role with the account administrator at
    `analytics.typo3.com <https://analytics.typo3.com>`__.


Status is stuck on "pending"
=============================

Registration confirmation is sent by the TYPO3 Analytics API after the
e-mail address is verified. If the status remains ``pending`` for more than
a few minutes:

*   Check the inbox (including spam folder) of the e-mail address used during
    registration for a confirmation message.
*   Use the :guilabel:`Refresh status` button to force a fresh API lookup.
*   Contact support at `analytics.typo3.com <https://analytics.typo3.com>`__
    if the issue persists.
