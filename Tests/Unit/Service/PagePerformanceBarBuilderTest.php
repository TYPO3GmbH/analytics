<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Service\AnalyticsDataClient;
use T3G\Analytics\Service\ApiExceptionExtractor;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\PagePerformanceBarBuilder;
use T3G\Analytics\View\SparklineRenderer;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PagePerformanceBarBuilderTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private MockHandler $mockHandler;
    private FrontendInterface&MockObject $cache;
    private string $encryptedApiKey;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);
        $this->mockHandler = new MockHandler();
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = ['verify' => false, 'handler' => HandlerStack::create($this->mockHandler)];
        $this->cache = $this->createMock(FrontendInterface::class);
        $this->encryptedApiKey = (new CipherService())->encrypt('test-api-key');

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);
        $GLOBALS['LANG'] = $languageService;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP'], $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    private function buildAnalyticsClient(): AnalyticsDataClient
    {
        $extConf = $this->createMock(ExtensionConfiguration::class);
        $extConf->method('get')->willReturn('');

        return new AnalyticsDataClient(
            new RequestFactory(new GuzzleClientFactory()),
            new ApiExceptionExtractor(),
            new ApiConfiguration($extConf),
            new GuzzleClientFactory(),
        );
    }

    private function buildSubject(SiteFinder $siteFinder): PagePerformanceBarBuilder
    {
        return new PagePerformanceBarBuilder(
            new SparklineRenderer(),
            $siteFinder,
            $this->createMock(UriBuilder::class),
            $this->buildAnalyticsClient(),
            new CipherService(),
            $this->createMock(LoggerInterface::class),
            $this->cache,
        );
    }

    private function buildSite(): Site
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('generateUri')->willReturn(new Uri('https://example.com/page'));

        $site = $this->createMock(Site::class);
        $site->method('getSettings')->willReturn(new SiteSettings(new Settings([
            'externalWebsiteId' => 'test-website-id',
            'apiKey' => $this->encryptedApiKey,
        ]), [], []));
        $site->method('getRouter')->willReturn($router);
        $site->method('getIdentifier')->willReturn('test-site');

        return $site;
    }

    private function callLoadPageData(PagePerformanceBarBuilder $subject, int $pageId = 1, int $days = 7): mixed
    {
        $site = $subject->trySite($pageId);
        return (new \ReflectionMethod($subject, 'loadPageData'))->invoke($subject, $pageId, $site, null, $days);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function callBuildMetrics(PagePerformanceBarBuilder $subject, int $pageId = 1, int $days = 7): array
    {
        $site = $subject->trySite($pageId);
        return (new \ReflectionMethod($subject, 'buildMetrics'))->invoke($subject, $pageId, $site, null, $days);
    }

    private function pageApiResponse(int $visitCount, float $bounceRate = 0.0, float $avgDuration = 0.0): Response
    {
        return new Response(200, [], json_encode(['payload' => [[
            'visitCount' => $visitCount,
            'bounceRate' => $bounceRate,
            'averageVisitDuration' => $avgDuration,
        ]]]));
    }

    private function emptyPageApiResponse(): Response
    {
        return new Response(200, [], '{"payload":[]}');
    }

    private function emptySeriesResponse(): Response
    {
        return new Response(200, [], '{"payload":{"datasets":[{"data":[]}]}}');
    }

    private function siteFinder(): SiteFinder
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($this->buildSite());
        return $siteFinder;
    }

    /** buildMetrics — null previous period */

    #[Test]
    public function buildMetricsHasNullVisitTrendWhenNoPreviousData(): void
    {
        $this->mockHandler->append(
            $this->pageApiResponse(21, 50.0, 0.0),
            $this->emptyPageApiResponse(),
            $this->emptySeriesResponse(),
            $this->emptySeriesResponse(),
        );
        $this->cache->method('get')->willReturn(false);

        $metrics = $this->callBuildMetrics($this->buildSubject($this->siteFinder()));

        // percentTrend(visitCount=21, prevVisitCount=0) → null (division by zero guarded)
        self::assertNull($metrics[0]['trend']);
    }

    #[Test]
    public function buildMetricsBounceRateTrendIsNullWhenNoPreviousData(): void
    {
        $this->mockHandler->append(
            $this->pageApiResponse(21, 50.0, 0.0),
            $this->emptyPageApiResponse(),
            $this->emptySeriesResponse(),
            $this->emptySeriesResponse(),
        );
        $this->cache->method('get')->willReturn(false);

        $metrics = $this->callBuildMetrics($this->buildSubject($this->siteFinder()));

        // When there is no previous-period data, bounce rate trend must be null — not -100%.
        self::assertNull($metrics[1]['trend']);
    }

    /** buildMetrics — bounce rate direction (inverted: lower bounce = better) */

    #[Test]
    public function buildMetricsBounceRateTrendDirectionIsUpWhenBounceRateDecreased(): void
    {
        $this->mockHandler->append(
            $this->pageApiResponse(21, 30.0, 0.0),
            $this->pageApiResponse(21, 70.0, 0.0),
            $this->emptySeriesResponse(),
            $this->emptySeriesResponse(),
        );
        $this->cache->method('get')->willReturn(false);

        $metrics = $this->callBuildMetrics($this->buildSubject($this->siteFinder()));

        // trendDirection(prev=70, current=30) → 70 > 30 → 'up' (lower bounce is better)
        self::assertSame('up', $metrics[1]['trendDirection']);
    }

    #[Test]
    public function buildMetricsBounceRateTrendDirectionIsDownWhenBounceRateIncreased(): void
    {
        $this->mockHandler->append(
            $this->pageApiResponse(21, 70.0, 0.0),
            $this->pageApiResponse(21, 30.0, 0.0),
            $this->emptySeriesResponse(),
            $this->emptySeriesResponse(),
        );
        $this->cache->method('get')->willReturn(false);

        $metrics = $this->callBuildMetrics($this->buildSubject($this->siteFinder()));

        // trendDirection(prev=30, current=70) → 30 < 70 → 'down' (higher bounce is worse)
        self::assertSame('down', $metrics[1]['trendDirection']);
    }

    #[Test]
    public function buildMetricsVisitTrendIsComputedWhenPreviousDataExists(): void
    {
        $this->mockHandler->append(
            $this->pageApiResponse(20, 0.0, 0.0),
            $this->pageApiResponse(10, 0.0, 0.0),
            $this->emptySeriesResponse(),
            $this->emptySeriesResponse(),
        );
        $this->cache->method('get')->willReturn(false);

        $metrics = $this->callBuildMetrics($this->buildSubject($this->siteFinder()));

        self::assertSame('+100.00%', $metrics[0]['trend']);
        self::assertSame('up', $metrics[0]['trendDirection']);
    }

    /** loadPageData tests */

    #[Test]
    public function loadPageDataDoesNotCacheOnApiError(): void
    {
        $this->mockHandler->append(new Response(401, [], '{"error":"AUTHENTICATION_INVALID_API_KEY"}'));

        $this->cache->method('get')->willReturn(false);
        $this->cache->expects(self::never())->method('set');

        self::assertNull($this->callLoadPageData($this->buildSubject($this->siteFinder())));
    }

    #[Test]
    public function loadPageDataCachesNullWhenPageHasNoData(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->cache->method('get')->willReturn(false);
        $this->cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('page_'),
            null,
        );

        self::assertNull($this->callLoadPageData($this->buildSubject($this->siteFinder())));
    }

    #[Test]
    public function loadPageDataReturnsCachedNullWithoutApiCall(): void
    {
        $this->cache->method('get')->willReturn(null);

        self::assertNull($this->callLoadPageData($this->buildSubject($this->siteFinder())));
        self::assertCount(0, $this->mockHandler);
    }

    #[Test]
    public function loadPageDataCachesAndReturnsDataOnSuccess(): void
    {
        $pageRow = ['visitCount' => 42, 'bounceRate' => 0.5, 'averageVisitDuration' => 120];
        $emptySeries = '{"payload":{"datasets":[{"data":[]}]}}';
        $this->mockHandler->append(
            new Response(200, [], json_encode(['payload' => [$pageRow]])),
            new Response(200, [], $emptySeries),
            new Response(200, [], $emptySeries),
            new Response(200, [], $emptySeries),
        );

        $this->cache->method('get')->willReturn(false);
        $this->cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('page_'),
            self::isArray(),
        );

        $result = $this->callLoadPageData($this->buildSubject($this->siteFinder()));

        self::assertIsArray($result);
        self::assertSame($pageRow, $result['page']);
    }
}
