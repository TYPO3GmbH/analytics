<?php

defined('TYPO3') || die();

call_user_func(static function (): void {
    // Backend-Modul wird über Configuration/Backend/Modules.php registriert.

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_analytics_status'] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'options' => ['defaultLifetime' => 86400],
    ];
});
