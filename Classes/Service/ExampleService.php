<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

final class ExampleService
{
    public function getMessage(): string
    {
        return 'TYPO3 v13 läuft erfolgreich mit PHP 8.4 und der Extension analytics.';
    }

    public function getEnvironment(): string
    {
        return $_SERVER['APP_ENV'] ?? 'dev';
    }
}
