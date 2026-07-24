..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

Install via Composer
====================

..  code-block:: bash

    composer require t3g/analytics

Activate the extension
=======================

Activate the extension in the TYPO3 Extension Manager or via the CLI:

..  code-block:: bash

    vendor/bin/typo3 extension:setup analytics

Include the Site Set
====================

The extension ships a Site Set that must be added to every site that should
be tracked. Open the site configuration in :guilabel:`Site Management →
Sites`, switch to the :guilabel:`Sets` tab, and add **TYPO3 Analytics**.

This registers the required settings (website ID, tracking code, etc.) for
the site and enables frontend tracking code injection once the site is active.
