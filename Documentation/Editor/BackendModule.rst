..  include:: /Includes.rst.txt

..  _backend-module:

==============
Backend module
==============

..  tabs::

    ..  tab:: Light

        ..  figure:: /Images/backend-module-active-light.png
            :alt: The analytics backend module with an active site card in light mode
            :zoom: gallery
            :gallery: backend-module
            :align: center

    ..  tab:: Dark

        ..  figure:: /Images/backend-module-active-dark.png
            :alt: The analytics backend module with an active site card in dark mode
            :zoom: gallery
            :gallery: backend-module
            :align: center

The **Sites → TYPO3 Analytics** module provides an overview of all configured
TYPO3 sites and their analytics status. Sites are grouped into **Active sites**
and **Inactive sites**.

Plans
=====

At the top of the module, available subscription plans are displayed with
pricing and feature comparison. The toggle switches between monthly and yearly
billing. Plans and pricing are fetched live from the TYPO3 Analytics API.


Site cards
==========

Each site is shown as a card with the following information:

-   Site title and identifier
-   Domain
-   Current status (``active``, ``pending``, ``inactive``, or ``cancelled``)
-   Website ID and API key (once registered)
-   Subscribed package and expiry date
-   Credit usage and reset date

..  tabs::

    ..  tab:: Active site card Light

        ..  figure:: /Images/active-site-card-light.png
            :alt: Active site card information in light mode
            :zoom: gallery
            :gallery: site-card
            :align: center

    ..  tab:: Active site card Dark

        ..  figure:: /Images/active-site-card-dark.png
            :alt: Active site card information in dark mode
            :zoom: gallery
            :gallery: site-card
            :align: center

**Active sites** additionally show:

-   A :guilabel:`Dashboard` button that opens the analytics dashboard as an
    embedded iframe inside the TYPO3 backend
-   A :guilabel:`Manage Plan` link for upgrading or changing the subscription
-   A :guilabel:`Refresh status` button to force a fresh status lookup from
    the API (bypasses the 24-hour cache)


..  note::
    The :guilabel:`Manage Plan` link and :guilabel:`Refresh status` button are
    only available to backend administrators and users with the
    **Analytics Manager** custom option. See :ref:`access-control`.


Registration
============

Sites that are not yet registered show a registration form. Enter an e-mail
address and click :guilabel:`Register` to connect the site with the TYPO3
Analytics API. Once registration is confirmed, the tracking script is
automatically injected into every frontend page of that site.

..  figure:: /Images/backend-module-registration.png
    :alt: The email address field and Register button in the analytics backend module
    :zoom: lightbox

    Enter an email address and click on the Register button

..  note::
    Registration is only available to backend administrators and users with the
    **Analytics Manager** custom option. See :ref:`access-control`.


Dashboard
=========

The :guilabel:`Dashboard` button opens the TYPO3 Analytics web dashboard as
an embedded iframe inside the TYPO3 backend. All analytics data for the
selected site is available here without leaving TYPO3.

..  figure:: /Images/analytics-web-dashboard.png
    :alt: The TYPO3 Analytics web dashboard
    :zoom: lightbox

    The TYPO3 Analytics web dashboard

Non-manager users are granted a read-only watcher link — they can view the
dashboard but cannot make changes to the analytics configuration.
