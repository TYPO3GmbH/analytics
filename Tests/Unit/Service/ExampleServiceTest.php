<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use T3G\Analytics\Service\ExampleService;

final class ExampleServiceTest extends TestCase
{
    public function testGetMessage(): void
    {
        $service = new ExampleService();

        self::assertSame(
            'TYPO3 v13 läuft erfolgreich mit PHP 8.4 und der Extension analytics.',
            $service->getMessage()
        );
    }

    public function testGetEnvironmentReturnsConfiguredValue(): void
    {
        $_SERVER['APP_ENV'] = 'test';

        $service = new ExampleService();

        self::assertSame('test', $service->getEnvironment());

        unset($_SERVER['APP_ENV']);
    }
}
