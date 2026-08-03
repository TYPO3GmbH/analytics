..  include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

..  _changelog-1.0.0:

1.0.0
=====

*   **Release:** First public release — the extension is now available on the
    `TYPO3 Extension Repository (TER) <https://extensions.typo3.org>`__ and
    `Packagist <https://packagist.org>`__.



..  _changelog-0.10.2:

0.10.2
======

*   **Improvement:** When opening the dashboard with a page filter (e.g. from
    the Page Performance Bar), all widgets are now shown instead of only
    page-specific ones.



..  _changelog-0.10.1:

0.10.1
======

*   **Bugfix:** Plan pricing now loads correctly against password-protected API
    environments (e.g. staging). HTTP Basic Auth credentials were missing from
    the ``fetchPlans()`` request, causing silent ``401`` errors and an empty
    pricing overview in the Analytics backend module.
*   **Improvement:** Default contact email changed from ``support@typo3.com``
    to ``analytics@typo3.com``.


..  _changelog-0.10.0:

0.10.0
======

*   **Feature:** The plan selection page now loads dynamically via AJAX and
    supports toggling between monthly and yearly billing. Plans are sorted by
    credit volume.
*   **Improvement:** Verified and extended TYPO3 v14 compatibility — CI now
    runs against TYPO3 v13, v14, and v15 (dev-main).


..  _changelog-0.9.15:

0.9.15
======

*   **Documentation:** TYPO3 RST documentation added — covers all dashboard
    widgets, the Page Performance Bar, configuration reference, installation,
    access control, and a dashboard preset overview. Includes light/dark mode
    screenshots and hover-tooltip galleries for all widgets.
*   **Improvement:** Extended German (de) translation — widget settings,
    chart labels, Traffic Sources section titles, dashboard preset, and
    access control strings are now fully translated.


..  _changelog-0.9.14:

0.9.14
======

*   **Bugfix:** Fixed missing AJAX loading in the Traffic Graph widget on TYPO3 v14.


..  _changelog-0.9.12:

0.9.12
======

*   **Feature:** Dynamic y-axis rescaling in the Traffic Graph — the axis adjusts
    automatically to the visible data range.
*   **Feature:** Legend items in the Traffic Graph are now clickable and toggle
    the corresponding data series on and off.
*   **Feature:** Traffic Sources donut chart now has a pull-out hover effect for
    individual segments.


..  _changelog-0.9.11:

0.9.11
======

*   **Improvement:** Top Pages widget now shows the page slug, language flags,
    and coloured tooltips. Page URLs are now absolute.


..  _changelog-0.9.10:

0.9.10
======

*   **Security:** Fixed multiple security-related issues identified in an audit.


..  _changelog-0.9.9:

0.9.9
=====

*   **Improvement:** Styled hover tooltips added to Traffic Sources and Top Pages
    widgets.
*   **Bugfix:** Fixed Top Pages widget JavaScript not loading correctly on
    TYPO3 v14.


..  _changelog-0.9.8:

0.9.8
=====

*   **Improvement:** Shared JavaScript utilities extracted into a reusable module.
*   **Improvement:** Empty site groups are now hidden in the backend module.


..  _changelog-0.9.7:

0.9.7
=====

*   **Improvement:** Non-manager backend users now open the Analytics dashboard
    with a read-only watcher role.
*   **Improvement:** Sites in the backend module are grouped by active status.
*   **Improvement:** Added labels for unknown device types in Traffic Sources.
*   **Improvement:** No-data states in widgets are rendered as styled backend
    alerts instead of plain text.


..  _changelog-0.9.6:

0.9.6
=====

*   **Bugfix:** Fixed Traffic Sources widget not displaying the no-data state
    correctly.


..  _changelog-0.9.5:

0.9.5
=====

*   **Feature:** The module logo, module icon, and CSP ``frame-src`` are now
    configurable via partner/API configuration.
*   **Improvement:** The Page Performance Bar now loads asynchronously — a
    skeleton is shown immediately while data is fetched in the background.
*   **Bugfix:** Fixed country and browser session totals; "Others" aggregation
    now works correctly in Traffic Sources.


..  _changelog-0.9.0:

0.9.0
=====

*   Initial release with backend module, dashboard widgets (Traffic Graph, Site
    Performance, Top Pages, Traffic Sources), and Page Performance Bar.
*   Development demo data mode for exploring widgets without a live account.
