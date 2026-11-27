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
Remove them from :file:`settings.yaml`:

*   ``websiteId``
*   ``instanceId``
*   ``instanceSecret``
*   ``trackingCode``
*   ``status``
*   ``apiKeyId``
*   ``apiKey``
*   ``externalWebsiteId``
