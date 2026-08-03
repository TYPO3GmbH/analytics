..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

Install via Composer
====================

..  code-block:: bash

    composer require t3g/analytics

Activate the extension in the TYPO3 Extension Manager or via the CLI:

..  code-block:: bash

    vendor/bin/typo3 extension:setup analytics

Install via TER (Classic mode)
===============================

In a non-Composer TYPO3 installation, search for **analytics** in
:guilabel:`Admin Tools → Extensions` and install it from there, or download it
directly from the `TYPO3 Extension Repository
<https://extensions.typo3.org/extension/analytics>`__. Make sure the
**Dashboard** system extension is active before installing, as the extension
depends on it.

Deployment note
===============

Site-specific data written by the extension (credentials, tracking code, API
keys) is stored in each site's :file:`config/sites/<identifier>/settings.yaml`.
This file is separate from the structural :file:`config.yaml` and contains
sensitive values that must not be committed to the repository. In a
deployment setup, :file:`settings.yaml` should be kept in a shared folder
outside the release directory and symlinked or copied on each deploy.
