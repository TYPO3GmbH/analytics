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

The extension writes the values as a tree below
``plugin.tx_analytics.settings``:

..  code-block:: yaml
    :caption: config/sites/<identifier>/settings.yaml

    plugin:
      tx_analytics:
        settings:
          websiteId: '...'
          instanceId: '...'
          instanceSecret: '...'

If other extensions also use :file:`settings.yaml`, the file must stay in the
repository. In this case, set :confval:`siteDataPath`. We recommend
:file:`config/analytics`. The extension then writes the data into
:file:`config/analytics/<identifier>.yaml`.

The extension always reads the data from the site settings. Thus you must
import the file in the :file:`settings.yaml` of each site:

..  code-block:: yaml
    :caption: config/sites/<identifier>/settings.yaml

    imports:
      - { resource: '../../analytics/<identifier>.yaml', glob: true }

    # Other site settings

Commit :file:`settings.yaml` with the import. Keep only the directory
:file:`config/analytics` in a shared folder.

You must set ``glob: true``. The file does not exist before the first
registration. With ``glob: true``, TYPO3 ignores a missing file. See
:ref:`t3coreapi:yamlfileloader` for the glob option of the imports. Without
``glob: true``, TYPO3 14.3.11 and later do not load a site with a missing
import. TYPO3 also does not load the site when the file contains invalid YAML.

*   Share the directory, not the single files. The extension replaces a file
    on each write. This removes a symbolic link to a single file.
*   The directory must be in the project root. TYPO3 does not import files
    from outside the project root. A symbolic link to a shared folder is
    possible.
*   Set :confval:`siteDataPath` in the extension configuration. Then the
    backend and the CLI use the same location. If you use the environment
    variable instead, set it for the web server and for the CLI.
*   In classic mode (no Composer), the project root is the web root. Deny web
    access to the directory. The same applies to :file:`typo3conf/sites/`.

The values in :file:`settings.yaml` have priority over the imported file.
Thus :file:`settings.yaml` must not contain values below
``plugin.tx_analytics.settings``.

The extension checks the import and ``glob: true`` before the registration.
After each write, the extension makes sure that the site settings contain the
new values.

..  warning::
    Do not save the site settings in the backend module :guilabel:`Site
    Management > Settings`. The settings editor of TYPO3 writes all values,
    also the imported values, into :file:`settings.yaml` and removes the
    import. If this occurred, remove the values of the extension from
    :file:`settings.yaml` and add the import again.

Move an existing installation to a separate file
------------------------------------------------

#.  Copy the values below ``plugin.tx_analytics.settings`` from
    :file:`settings.yaml` into :file:`config/analytics/<identifier>.yaml`.
    Keep the tree.
#.  Remove the values below ``plugin.tx_analytics.settings`` from
    :file:`settings.yaml`.
#.  Add the import to :file:`settings.yaml`.
#.  Set :confval:`siteDataPath` to :file:`config/analytics`.
#.  Flush the TYPO3 caches.

If :file:`settings.yaml` contains the values in the old format (see
:ref:`installation-update-namespace`), do steps 3 and 4. Then run the upgrade
wizard. The wizard moves the values into the separate file.

If you remove :confval:`siteDataPath` again, the extension writes into
:file:`settings.yaml` again. The first write copies the imported values into
:file:`settings.yaml` and removes the import. Then do not commit
:file:`settings.yaml` any more.


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
    With :confval:`siteDataPath`, it moves the values into the separate file
    and removes them from :file:`settings.yaml`.
*   It keeps a value that already exists below
    ``plugin.tx_analytics.settings``.
*   It keeps imports, placeholders and the settings of other extensions.

Then run the wizard
:guilabel:`Analytics: Migrate instance secret encryption (v13 → v14)`, if it
is necessary.
