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

    public function testGetApiBaseUrlStripsCredentials(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl'] = 'https://user:secret@custom.example.com/api';

        self::assertSame('https://custom.example.com/api', Analytics::getApiBaseUrl());
    }

    public function testGetApiRequestOptionsReturnsVerifyFalseByDefault(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl']);

        $options = Analytics::getApiRequestOptions();

        self::assertFalse($options['verify']);
        self::assertArrayNotHasKey('auth', $options);
    }

    public function testGetApiRequestOptionsNeverIncludesAuth(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl'] = 'https://user:secret@custom.example.com/api';

        $options = Analytics::getApiRequestOptions();

        self::assertArrayNotHasKey('auth', $options);
        self::assertFalse($options['verify']);
    }

    public function testGetApiAuthOptionsReturnsAuthFromUrl(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl'] = 'https://user:secret@custom.example.com/api';

        $options = Analytics::getApiAuthOptions();

        self::assertSame(['user', 'secret'], $options['auth']);
    }

    public function testGetApiAuthOptionsOmitsAuthWhenNoCredentialsInUrl(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl'] = 'https://custom.example.com/api';

        self::assertSame([], Analytics::getApiAuthOptions());
    }

    public function testGetApiAuthOptionsReturnsAuthFromDefaultUrl(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl']);

        $options = Analytics::getApiAuthOptions();

        self::assertArrayHasKey('auth', $options);
        self::assertIsArray($options['auth']);
        self::assertCount(2, $options['auth']);
    }
}
