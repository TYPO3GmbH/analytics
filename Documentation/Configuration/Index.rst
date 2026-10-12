..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

The following settings can be configured in the TYPO3 Extension Manager, under
:guilabel:`System > Settings > Extension Configuration`.

Alternatively, you can set them in the configuration overrides file
:file:`config/system/additional.php`:

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['settingName'] = 'value';


..  confval-menu::
    :display: table
    :type:
    :default:

    ..  confval:: dashboardPeriods
        :type: string
        :default: `7,14,30`

        A comma-separated list of time periods (in days) for
        dashboard widget dropdowns and the Page Performance Bar. They
        must be positive integers.

    ..  confval:: dashboardDefaultPeriod
        :type: int
        :default: `7`

        A time period (in days) that is pre-selected in dropdowns for dashboard
        widgets and the Page Performance Bar. It must be one of the values defined
        in
        :confval:`dashboardPeriods` (above).

    ..  confval:: pageAnalyticsCacheTtl
        :type: int
        :default: `3600`

        Lifetime (in seconds) for analytics data caches (page metrics,
        top pages, site performance, traffic graph, traffic sources, and plans).
        Increase this value to reduce API calls in high-traffic backend
        installations.

    ..  confval:: demoData
        :type: bool
        :default: `0`

        If enabled, replaces analytics API calls with static demo data.
        Will only take effect if the application context is set to
        :php:`Development`. Use it to explore the analytics widgets and
        module without needing an analytics account.


Dashboard settings
==================

**Example — setting time periods:**

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['dashboardPeriods'] = '7,30,90';
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['dashboardDefaultPeriod'] = 30;


Cache settings
==============

**Example — setting a long cache lifetime:**

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['pageAnalyticsCacheTtl'] = 7200;


Development settings
====================

**Example — enabling demo data:**

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['demoData'] = true;
