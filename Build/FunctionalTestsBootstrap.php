<?php

declare(strict_types=1);

/**
 * Bootstrap for TYPO3 functional tests using SQLite.
 *
 * No external database server is required. The testing framework creates a
 * temporary SQLite file for each test run and tears it down afterwards.
 */
(static function (): void {
    putenv('typo3DatabaseDriver=pdo_sqlite');

    $testbase = new \TYPO3\TestingFramework\Core\Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
})();
