..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

Install via Composer
====================

..  code-block:: bash

    composer require t3g/analytics

Activate the extension in the TYPO3 Extension Manager or via CLI:

..  code-block:: bash

    vendor/bin/typo3 extension:setup analytics

Install via TER (Classic mode)
===============================

In a non-Composer TYPO3 installation, search for **analytics** in
:guilabel:`Admin Tools > Extensions` (TYPO3 v13) or :guilabel:`System > Extensions` (TYPO3 v14) and install it from there or download it
from the `TYPO3 Extension Repository
<https://extensions.typo3.org/extension/analytics>`__. Make sure the
**Dashboard** system extension is active before installing it, as the extension
depends on it.

Deployment note
===============

The extension writes data for each site: credentials, tracking code and API
key. These values are sensitive. Do not commit them to the repository.

Set :confval:`siteDataPath` to write the data into a separate file for each
site. We recommend :file:`config/analytics`. The extension then writes the
data into :file:`config/analytics/<identifier>.yaml`.

With :confval:`siteDataPath`, the extension reads the data only from this
file. The site settings do not contain the data. Thus :guilabel:`Site Management > Settings` shows empty
values for the extension.

The extension writes the values as a tree below
``plugin.tx_analytics.settings``, the same as in :file:`settings.yaml`:

..  code-block:: yaml
    :caption: config/analytics/<identifier>.yaml

    plugin:
      tx_analytics:
        settings:
          websiteId: '...'
          instanceId: '...'
          instanceSecret: '...'

Keep the directory :file:`config/analytics` in a shared folder. Do not commit
the directory.

*   Share the directory, not the single files. The extension replaces a file
    on each write. This removes a symbolic link to a single file.
*   In classic mode (no Composer), the project root is the web root. Deny web
    access to the directory. The same applies to :file:`typo3conf/sites/`.

Move an existing installation to a separate file
------------------------------------------------

Set :confval:`siteDataPath` to :file:`config/analytics`. You do not have to
move the data.

Until the file of a site exists, the extension reads the data from
:file:`settings.yaml`. The extension creates the file when the data changes,
for example when the status or the tracking code changes. This write copies
the values of the extension from :file:`settings.yaml` into the file. After
this write, the extension ignores these values in :file:`settings.yaml`.

Run the upgrade wizard (see :ref:`installation-update-namespace`) after you
set :confval:`siteDataPath`. The wizard creates the file at once and removes
the values of the extension from :file:`settings.yaml`.


..  _installation-update-namespace:

Update from version 1.1 or earlier
==================================

Version 1.1 and earlier wrote the values on the top level of
:file:`settings.yaml`, for example ``websiteId``. The extension now writes the
values below ``plugin.tx_analytics.settings``.

Until you run the upgrade wizard, the extension also reads the values on the
top level. Thus the tracking and the backend module continue to work. The
:guilabel:`Analytics` module shows a reminder for each site with values on the
top level.

Run the upgrade wizard
:guilabel:`Analytics: Move the site data to plugin.tx_analytics.settings`:

..  code-block:: bash

    vendor/bin/typo3 upgrade:run analytics_siteDataNamespaceMigration

The wizard does these steps for each site:

*   It moves the values into the tree below ``plugin.tx_analytics.settings``.
*   With :confval:`siteDataPath`, it moves the values into the separate file
    and removes them from :file:`settings.yaml`. This also applies to the
    values below ``plugin.tx_analytics.settings``. If the file already
    exists, the wizard keeps the file and only removes the values from
    :file:`settings.yaml`.
*   It keeps a value that already exists below
    ``plugin.tx_analytics.settings``.
*   It keeps placeholders and the settings of other extensions.

Then run the wizard
:guilabel:`Analytics: Migrate instance secret encryption (v13 → v14)`, if it
is necessary.
