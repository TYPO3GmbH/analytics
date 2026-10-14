<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use T3G\Analytics\Service\AnalyticsSiteProvider;
use T3G\Analytics\Service\BackendPageAccessChecker;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Tests\Fixtures\AnalyticsIds;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Uses the real cipher service and the real page access check with an admin
 * user who can access the root page of the site.
 */
final class AnalyticsSiteProviderTest extends FunctionalTestCase
{
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/analytics_site_provider.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function resolveAnalyticsSiteReturnsCredentialsForValidExternalWebsiteId(): void
    {
        $result = $this->buildSubject(AnalyticsIds::EXTERNAL_WEBSITE_ID)->resolveAnalyticsSite('main');

        self::assertNotNull($result);
        self::assertSame(AnalyticsIds::EXTERNAL_WEBSITE_ID, $result['websiteId']);
        self::assertSame('plain-api-key', $result['apiKey']);
    }

    public static function invalidExternalWebsiteIdDataProvider(): \Generator
    {
        yield 'path segment' => ['..'];
        yield 'path with query' => ['../x?y'];
        yield 'no UUID' => ['not-a-uuid'];
    }

    #[Test]
    #[DataProvider('invalidExternalWebsiteIdDataProvider')]
    public function resolveAnalyticsSiteIgnoresStoredExternalWebsiteIdThatIsNoUuid(string $externalWebsiteId): void
    {
        self::assertNull($this->buildSubject($externalWebsiteId)->resolveAnalyticsSite('main'));
    }

    private function buildSubject(string $externalWebsiteId): AnalyticsSiteProvider
    {
        $cipherService = new CipherService();
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn('main');
        $site->method('getRootPageId')->willReturn(1);
        $site->method('getSettings')->willReturn(new SiteSettings(
            new Settings(['externalWebsiteId' => $externalWebsiteId, 'apiKey' => $cipherService->encrypt('plain-api-key')]),
            [],
            []
        ));
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willReturn($site);

        return new AnalyticsSiteProvider($siteFinder, $cipherService, new BackendPageAccessChecker(), new NullLogger());
    }
}
