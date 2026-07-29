..  include:: /Includes.rst.txt

..  _dashboard-widgets:

=================
Dashboard widgets
=================

The extension provides four widget types for the TYPO3 dashboard. Each widget
type is available in a TYPO3 v13 variant (inline dropdowns, AJAX-driven) and a
TYPO3 v14+ variant (native widget settings panel).

All v14+ widgets share two common settings:

..  list-table::
    :header-rows: 1
    :widths: 25 15 15 45

    *   -   Setting
        -   Type
        -   Default
        -   Description
    *   -   **Title**
        -   string
        -   *(widget default)*
        -   Overrides the widget title shown in the dashboard header.
            Leave empty to use the default.
    *   -   **Show site & period in title**
        -   bool
        -   ``true``
        -   Appends the site name and period in parentheses to the title,
            e.g. :guilabel:`Traffic Sources (My Site · 7 days)`.


..  _widget-traffic-graph:

Traffic Graph
=============

..  tabs::

    ..  tab:: Light

        ..  figure:: ../Images/widget-traffic-graph-light.png
            :alt: Traffic Graph widget
            :zoom: gallery
            :gallery: traffic-graph
            :align: center

    ..  tab:: Dark

        ..  figure:: ../Images/widget-traffic-graph-dark.png
            :alt: Traffic Graph (dark)
            :zoom: gallery
            :gallery: traffic-graph
            :align: center

    ..  tab:: With tooltip

        ..  figure:: ../Images/widget-traffic-graph-light-tooltip.png
            :alt: Traffic Graph (with tooltip)
            :zoom: gallery
            :gallery: traffic-graph
            :align: center

    ..  tab:: Metric: Visits

        ..  figure:: ../Images/widget-traffic-graph-single-visits.png
            :alt: Metric: Visits
            :zoom: gallery
            :gallery: traffic-graph
            :align: center

    ..  tab:: Metric: Sessions

        ..  figure:: ../Images/widget-traffic-graph-single-sessions.png
            :alt: Metric: Sessions
            :zoom: gallery
            :gallery: traffic-graph
            :align: center

    ..  tab:: Metric: New visitors

        ..  figure:: ../Images/widget-traffic-graph-single-new-visitors.png
            :alt: Metric: New visitors
            :zoom: gallery
            :gallery: traffic-graph
            :align: center

    ..  tab:: Metric: Returning visitors

        ..  figure:: ../Images/widget-traffic-graph-single-returning.png
            :alt: Metric: Returning visitors
            :zoom: gallery
            :gallery: traffic-graph
            :align: center

    ..  tab:: Metric: Total visitors

        ..  figure:: ../Images/widget-traffic-graph-single-total.png
            :alt: Metric: Total visitors
            :zoom: gallery
            :gallery: traffic-graph
            :align: center

Displays a full-width line chart of daily visit counts for a configured site
over the selected period. A :guilabel:`Show in Analytics` link opens the main
analytics dashboard.

Each metric in the legend (**Visits**, **Sessions**, **New visitors**,
**Returning visitors**, **Total visitors**) can be toggled on and off
individually by clicking its legend button — useful for focusing on a single
data series. The y-axis rescales automatically to the visible data range when
series are toggled. Hovering over the chart reveals a tooltip with exact values
for that date.

Widget settings (v14+): **Site**, **Period** (days).


..  _widget-site-performance:

Site Performance
================

..  tabs::

    ..  tab:: Light mode

        ..  figure:: ../Images/widget-site-performance-light.png
            :alt: Site Performance widget (light)
            :zoom: gallery
            :gallery: site-performance
            :align: center

    ..  tab:: Dark mode

        ..  figure:: ../Images/widget-site-performance-dark.png
            :alt: Site Performance widget (dark)
            :zoom: gallery
            :gallery: site-performance
            :align: center

Displays four metric tiles — **Visits**, **Visitors**, **Bounce rate**, and
**Avg. visit duration** — each showing the current value and a trend arrow
compared to the previous period of equal length. A :guilabel:`Show all` link
leads to the analytics dashboard.

Widget settings (v14+): **Site**, **Period** (days).


..  _widget-top-pages:

Top Pages
=========

..  tabs::

    ..  tab:: Light mode

        ..  figure:: ../Images/widget-top-pages-light.png
            :alt: Top Pages widget (light)
            :zoom: gallery
            :gallery: top-pages
            :align: center

    ..  tab:: Dark mode

        ..  figure:: ../Images/widget-top-pages-dark.png
            :alt: Top Pages widget (dark)
            :zoom: gallery
            :gallery: top-pages
            :align: center

Displays a ranked list of the most-visited pages for a configured site. Each
row shows the page title, URL, and view count. A :guilabel:`Show all` link
leads to the pages view of the analytics dashboard.

Widget settings (v14+): **Site**, **Period** (days), **Limit** (number of
pages shown).


..  _widget-traffic-sources:

Traffic Sources
===============

Displays a breakdown of incoming traffic by channel, browser, device type, or
country. Entries outside the top results are aggregated into an "Others" row.
Donut chart variants support hover interaction: hovering over a segment pulls
it out slightly and shows a tooltip with the session count, percentage, and
comparison to the previous period.

On **TYPO3 v14+**, four separate widgets are registered — one per section:

..  list-table::
    :header-rows: 1
    :widths: 40 30 30

    *   -   Widget
        -   Section
        -   Default chart type
    *   -   Traffic Sources: Channel
        -   Channel
        -   List
    *   -   Traffic Sources: Devices
        -   Devices
        -   Donut
    *   -   Traffic Sources: Browser
        -   Browser
        -   Donut
    *   -   Traffic Sources: Countries
        -   Countries
        -   List

..  tabs::

    ..  tab:: Channel

        ..  tabs::

            ..  tab:: Light

                ..  figure:: ../Images/widget-traffic-sources-channel-light.png
                    :alt: Channel widget
                    :zoom: gallery
                    :class: with-border with-shadow
                    :gallery: traffic-sources
                    :align: center

            ..  tab:: Dark

                ..  figure:: ../Images/widget-traffic-sources-channel-dark.png
                    :alt: Channel (dark)
                    :zoom: gallery
                    :class: with-border with-shadow
                    :gallery: traffic-sources
                    :align: center

            ..  tab:: With tooltip

                ..  figure:: ../Images/widget-traffic-sources-channel-light-tooltip.png
                    :alt: Channel (with tooltip)
                    :zoom: gallery
                    :class: with-border with-shadow
                    :gallery: traffic-sources
                    :align: center

    ..  tab:: Browser

        ..  tabs::

            ..  tab:: Light

                ..  figure:: ../Images/widget-traffic-sources-browser-light.png
                    :alt: Browser widget
                    :zoom: gallery
                    :class: with-border with-shadow
                    :gallery: traffic-sources
                    :align: center

            ..  tab:: Dark

                ..  figure:: ../Images/widget-traffic-sources-browser-dark.png
                    :alt: Browser (dark)
                    :zoom: gallery
                    :class: with-border with-shadow
                    :gallery: traffic-sources
                    :align: center

            ..  tab:: With tooltip

                ..  figure:: ../Images/widget-traffic-sources-browser-light-tooltip.png
                    :alt: Browser (with tooltip)
                    :zoom: gallery
                    :class: with-border with-shadow
                    :gallery: traffic-sources
                    :align: center

    ..  tab:: Devices

        ..  tabs::

            ..  tab:: Light

                ..  figure:: ../Images/widget-traffic-sources-devices-light.png
                    :alt: Devices widget
                    :zoom: gallery
                    :class: with-border with-shadow
                    :gallery: traffic-sources
                    :align: center

            ..  tab:: Dark

                ..  figure:: ../Images/widget-traffic-sources-devices-dark.png
                    :alt: Devices (dark)
                    :zoom: gallery
                    :class: with-border with-shadow
                    :gallery: traffic-sources
                    :align: center

            ..  tab:: With tooltip

                ..  figure:: ../Images/widget-traffic-sources-devices-light-tooltip.png
                    :alt: Devices (with tooltip)
                    :zoom: gallery
                    :class: with-border with-shadow
                    :gallery: traffic-sources
                    :align: center

    ..  tab:: Countries

        ..  tabs::

            ..  tab:: Light

                ..  figure:: ../Images/widget-traffic-sources-countries-light.png
                    :alt: Countries widget
                    :zoom: gallery
                    :class: with-border with-shadow
                    :gallery: traffic-sources
                    :align: center

            ..  tab:: Dark

                ..  figure:: ../Images/widget-traffic-sources-countries-dark.png
                    :alt: Countries (dark)
                    :zoom: gallery
                    :class: with-border with-shadow
                    :gallery: traffic-sources
                    :align: center

            ..  tab:: With tooltip

                ..  figure:: ../Images/widget-traffic-sources-countries-light-tooltip.png
                    :alt: Countries (with tooltip)
                    :zoom: gallery
                    :class: with-border with-shadow
                    :gallery: traffic-sources
                    :align: center

Widget settings (v14+):

..  list-table::
    :header-rows: 1
    :widths: 25 15 20 40

    *   -   Setting
        -   Type
        -   Default
        -   Description
    *   -   **Site**
        -   string
        -   first registered site
        -   Site to display data for.
    *   -   **Period**
        -   int
        -   ``dashboardDefaultPeriod``
        -   Time window in days.
    *   -   **Chart type**
        -   enum
        -   *(per widget)*
        -   Display as ``list`` (progress bars) or ``donut``
            (SVG donut chart, top 5 + aggregated "Other").

On **TYPO3 v13**, all four sections are shown inside a single widget, stacked
vertically. Site and period are selected via inline dropdowns.


..  _dashboard-presets:

Dashboard presets
=================

The extension ships a ready-made dashboard preset called **Analytics Overview**.
It can be selected when creating a new dashboard via the
:guilabel:`Add dashboard` wizard and pre-populates the dashboard with all
available analytics widgets:

*   Traffic Graph
*   Site Performance
*   Top Pages
*   Traffic Sources (v13: combined widget; v14+: Channel, Devices, Browser,
    Countries)

The preset is a convenience starting point — widgets can be removed, reordered,
or supplemented with other TYPO3 dashboard widgets after creation.
