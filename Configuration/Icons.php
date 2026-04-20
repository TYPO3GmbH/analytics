<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

return [
    'analytics-module' => [
        'provider' => SvgIconProvider::class,
        'source' => (new Typo3Version())->getMajorVersion() >= 14
            ? 'EXT:analytics/Resources/Public/Icons/Extension.svg'
            : 'EXT:analytics/Resources/Public/Icons/Extension-v13.svg',
    ],
];
