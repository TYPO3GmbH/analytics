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

The Page Performance Bar is above the page content in the
:guilabel:`Web → Page` module. The bar shows these metrics of the current
page:

-   **Views**
-   **Bounce rate**
-   **Avg. time on page**
-   **Continuation rate**: 100 % minus the bounce rate

Each metric shows its value and the trend compared to the previous period.
The trend uses the same rules as the :ref:`Site Performance widget
<widget-site-performance>`:

-   **+n** or **−n**: the value went up or down.
-   **±0**: the value did not change.
-   An info icon: there is no value to compare with, because the previous
    period had no visits.

Move the pointer onto a metric or select it with the keyboard to show a
tooltip. The tooltip shows a description, the values of today and yesterday,
the peak value and a chart with one value for each day.

The bar also has these controls:

-   A period selector
-   :guilabel:`Show all details`: shows the details of all metrics in a panel
    below the bar. Click :guilabel:`Hide all details` to close the panel. The
    bar keeps this state for each backend user.
-   :guilabel:`Show in Analytics`: opens the analytics dashboard for the page.
    For a page of a site that uses the analytics of an active site, the link
    opens the dashboard of the active site. The bar shows the link only when
    you have access to the root page of the site whose dashboard opens.

The bar loads asynchronously — a lightweight skeleton is shown immediately,
and the actual data is fetched via AJAX once the page has loaded. The bar is
hidden in language-comparison mode.

..  note::
    The period selector values come from the :confval:`dashboardPeriods`
    Extension Manager setting.
