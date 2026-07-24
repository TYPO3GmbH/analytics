..  include:: /Includes.rst.txt

..  _access-control:

==============
Access control
==============

The **Sites → Analytics** module is accessible to all backend users. However,
the following actions are restricted to **backend administrators** and users
who hold the **Analytics Manager** custom option:

-   Registering a new site with the Analytics API
-   Subscribing to or managing a plan
-   Manually refreshing the registration status

Granting the Analytics Manager option
======================================

Open :guilabel:`System → Backend Users → Backend Groups`, edit the group that
should have manager access, and switch to the :guilabel:`Custom Options` tab.
Enable :guilabel:`Analytics → Analytics Manager`.

The TCA value for programmatic assignment is ``tx_analytics:manager``.
