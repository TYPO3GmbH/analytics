<?php

declare(strict_types=1);

use T3G\Analytics\Middleware\TrackingCodeMiddleware;

return [
    'frontend' => [
        't3g/analytics/tracking-code' => [
            'target' => TrackingCodeMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site-resolver',
            ],
        ],
    ],
];
