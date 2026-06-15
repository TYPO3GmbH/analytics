<?php

declare(strict_types=1);

return [
    'analytics_top_pages_content' => [
        'path' => '/analytics/top-pages/content',
        'target' => \T3G\Analytics\Controller\TopPagesAjaxController::class . '::handle',
        'access' => 'user',
    ],
    'analytics_site_performance_content' => [
        'path' => '/analytics/site-performance/content',
        'target' => \T3G\Analytics\Controller\SitePerformanceAjaxController::class . '::handle',
        'access' => 'user',
    ],
];
