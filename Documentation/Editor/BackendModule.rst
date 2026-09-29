..  include:: /Includes.rst.txt

..  _backend-module:

==============
Backend module
==============

The **Sites → TYPO3 Analytics** module provides an overview of all configured
TYPO3 sites and their analytics status. Sites are grouped into **Active sites**
and **Inactive sites**.

..  figure:: /Images/backend-module-inactive.png
    :alt: The analytics backend module with a site card showing main as an inactive site showing status "Not registered"
    :zoom: lightbox

    An site card with an inactive site in the analytics backend module showing status "Not registered"

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

**Active sites** additionally show:

-   A :guilabel:`Dashboard` button that opens the analytics dashboard as an
    embedded iframe inside the TYPO3 backend
-   A :guilabel:`Manage Plan` link for upgrading or changing the subscription
-   A :guilabel:`Refresh status` button to force a fresh status lookup from
    the API (bypasses the 24-hour cache)


..  figure:: /Images/backend-module-active.png
    :alt: The analytics backend module with a site card showing main as an active site showing buttons Dashboard, Manage Plan and Refresh Status
    :zoom: lightbox

    A site card with an active site in the analytics backend module with buttons Dashboard, Manage Plan and Refresh Status

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
