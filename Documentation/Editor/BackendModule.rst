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

The :guilabel:`Sites > TYPO3 Analytics` module provides an overview of all the sites
configured in TYPO3 with their analytics status. Sites are grouped into
**Active sites** and **Inactive sites**.

Plans
=====

Subscription plans are displayed at the top of the module with
a comparison of pricing and features. You can toggle between monthly and yearly
billing. Plans and pricing are fetched live from the TYPO3 Analytics API.


Site cards
==========

Each site is displayed as a card with the following information:

-   Site title and identifier
-   Domain
-   Current status (``active``, ``pending``, ``inactive``, or ``cancelled``)
-   Website ID and API key (once registered)
-   Package and expiry date
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

**Active sites** also have:

-   A :guilabel:`Dashboard` button that opens the analytics dashboard as an
    embedded iframe inside the TYPO3 backend
-   A :guilabel:`Manage Plan` link for upgrading or changing the subscription
-   A :guilabel:`Refresh status` button to force a status lookup from
    the API (it bypasses the 24-hour cache)


..  note::
    The :guilabel:`Manage Plan` link and :guilabel:`Refresh status` button are
    only available to backend administrators and users with
    **Analytics Manager** access. See :ref:`access-control`.


Registration
============

Unregistered sites display a registration form.
To register, enter an e-mail address and click :guilabel:`Register` to connect
the site to the TYPO3 Analytics API. Once registration is confirmed, a
tracking script is injected into every frontend page of the site.

..  figure:: /Images/backend-module-registration.png
    :alt: The email address field and Register button in the analytics backend module
    :zoom: lightbox

    Enter an email address and click on the Register button

..  note::
    Registration is only available to backend administrators and users with
    **Analytics Manager** access. See :ref:`access-control`.


TYPO3 Analytics Web Dashboard
=============================

The :guilabel:`Dashboard` button opens the TYPO3 Analytics web dashboard. It is
an embedded iframe inside the TYPO3 backend. Here you can see all the analytics data for a selected
site without having to leave TYPO3.

..  figure:: /Images/analytics-web-dashboard.png
    :alt: The TYPO3 Analytics web dashboard
    :zoom: lightbox

    The TYPO3 Analytics web dashboard

Users without Analytics Manager access are granted a read-only watcher link. They can view the
dashboard but cannot make changes to the analytics configuration.
