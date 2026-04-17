<?php

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
