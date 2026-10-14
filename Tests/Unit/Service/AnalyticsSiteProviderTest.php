<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use T3G\Analytics\Service\AnalyticsSiteProvider;
use T3G\Analytics\Service\BackendPageAccessCheckerInterface;
use T3G\Analytics\Service\CipherServiceInterface;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AnalyticsSiteProviderTest extends UnitTestCase
{
    private SiteFinder&MockObject $siteFinder;
    private AnalyticsSiteProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteFinder = $this->createMock(SiteFinder::class);

        $cipherService = $this->createMock(CipherServiceInterface::class);
        $cipherService->method('decrypt')->willReturnCallback(static fn (string $value): string => 'decrypted-' . $value);

        $pageAccessChecker = $this->createMock(BackendPageAccessCheckerInterface::class);
        $pageAccessChecker->method('userCanAccessPage')->willReturn(true);

        $this->subject = new AnalyticsSiteProvider(
            $this->siteFinder,
            $cipherService,
            $pageAccessChecker,
            new NullLogger(),
        );
    }

    /** siteOptions */

    #[Test]
    public function siteOptionsListsSitesWithCredentials(): void
    {
        $this->siteFinder->method('getAllSites')->willReturn([
            $this->buildSite('main', ['externalWebsiteId' => 'ext-1', 'apiKey' => 'key'], 'Main'),
            $this->buildSite('unregistered', []),
        ]);

        self::assertSame(['main' => 'Main (main)'], $this->subject->siteOptions());
    }

    #[Test]
    public function siteOptionsListsSitesAttachedToAnActiveSite(): void
    {
        $this->siteFinder->method('getAllSites')->willReturn([
            $this->buildSite('main', ['externalWebsiteId' => 'ext-1', 'apiKey' => 'key'], 'Main'),
            $this->buildSite('second', ['externalWebsiteId' => 'ext-1', 'apiKey' => 'key', 'attachedTo' => 'main'], 'Second'),
        ]);

        self::assertSame(['main' => 'Main (main)', 'second' => 'Second (second)'], $this->subject->siteOptions());
    }

    /** resolveAnalyticsSite */

    #[Test]
    public function resolveAnalyticsSiteReturnsCredentialsAndHostFilterOfTheSite(): void
    {
        $site = $this->buildSite('main', ['externalWebsiteId' => 'ext-1', 'apiKey' => 'key']);
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);

        $result = $this->subject->resolveAnalyticsSite('main');

        self::assertNotNull($result);
        self::assertSame($site, $result['site']);
        self::assertSame('ext-1', $result['websiteId']);
        self::assertSame('decrypted-key', $result['apiKey']);
        self::assertSame(['://example.com'], $result['pageUrlFilter']);
    }

    #[Test]
    public function resolveAnalyticsSiteLimitsASiteAttachedToAnActiveSiteToItsOwnHost(): void
    {
        $this->siteFinder->method('getSiteByIdentifier')->willReturn(
            $this->buildSite('second', ['externalWebsiteId' => 'ext-1', 'apiKey' => 'key', 'attachedTo' => 'main'], base: 'https://second.example.com/')
        );

        $result = $this->subject->resolveAnalyticsSite('second');

        self::assertNotNull($result);
        self::assertSame('ext-1', $result['websiteId']);
        self::assertSame(['://second.example.com'], $result['pageUrlFilter']);
    }

    /**
     * @return \Generator<string, array{string, list<string>, list<string>}>
     */
    public static function pageUrlFilterProvider(): \Generator
    {
        yield 'language with a path on the same host' => ['https://example.com/', ['https://example.com/de/'], ['://example.com']];
        yield 'language on another host' => ['https://example.com/', ['https://example.ch/'], ['://example.ch', '://example.com']];
        yield 'site with a path' => ['https://example.com/shop/', ['https://example.com/shop/de/'], ['://example.com/shop']];
        yield 'host with a port' => ['https://example.com/', ['https://example.com:8080/'], ['://example.com', '://example.com:8080']];
        yield 'language without a host' => ['https://example.com/', ['/de/'], ['://example.com']];
    }

    /**
     * @param list<string> $languageBases
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('pageUrlFilterProvider')]
    public function resolveAnalyticsSiteBuildsTheHostFilterFromAllBases(string $base, array $languageBases, array $expected): void
    {
        $this->siteFinder->method('getSiteByIdentifier')->willReturn(
            $this->buildSite('main', ['externalWebsiteId' => 'ext-1', 'apiKey' => 'key'], base: $base, languageBases: $languageBases)
        );

        self::assertSame($expected, $this->subject->resolveAnalyticsSite('main')['pageUrlFilter'] ?? null);
    }

    /** Helpers */

    /**
     * @param list<string> $languageBases
     */
    private function buildSite(string $identifier, array $settings, string $websiteTitle = '', string $base = 'https://example.com/', array $languageBases = []): Site
    {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getConfiguration')->willReturn(['websiteTitle' => $websiteTitle]);
        $site->method('getRootPageId')->willReturn(1);
        $site->method('getBase')->willReturn(new Uri($base));
        $site->method('getAllLanguages')->willReturn(array_map(function (string $languageBase): SiteLanguage {
            $language = $this->createMock(SiteLanguage::class);
            $language->method('getBase')->willReturn(new Uri($languageBase));
            return $language;
        }, $languageBases));
        $site->method('getSettings')->willReturn(new SiteSettings(new Settings($settings), [], []));
        return $site;
    }
}
