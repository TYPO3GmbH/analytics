<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use T3G\Analytics\Service\AnalyticsDataClientInterface;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\BackendPageAccessCheckerInterface;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\MetricFormatter;
use T3G\Analytics\Service\TopPagesService;
use T3G\Analytics\Tests\Fixtures\AnalyticsIds;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;

/**
 * Uses a real site with the languages "/" and "/de/", the real router and the
 * real page access check. Only the data API returns fixed top pages.
 */
final class TopPagesServiceTest extends FunctionalTestCase
{
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/top_pages.csv');
        $this->setUpBackendUser(1);
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'dependencies' => ['t3g/analytics'],
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
                ['languageId' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'flag' => 'de'],
            ],
        ]);
        $siteWriter->writeSettings('main', [
            'externalWebsiteId' => AnalyticsIds::EXTERNAL_WEBSITE_ID,
            'apiKey' => (new CipherService())->encrypt('plain-api-key'),
        ]);
    }

    #[Test]
    public function loadTopPagesDataExcludesPageWhenHostOnlyStartsWithSiteHost(): void
    {
        $result = $this->buildSubject([['pageUrl' => 'https://example.com.example.org/about', 'visitCount' => 10]])
            ->loadTopPagesData('main', 7);

        self::assertSame([], $result);
    }

    #[Test]
    public function loadTopPagesDataDoesNotMatchLanguageWhenPathOnlyStartsWithLanguageBase(): void
    {
        $result = $this->buildSubject([['pageUrl' => 'https://example.com/demo', 'visitCount' => 10]])
            ->loadTopPagesData('main', 7);

        self::assertCount(1, $result);
        self::assertSame('flags-us', $result[0]['flagIdentifier']);
        self::assertSame(2, $result[0]['pageId']);
        self::assertSame(0, $result[0]['languageId']);
    }

    /**
     * @param list<array<string, mixed>> $topPages
     */
    private function buildSubject(array $topPages): TopPagesService
    {
        $analyticsClient = $this->createMock(AnalyticsDataClientInterface::class);
        $analyticsClient->method('fetchTopPages')->willReturn($topPages);
        $cache = $this->get(CacheManager::class)->getCache('tx_analytics_top_pages');
        $cache->flush();

        return new TopPagesService(
            $analyticsClient,
            new NullLogger(),
            $cache,
            $this->get(BackendPageAccessCheckerInterface::class),
            new MetricFormatter(),
            $this->get(AnalyticsSiteProviderInterface::class),
            $this->get(UriBuilder::class),
        );
    }
}
