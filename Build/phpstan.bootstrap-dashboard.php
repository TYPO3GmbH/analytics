<?php

declare(strict_types=1);

// Register autoloader for typo3/cms-dashboard when the local dummy install exists.
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

// v13 fallback stubs — declared when cms-dashboard is not available (e.g. CI).
require_once __DIR__ . '/phpstan.bootstrap-v13stubs.php';

// v14-only types — also declared as fallback stubs when not installed.
require_once __DIR__ . '/phpstan.bootstrap-v14stubs.php';
