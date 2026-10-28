..  include:: /Includes.rst.txt

..  _access-control:

==============
Access control
==============

Backend users with access to the :guilabel:`Sites > Analytics` module can open
the module. However, some actions are restricted to **backend administrators**
and users with **Analytics Manager** access:

-   Registering a new site with the Analytics API
-   Subscribing to or managing a plan
-   Manually refreshing registration status

Granting the Analytics Manager option
======================================

Open :guilabel:`System > Backend Users > Backend user groups` (TYPO3 v13) or
:guilabel:`Administration > Users > Backend user groups` (TYPO3 v14).
Edit the group and open the :guilabel:`Custom Options` tab.
Enable :guilabel:`Analytics > Analytics Manager`.

..  figure:: /Images/be-group-option.png
    :alt: The Analytics Manager option at the bottom of a backend user group "Module Permissions" page
    :zoom: lightbox

    Enable the Analytics Manager option at the bottom of your backend user group "Module Permissions" page


The TCA value for programmatic assignment is ``tx_analytics:manager``.

Access to the analytics data
============================

The backend shows analytics data in the dashboard widgets, in the page
performance bar and in the plan management. Each part requires access to a
module:

..  list-table::
    :header-rows: 1
    :widths: 35 65

    *   -   Part
        -   Required access
    *   -   Dashboard widgets
        -   The :guilabel:`Dashboard` module and the widget in the field
            :guilabel:`Allowed dashboard widgets` of the backend group
    *   -   Page performance bar
        -   The page module: :guilabel:`Web → Page` in TYPO3 13,
            :guilabel:`Content → Layout` in TYPO3 14
    *   -   Plan management
        -   The :guilabel:`Sites → Analytics` module

The user also requires access to the root page of the site.

TYPO3 14 replaces the widget :guilabel:`Analytics: Traffic Sources` with four
widgets:

-   :guilabel:`Traffic Sources: Channel`
-   :guilabel:`Traffic Sources: Devices`
-   :guilabel:`Traffic Sources: Browser`
-   :guilabel:`Traffic Sources: Countries`

Allow each of these widgets in the backend group. A permission for the widget
:guilabel:`Analytics: Traffic Sources` from TYPO3 13 has no effect in TYPO3 14.
