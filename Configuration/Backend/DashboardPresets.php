<?php

declare(strict_types=1);

return [
    'analyticsOverview' => [
        'title' => 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:dashboard.analyticsOverview.title',
        'description' => 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:dashboard.analyticsOverview.description',
        'iconIdentifier' => 'analytics-site-performance-widget-icon',
        'defaultWidgets' => [
            'analyticsTrafficGraph',
            'analyticsSitePerformance',
            'analyticsTopPages',
            'analyticsTrafficSources',
            'analyticsTrafficSourcesChannel',
            'analyticsTrafficSourcesDevices',
            'analyticsTrafficSourcesBrowser',
            'analyticsTrafficSourcesCountries',
        ],
        'showInWizard' => true,
    ],
];
