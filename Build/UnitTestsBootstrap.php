<?php

declare(strict_types=1);

/**
 * Bootstrap for TYPO3 unit tests.
 *
 * Sets up the minimal TYPO3 environment (class loader, environment, package manager)
 * required for unit tests that use GeneralUtility::makeInstance() or similar helpers.
 */
(static function (): void {
    $testbase = new \TYPO3\TestingFramework\Core\Testbase();

    if (!getenv('TYPO3_PATH_ROOT')) {
        putenv('TYPO3_PATH_ROOT=' . rtrim($testbase->getWebRoot(), '/'));
    }
    if (!getenv('TYPO3_PATH_WEB')) {
        putenv('TYPO3_PATH_WEB=' . rtrim($testbase->getWebRoot(), '/'));
    }

    $testbase->defineSitePath();

    $composerMode = defined('TYPO3_COMPOSER_MODE') && TYPO3_COMPOSER_MODE === true;

    \TYPO3\TestingFramework\Core\SystemEnvironmentBuilder::run(
        0,
        \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_CLI,
        $composerMode
    );

    $testbase->createDirectory(\TYPO3\CMS\Core\Core\Environment::getPublicPath() . '/typo3conf/ext');
    $testbase->createDirectory(\TYPO3\CMS\Core\Core\Environment::getPublicPath() . '/typo3temp/assets');
    $testbase->createDirectory(\TYPO3\CMS\Core\Core\Environment::getPublicPath() . '/typo3temp/var/tests');
    $testbase->createDirectory(\TYPO3\CMS\Core\Core\Environment::getPublicPath() . '/typo3temp/var/transient');

    $classLoader = require $testbase->getPackagesPath() . '/autoload.php';
    \TYPO3\CMS\Core\Core\Bootstrap::initializeClassLoader($classLoader);

    $configurationManager = new \TYPO3\CMS\Core\Configuration\ConfigurationManager();
    $GLOBALS['TYPO3_CONF_VARS'] = $configurationManager->getDefaultConfiguration();

    $cache = new \TYPO3\CMS\Core\Cache\Frontend\PhpFrontend(
        'core',
        new \TYPO3\CMS\Core\Cache\Backend\NullBackend('production', [])
    );
    $packageManager = \TYPO3\CMS\Core\Core\Bootstrap::createPackageManager(
        \TYPO3\CMS\Core\Package\UnitTestPackageManager::class,
        \TYPO3\CMS\Core\Core\Bootstrap::createPackageCache($cache)
    );

    \TYPO3\CMS\Core\Utility\GeneralUtility::setSingletonInstance(
        \TYPO3\CMS\Core\Package\PackageManager::class,
        $packageManager
    );
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::setPackageManager($packageManager);

    $testbase->dumpClassLoadingInformation();

    \TYPO3\CMS\Core\Utility\GeneralUtility::purgeInstances();
})();
