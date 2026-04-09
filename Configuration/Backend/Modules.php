<?php

declare(strict_types=1);

use T3G\Analytics\Controller\BackendModuleController;

return [
    'web_analytics' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user,group',
        'workspaces' => 'live',
        'path' => '/module/web/analytics',
        'labels' => 'LLL:EXT:analytics/Resources/Private/Language/locallang_mod.xlf',
        'iconIdentifier' => 'analytics-module',
        'routes' => [
            '_default' => [
                'target' => BackendModuleController::class . '::indexAction',
            ],
        ],
    ],
];
