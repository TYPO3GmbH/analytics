<?php

defined('TYPO3') || die();

call_user_func(static function (): void {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_analytics_status'] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'options' => ['defaultLifetime' => \T3G\Analytics\Service\AnalyticsStatusService::STATUS_CACHE_LIFETIME],
    ];

    $pageAnalyticsCacheTtl = (int)($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['pageAnalyticsCacheTtl'] ?? 3600);
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_analytics_pages'] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'options' => ['defaultLifetime' => $pageAnalyticsCacheTtl > 0 ? $pageAnalyticsCacheTtl : 3600],
    ];

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_analytics_top_pages'] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'options' => ['defaultLifetime' => $pageAnalyticsCacheTtl > 0 ? $pageAnalyticsCacheTtl : 3600],
    ];

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_analytics_site_performance'] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'options' => ['defaultLifetime' => $pageAnalyticsCacheTtl > 0 ? $pageAnalyticsCacheTtl : 3600],
    ];

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_analytics_traffic_graph'] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'options' => ['defaultLifetime' => $pageAnalyticsCacheTtl > 0 ? $pageAnalyticsCacheTtl : 3600],
    ];

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_analytics_traffic_sources'] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'options' => ['defaultLifetime' => $pageAnalyticsCacheTtl > 0 ? $pageAnalyticsCacheTtl : 3600],
    ];

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_analytics_plans'] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'options' => ['defaultLifetime' => $pageAnalyticsCacheTtl > 0 ? $pageAnalyticsCacheTtl : 3600],
    ];
});
