..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

TYPO3 Analytics is a web analytics service built for TYPO3. This extension
integrates it directly into the TYPO3 backend, so editors and administrators
can access analytics data without switching to a separate tool.

..  note::
    After the free trial period, a paid subscription is required. Pricing and
    plan details are available at `analytics.typo3.com <https://analytics.typo3.com>`__.


What the extension provides
===========================

The extension adds a **Sites → Analytics** module to the TYPO3 backend.
For each configured TYPO3 site it provides:

**Registration**
    Enter an e-mail address to register the site with the TYPO3 Analytics API.
    The tracking code is then automatically injected into every frontend page.

**Status display**
    Shows the current registration status, website ID and API key.
    The status is cached and can be refreshed manually.

**Dashboard**
    Opens the TYPO3 Analytics dashboard as an embedded iframe inside the
    TYPO3 backend.

**Dashboard widgets**
    Four widget types for the TYPO3 dashboard — Traffic Graph, Site
    Performance, Top Pages, and Traffic Sources — give editors an at-a-glance
    view of their site's analytics directly on the dashboard.

**Page Performance Bar**
    An analytics bar above the page layout in the **Page** module shows
    per-page metrics such as page views, bounce rate, and average time on page.


Requirements
============

..  list-table::
    :header-rows: 1
    :widths: 30 70

    *   -   Component
        -   Version
    *   -   PHP
        -   ^8.2
    *   -   TYPO3
        -   ^13.4 or ^14.0
    *   -   libsodium
        -   PHP extension (bundled since PHP 7.2)
