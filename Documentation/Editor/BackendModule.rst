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

The **Sites → TYPO3 Analytics** module shows all configured TYPO3 sites and
their analytics status. The module puts the sites into two groups:

-   **Active sites**
-   **Inactive sites**

Click the title of a group to collapse or expand the group. The group
**Inactive sites** is collapsed by default. The module keeps the collapsed
state for each backend user.

Plans
=====

The top of the module shows the available subscription plans with prices and
features. Use the toggle to switch between monthly and yearly billing. The
module gets the plans and prices live from the TYPO3 Analytics API.

Click :guilabel:`Our Plans` or :guilabel:`Hide price info` to collapse the
plans. The module keeps the collapsed state for each backend user.


Site list
=========

Each group shows its sites in a table with these columns:

-   **Site**: the site name and the site identifier
-   **Domain**: the base URL of the site
-   **Status**: ``active``, ``pending``, ``inactive`` or ``cancelled``
-   **Credits**: the used and the available credits, if the plan has a limit

Click the arrow in front of the site name to show the details of the site:

-   Site title
-   Website ID (once registered)
-   Package
-   :guilabel:`Valid until`
-   Reset date of the credits
-   Date and time of the last status update

The module shows dates in the language of the backend user.

When the credits of a site are low or used up, the row has a warning color.
The details of this site are open by default and show a message with a link
to :guilabel:`Manage Plan`.

..  tabs::

    ..  tab:: Active site Light

        ..  figure:: /Images/active-site-card-light.png
            :alt: Site list with the details of an active site in light mode
            :zoom: gallery
            :gallery: site-card
            :align: center

    ..  tab:: Active site Dark

        ..  figure:: /Images/active-site-card-dark.png
            :alt: Site list with the details of an active site in dark mode
            :zoom: gallery
            :gallery: site-card
            :align: center

The row of an **active site** also shows these buttons:

-   :guilabel:`Dashboard`: opens the analytics dashboard in an iframe in the
    TYPO3 backend.
-   :guilabel:`Manage Plan`: opens the page to upgrade or change the
    subscription. A site without an active plan shows
    :guilabel:`Subscribe plan`.
-   :guilabel:`Refresh status`: gets a new status from the API. This button
    ignores the status cache of 24 hours.


..  note::
    The :guilabel:`Manage Plan` link and :guilabel:`Refresh status` button are
    only available to backend administrators and users with the
    **Analytics Manager** custom option. See :ref:`access-control`.


Registration
============

To register a site, do these steps:

#.  In the group **Inactive sites**, find the site.
#.  Click :guilabel:`Register`. The details of the site open and show the
    registration form.
#.  Enter an e-mail address.
#.  Click :guilabel:`Register`.

The module connects the site with the TYPO3 Analytics API. After the
confirmation, the extension adds the tracking script to all frontend pages of
the site.

..  TODO: Describe the choice between a new site and an active site, when the
    registration supports mode "attach".

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
