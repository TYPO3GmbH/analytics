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

The extension writes data for each site: credentials, tracking code and API
key. These values are sensitive. Do not commit them to the repository.

By default, the extension writes the data into
:file:`config/sites/<identifier>/settings.yaml`. In a deployment setup, keep
this file in a shared folder outside the release directory.

If other extensions also use :file:`settings.yaml`, the file must stay in the
repository. In this case, set :confval:`siteDataPath`. We recommend
:file:`config/analytics`. The extension then writes the data into
:file:`config/analytics/<identifier>.yaml`. Keep only this directory in a
shared folder and commit :file:`settings.yaml` as usual.

*   Share the directory, not the single files. The extension replaces a file
    on each write. This removes a symbolic link to a single file.
*   Set :confval:`siteDataPath` in the extension configuration. Then the
    backend, the frontend and the CLI use the same location. If you use the
    environment variable instead, set it for the web server and for the CLI.
*   In classic mode (no Composer), the project root is the web root. Use an
    absolute path outside the web root, or deny web access to the directory.
    The same applies to :file:`typo3conf/sites/`.

An existing installation continues to work after you set
:confval:`siteDataPath`. If a value is not in the separate file, the extension
reads it from :file:`settings.yaml`. The first write copies all values into the
separate file. After this, you can remove the values of the extension from
:file:`settings.yaml`.

If you remove :confval:`siteDataPath` again, the extension reads
:file:`settings.yaml` again. Old values in :file:`settings.yaml` then apply.
Copy the values from the separate file back into :file:`settings.yaml` first.
