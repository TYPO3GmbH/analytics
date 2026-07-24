..  include:: /Includes.rst.txt

..  _backend-module:

==============
Backend module
==============

The **Sites → Analytics** module is available in the TYPO3 backend under
:guilabel:`Sites → TYPO3 Analytics`. It provides a per-site view with three
tabs.


Registration
============

Use this tab to register a TYPO3 site with the TYPO3 Analytics API. Enter
an e-mail address and click **Register**. Once registration is complete, the
TYPO3 Analytics tracking script is automatically injected into every frontend
page of that site.

..  note::
    Registration and plan management are only available to backend
    administrators and users with the **Analytics Manager** custom option.
    See :ref:`access-control`.


Status
======

Shows the current registration status fetched from the API, including the
website ID and API key. The status is cached for 24 hours and can be
refreshed manually using the **Refresh status** button.

Possible status values:

-   **pending** — registration submitted, awaiting confirmation
-   **active** — site is registered and tracking is live
-   **inactive** — site is registered but tracking is paused
-   **cancelled** — subscription has been cancelled


Dashboard
=========

Opens the TYPO3 Analytics web dashboard as an embedded iframe inside the
TYPO3 backend. All analytics data for the selected site is available here
without leaving TYPO3.
