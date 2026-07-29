..  include:: /Includes.rst.txt

..  _page-performance-bar:

====================
Page Performance Bar
====================

..  tabs::

    ..  tab:: Light

        ..  figure:: ../Images/page-performance-bar-light.png
            :alt: Page Performance Bar
            :zoom: gallery
            :gallery: page-performance-bar
            :align: center
            :class: with-shadow

    ..  tab:: Dark

        ..  figure:: ../Images/page-performance-bar-dark.png
            :alt: Page Performance Bar (dark)
            :zoom: gallery
            :gallery: page-performance-bar
            :align: center
            :class: with-shadow

    ..  tab:: Tooltip: Views

        ..  figure:: ../Images/page-performance-bar-light-tooltip-views.png
            :alt: Tooltip: Views
            :zoom: gallery
            :gallery: page-performance-bar
            :align: center
            :class: with-shadow

    ..  tab:: Tooltip: Bounce Rate

        ..  figure:: ../Images/page-performance-bar-light-tooltip-bounce.png
            :alt: Tooltip: Bounce Rate
            :zoom: gallery
            :gallery: page-performance-bar
            :align: center
            :class: with-shadow

    ..  tab:: Tooltip: Avg. Time on Page

        ..  figure:: ../Images/page-performance-bar-light-tooltip-avgtime.png
            :alt: Tooltip: Avg. Time on Page
            :zoom: gallery
            :gallery: page-performance-bar
            :align: center
            :class: with-shadow

    ..  tab:: Tooltip: Continuation Rate

        ..  figure:: ../Images/page-performance-bar-light-tooltip-continuation.png
            :alt: Tooltip: Continuation Rate
            :zoom: gallery
            :gallery: page-performance-bar
            :align: center
            :class: with-shadow

The Page Performance Bar appears above the page content in the
:guilabel:`Web → Page` module. It shows analytics metrics for the currently
viewed page:

-   **Page views** with sparkline and trend vs. previous period
-   **Bounce rate** with sparkline and trend
-   **Average time on page** with sparkline and trend
-   **Continuation rate** (100 − bounce rate) with sparkline and trend

A period selector and a direct link to the full analytics dashboard are
included in the bar.

The bar loads asynchronously — a lightweight skeleton is shown immediately,
and the actual data is fetched via AJAX once the page has loaded. The bar is
hidden in language-comparison mode.

..  note::
    The period selector values come from the :confval:`dashboardPeriods`
    Extension Manager setting.
