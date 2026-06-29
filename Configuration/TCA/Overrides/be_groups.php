<?php

declare(strict_types=1);

$GLOBALS['TCA']['be_groups']['columns']['custom_options']['config']['items'][] = [
    'label' => 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:be_groups.custom_options.analytics',
    'value' => '--div--',
];
$GLOBALS['TCA']['be_groups']['columns']['custom_options']['config']['items'][] = [
    'label' => 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:be_groups.custom_options.analytics.manager',
    'value' => 'tx_analytics:manager',
];
