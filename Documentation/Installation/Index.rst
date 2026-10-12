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
:guilabel:`Admin Tools > Extensions` and install it from there or download it
from the `TYPO3 Extension Repository
<https://extensions.typo3.org/extension/analytics>`__. Make sure the
**Dashboard** system extension is active before installing it, as the extension
depends on it.

Deployment note
===============

Site-specific data written by the extension (such as credentials, tracking code and API
keys) are stored in the site's :file:`config/sites/<identifier>/settings.yaml` file.
This file is separate from the  :file:`config.yaml` (more
relevant to the structure of the site) and contains
sensitive values that should not be committed to a repository. Therefore, in
production, :file:`settings.yaml` should be kept in a shared folder
outside the release directory and symlinked or copied on each deploy.
