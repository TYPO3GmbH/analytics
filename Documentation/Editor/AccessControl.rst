..  include:: /Includes.rst.txt

..  _access-control:

==============
Access control
==============

The :guilabel:`Sites > Analytics` module is accessible to all backend users but
some actions are restricted to **backend administrators** and users
with **Analytics Manager** access:

-   Registering a new site with the Analytics API
-   Subscribing to or managing a plan
-   Manually refreshing registration status

Granting the Analytics Manager option
======================================

Open :guilabel:`System > Backend Users > Backend Groups`, edit the group that
you want to give Analytics Manager access to, and switch to the :guilabel:`Custom Options` tab.
Enable :guilabel:`Analytics > Analytics Manager`.

..  figure:: /Images/be-group-option.png
    :alt: The Analytics Manager option at the bottom of a backend user group "Module Permissions" page
    :zoom: lightbox

    Enable the Analytics Manager option at the bottom of your backend user group "Module Permissions" page


The TCA value for programmatic assignment is ``tx_analytics:manager``.
