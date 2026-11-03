<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Tests\Fixtures\AnalyticsIds;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;

/**
 * Resolves the analytics sites from real site configurations. The backend user from
 * Fixtures/analytics_site_provider.csv has the root pages of both sites as DB mounts.
 */
final class AnalyticsSiteProviderTest extends FunctionalTestCase
{
    private const EDITOR = 1;

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        ],
    ];

    /** @var array<string, string> */
    private array $credentials = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/analytics_site_provider.csv');
        $this->setUpBackendUser(self::EDITOR);
        $this->credentials = [
            'externalWebsiteId' => AnalyticsIds::EXTERNAL_WEBSITE_ID,
            'apiKey' => (new CipherService())->encrypt('test-api-key'),
        ];
    }

    /** siteOptions */

    #[Test]
    public function siteOptionsListsSitesWithCredentials(): void
    {
        $this->writeSite('main', 1, 'https://example.com/', settings: $this->credentials, websiteTitle: 'Main');
        $this->writeSite('unregistered', 2, 'https://unregistered.example.com/');

        self::assertSame(['main' => 'Main (main)'], $this->subject()->siteOptions());
    }

    #[Test]
    public function siteOptionsListsSitesAttachedToAnActiveSite(): void
    {
        $this->writeSite('main', 1, 'https://example.com/', settings: $this->credentials, websiteTitle: 'Main');
        $this->writeSite('second', 2, 'https://second.example.com/', settings: [...$this->credentials, 'attachedTo' => 'main'], websiteTitle: 'Second');

        self::assertSame(['main' => 'Main (main)', 'second' => 'Second (second)'], $this->subject()->siteOptions());
    }

    /** resolveAnalyticsSite */

    #[Test]
    public function resolveAnalyticsSiteReturnsTheCredentialsAndThePageUrlFilterOfTheSite(): void
    {
        $this->writeSite('main', 1, 'https://example.com/', settings: $this->credentials);

        $result = $this->subject()->resolveAnalyticsSite('main');

        self::assertNotNull($result);
        self::assertSame('main', $result['site']->getIdentifier());
        self::assertSame(AnalyticsIds::EXTERNAL_WEBSITE_ID, $result['websiteId']);
        self::assertSame('test-api-key', $result['apiKey']);
        self::assertSame(['://example.com'], $result['pageUrlFilter']);
    }

    #[Test]
    public function resolveAnalyticsSiteLimitsAnAttachedSiteToItsOwnHost(): void
    {
        $this->writeSite('main', 1, 'https://example.com/', settings: $this->credentials);
        $this->writeSite('second', 2, 'https://second.example.com/', settings: [...$this->credentials, 'attachedTo' => 'main']);

        $result = $this->subject()->resolveAnalyticsSite('second');

        self::assertNotNull($result);
        self::assertSame(AnalyticsIds::EXTERNAL_WEBSITE_ID, $result['websiteId']);
        self::assertSame(['://second.example.com'], $result['pageUrlFilter']);
    }

    /**
     * @return \Generator<string, array{string, string, list<string>}>
     */
    public static function pageUrlFilterProvider(): \Generator
    {
        yield 'language with a path on the same host' => ['https://example.com/', 'https://example.com/de/', ['://example.com']];
        yield 'language with a relative base' => ['https://example.com/', '/de/', ['://example.com']];
        yield 'language on another host' => ['https://example.com/', 'https://example.ch/', ['://example.ch', '://example.com']];
        yield 'site with a path' => ['https://example.com/shop/', 'https://example.com/shop/de/', ['://example.com/shop']];
        yield 'host with a port' => ['https://example.com/', 'https://example.com:8080/', ['://example.com', '://example.com:8080']];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('pageUrlFilterProvider')]
    public function resolveAnalyticsSiteBuildsThePageUrlFilterFromAllBases(string $base, string $languageBase, array $expected): void
    {
        $this->writeSite('main', 1, $base, $languageBase, $this->credentials);

        self::assertSame($expected, $this->subject()->resolveAnalyticsSite('main')['pageUrlFilter'] ?? null);
    }

    /** Helpers */

    private function subject(): AnalyticsSiteProviderInterface
    {
        return $this->get(AnalyticsSiteProviderInterface::class);
    }

    /**
     * @param array<string, string> $settings
     */
    private function writeSite(string $identifier, int $rootPageId, string $base, ?string $secondLanguageBase = null, array $settings = [], string $websiteTitle = ''): void
    {
        $languages = [
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
        ];
        if ($secondLanguageBase !== null) {
            $languages[] = ['languageId' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF-8', 'base' => $secondLanguageBase, 'enabled' => true];
        }
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write($identifier, [
            'rootPageId' => $rootPageId,
            'base' => $base,
            'websiteTitle' => $websiteTitle,
            'dependencies' => ['t3g/analytics'],
            'languages' => $languages,
        ]);
        if ($settings !== []) {
            $siteWriter->writeSettings($identifier, $settings);
        }
    }
}
