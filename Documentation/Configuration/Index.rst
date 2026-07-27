..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

The following settings can be configured in the TYPO3 Extension Manager under
:guilabel:`Admin Tools → Settings → Extension Configuration → analytics`.

They can also be set programmatically in :file:`config/system/additional.php`:

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['settingName'] = 'value';


..  confval-menu::
    :display: table
    :type:
    :default:

    ..  confval:: dashboardPeriods
        :type: string
        :default: `7,14,30`

        Comma-separated list of period options (in days) available in all
        dashboard widget dropdowns and the Page Performance Bar. Must be
        positive integers.

    ..  confval:: dashboardDefaultPeriod
        :type: int
        :default: `7`

        Pre-selected period (in days) for all dashboard widgets and the Page
        Performance Bar. Must be one of the values defined in
        :confval:`dashboardPeriods`.

    ..  confval:: pageAnalyticsCacheTtl
        :type: int
        :default: `3600`

        Lifetime in seconds for all analytics data caches (page metrics,
        top pages, site performance, traffic graph, and traffic sources).
        Increase this value to reduce API calls on high-traffic backend
        installations.

    ..  confval:: demoData
        :type: bool
        :default: `0`

        When enabled, replaces all analytics API calls with static demo data.
        Only takes effect in the TYPO3 :php:`Development` application context.
        Use this to explore the widgets and module without a live analytics
        account.


Dashboard settings
==================

**Example — custom period options:**

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['dashboardPeriods'] = '7,30,90';
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['dashboardDefaultPeriod'] = 30;


Cache settings
==============

**Example — longer cache lifetime:**

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['pageAnalyticsCacheTtl'] = 7200;


Development settings
====================

**Example — enable demo data:**

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['demoData'] = true;
