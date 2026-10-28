<?php

declare(strict_types=1);

use T3G\Analytics\Middleware\TrackingCodeMiddleware;

return [
    'frontend' => [
        't3g/analytics/tracking-code' => [
            'target' => TrackingCodeMiddleware::class,
            // Run inside content-length-headers, so the Content-Length header
            // includes the tracking code.
            'after' => [
                'typo3/cms-frontend/site',
                'typo3/cms-frontend/content-length-headers',
            ],
        ],
    ],
];
