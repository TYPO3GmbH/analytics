<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit;

use PHPUnit\Framework\TestCase;
use T3G\Analytics\Analytics;

final class AnalyticsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl']);
    }

    public function testGetApiBaseUrlReturnsDefault(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl']);

        self::assertSame(
            'https://site-analytics-middleware.ddev.site/api',
            Analytics::getApiBaseUrl()
        );
    }

    public function testGetApiBaseUrlReturnsConfiguredValue(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl'] = 'https://custom.example.com/api';

        self::assertSame('https://custom.example.com/api', Analytics::getApiBaseUrl());
    }

    public function testGetApiBaseUrlStripsTrailingSlash(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl'] = 'https://custom.example.com/api/';

        self::assertSame('https://custom.example.com/api', Analytics::getApiBaseUrl());
    }
}
