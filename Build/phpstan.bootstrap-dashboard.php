<?php

declare(strict_types=1);

// Register autoloader for typo3/cms-dashboard which is only installed in .Build/dummy-typo3
$dashboardClassesDir = __DIR__ . '/../.Build/dummy-typo3/vendor/typo3/cms-dashboard/Classes/';
if (is_dir($dashboardClassesDir)) {
    spl_autoload_register(static function (string $class) use ($dashboardClassesDir): void {
        $prefix = 'TYPO3\\CMS\\Dashboard\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = $dashboardClassesDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

// Declare TYPO3 v14-only dashboard types so PHPStan can analyse TopPagesWidgetV14
// against a v13 cms-dashboard installation. Guards prevent redeclaration on v14.
require_once __DIR__ . '/phpstan.bootstrap-v14stubs.php';
