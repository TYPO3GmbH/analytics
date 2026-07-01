<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Configuration\ApiConfiguration;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ApiConfigurationTest extends UnitTestCase
{
    private function buildSubject(string $apiBaseUrl = '', string $verifySsl = '1', string $intpId = '', string $analyticsApiBaseUrl = ''): ApiConfiguration
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnMap([
            ['analytics', 'apiBaseUrl', $apiBaseUrl],
            ['analytics', 'verifySsl', $verifySsl],
            ['analytics', 'intpId', $intpId],
            ['analytics', 'analyticsApiBaseUrl', $analyticsApiBaseUrl],
        ]);
        return new ApiConfiguration($extensionConfiguration);
    }

    #[Test]
    public function getIntpIdReturnsDefaultWhenNotConfigured(): void
    {
        self::assertSame('28096317-d75a-43b7-af39-f0862b66afa3', $this->buildSubject()->getIntpId());
    }

    #[Test]
    public function getIntpIdReturnsConfiguredValue(): void
    {
        self::assertSame('custom-intp-id', $this->buildSubject(intpId: 'custom-intp-id')->getIntpId());
    }

    #[Test]
    public function getAnalyticsApiBaseUrlReturnsDefaultWhenNotConfigured(): void
    {
        self::assertSame('https://api.analytics.typo3.com/api', $this->buildSubject()->getAnalyticsApiBaseUrl());
    }

    #[Test]
    public function getAnalyticsApiBaseUrlReturnsConfiguredValue(): void
    {
        self::assertSame('https://custom.example.com/api', $this->buildSubject(analyticsApiBaseUrl: 'https://custom.example.com/api')->getAnalyticsApiBaseUrl());
    }

    #[Test]
    public function getAnalyticsApiBaseUrlStripsTrailingSlash(): void
    {
        self::assertSame('https://custom.example.com/api', $this->buildSubject(analyticsApiBaseUrl: 'https://custom.example.com/api/')->getAnalyticsApiBaseUrl());
    }

    #[Test]
    public function getBaseUrlUsesConfiguredUrl(): void
    {
        self::assertSame('https://custom.example.com/api', $this->buildSubject('https://custom.example.com/api')->getBaseUrl());
    }

    #[Test]
    public function getBaseUrlStripsTrailingSlash(): void
    {
        self::assertSame('https://custom.example.com/api', $this->buildSubject('https://custom.example.com/api/')->getBaseUrl());
    }

    #[Test]
    public function getBaseUrlPreservesPort(): void
    {
        self::assertSame('https://custom.example.com:8080/api', $this->buildSubject('https://custom.example.com:8080/api')->getBaseUrl());
    }

    #[Test]
    public function getRequestOptionsReturnsTrueByDefault(): void
    {
        self::assertSame(['verify' => true], $this->buildSubject()->getRequestOptions());
    }

    #[Test]
    public function getRequestOptionsReturnsFalseWhenVerifySslDisabled(): void
    {
        self::assertSame(['verify' => false], $this->buildSubject(verifySsl: '0')->getRequestOptions());
    }

    #[Test]
    public function getAuthOptionsReturnsEmptyArrayWhenNoUrlConfigured(): void
    {
        self::assertSame([], $this->buildSubject()->getAuthOptions());
    }

    #[Test]
    public function getAuthOptionsReturnsEmptyArrayWhenUrlHasNoCredentials(): void
    {
        self::assertSame([], $this->buildSubject('https://custom.example.com/api')->getAuthOptions());
    }

    #[Test]
    public function getAuthOptionsReturnsCredentialsWhenEmbeddedInUrl(): void
    {
        $subject = $this->buildSubject('https://user:secret@custom.example.com/api');
        self::assertSame(['auth' => ['user', 'secret']], $subject->getAuthOptions());
    }
}
