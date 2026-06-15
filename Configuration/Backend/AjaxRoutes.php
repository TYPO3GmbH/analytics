<?php

declare(strict_types=1);

return [
    'analytics_top_pages_content' => [
        'path' => '/analytics/top-pages/content',
        'target' => \T3G\Analytics\Controller\TopPagesAjaxController::class . '::handle',
        'access' => 'user',
    ],
];
