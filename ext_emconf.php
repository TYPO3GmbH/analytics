<?php

// Required for TYPO3 v13 only.
// TYPO3 v14+ reads extension metadata from composer.json (extra.typo3/cms).
// See: https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/14.2/Deprecation-108345-Deprecation-of-ext-emconf-php.html

$EM_CONF['analytics'] = [
    'title' => 'Analytics',
    'description' => 'TYPO3 Analytics',
    'category' => 'module',
    'state' => 'alpha',
    'clearCacheOnLoad' => 1,
    'author' => 'Vendor',
    'author_email' => 'simon.schmidt@typo3.com',
    'author_company' => 'TYPO3 GmbH',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
    ],
];
