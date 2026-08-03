<?php

/**
 * Required for TYPO3 v13 only.
 * TYPO3 v14+ reads extension metadata from composer.json (extra.typo3/cms).
 * @see https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/14.2/Deprecation-108345-Deprecation-of-ext-emconf-php.html
 */

$EM_CONF['analytics'] = [
    'title' => 'Analytics',
    'description' => 'Integrates analytics dashboards and widgets into the TYPO3 backend — including traffic graphs, site performance, top pages, traffic sources, and a page performance bar.',
    'category' => 'module',
    'state' => 'stable',
    'clearCacheOnLoad' => 1,
    'author' => 'TYPO3 GmbH',
    'author_email' => 'simon.schmidt@typo3.com',
    'author_company' => 'TYPO3 GmbH',
    'version' => '1.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
    ],
];
