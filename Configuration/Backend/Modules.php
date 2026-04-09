<?php

declare(strict_types=1);

use T3G\Analytics\Controller\BackendModuleController;

return [
    'site_analytics' => [
        'parent' => 'site',
        'position' => ['after' => 'site_settings'],
        'access' => 'user,group',
        'workspaces' => 'live',
        'path' => '/module/site/analytics',
        'labels' => 'LLL:EXT:analytics/Resources/Private/Language/locallang_mod.xlf',
        'iconIdentifier' => 'analytics-module',
        'routes' => [
            '_default' => [
                'target' => BackendModuleController::class . '::indexAction',
            ],
            'register' => [
                'target' => BackendModuleController::class . '::registerAction',
                'methods' => ['POST'],
            ],
            'status' => [
                'target' => BackendModuleController::class . '::statusAction',
                'methods' => ['POST'],
            ],
            'dashboard' => [
                'target' => BackendModuleController::class . '::dashboardAction',
                'methods' => ['GET'],
            ],
            'checkout' => [
                'target' => BackendModuleController::class . '::checkoutAction',
                'methods' => ['GET'],
            ],
        ],
    ],
];
