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

Site-specific data written by the extension (such as credentials, tracking code and API
keys) are stored in the site's :file:`config/sites/<identifier>/settings.yaml` file.
This file is separate from :file:`config.yaml` and contains sensitive values.
You must not commit these values to a repository. In production, keep
:file:`settings.yaml` in a shared folder outside the release directory.
Create a symlink to the file or copy it on each deploy.
