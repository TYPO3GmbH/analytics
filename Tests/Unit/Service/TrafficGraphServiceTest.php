<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Service\AnalyticsDataClientInterface;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\TrafficGraphService;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TrafficGraphServiceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private AnalyticsDataClientInterface&MockObject $analyticsClient;
    private LoggerInterface&MockObject $logger;
    private FrontendInterface&MockObject $cache;
    private AnalyticsSiteProviderInterface&MockObject $siteProvider;
    private TrafficGraphService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyticsClient = $this->createMock(AnalyticsDataClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cache = $this->createMock(FrontendInterface::class);
        $this->siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);

        $this->subject = new TrafficGraphService(
            $this->analyticsClient,
            $this->logger,
            $this->cache,
            $this->siteProvider,
        );
    }

    #[Test]
    public function loadGraphDataReturnsNullWhenSiteNotFound(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(null);

        $result = $this->subject->loadGraphData('unknown-site', 30);

        self::assertNull($result);
    }

    #[Test]
    public function loadGraphDataReturnsCachedDataOnCacheHit(): void
    {
        $cached = ['labels' => ['2024-01-01'], 'data' => [5]];
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn($cached);
        $this->analyticsClient->expects(self::never())->method('fetchSiteVisitsGraph');

        $result = $this->subject->loadGraphData('my-site', 30);

        self::assertSame($cached, $result);
    }

    #[Test]
    public function loadGraphDataFetchesAndCachesDataOnCacheMiss(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchSiteVisitsGraph')->willReturn([
            'labels' => ['2024-01-01', '2024-01-02'],
            'datasets' => [['data' => [3, 7]]],
        ]);
        $expected = ['labels' => ['2024-01-01', '2024-01-02'], 'data' => [3, 7]];
        $this->cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('traffic_graph_'),
            $expected,
        );

        $result = $this->subject->loadGraphData('my-site', 30);

        self::assertSame($expected, $result);
    }

    #[Test]
    public function loadGraphDataReturnsNullAndLogsWarningOnApiException(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchSiteVisitsGraph')->willThrowException(
            new AnalyticsApiException('API error', 500),
        );
        $this->logger->expects(self::once())->method('warning');

        $result = $this->subject->loadGraphData('my-site', 30);

        self::assertNull($result);
    }

    #[Test]
    public function loadGraphDataNormalizesDaysToAtLeastOne(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchSiteVisitsGraph')->willReturn(['labels' => [], 'datasets' => []]);
        $this->cache->expects(self::once())->method('set')->with(
            'traffic_graph_' . md5('w1_1'),
            self::anything(),
        );

        $this->subject->loadGraphData('my-site', 0);
    }

    #[Test]
    public function loadSessionsDataReturnsNullWhenSiteNotFound(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(null);

        $result = $this->subject->loadSessionsData('unknown-site', 30);

        self::assertNull($result);
    }

    #[Test]
    public function loadSessionsDataReturnsCachedDataOnCacheHit(): void
    {
        $cached = ['labels' => ['2024-01-01'], 'data' => [5]];
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn($cached);
        $this->analyticsClient->expects(self::never())->method('fetchSiteSessionsGraph');

        $result = $this->subject->loadSessionsData('my-site', 30);

        self::assertSame($cached, $result);
    }

    #[Test]
    public function loadSessionsDataFetchesAndCachesDataOnCacheMiss(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchSiteSessionsGraph')->willReturn([
            'labels' => ['2024-01-01', '2024-01-02'],
            'datasets' => [['data' => [3, 7]]],
        ]);
        $expected = ['labels' => ['2024-01-01', '2024-01-02'], 'data' => [3, 7]];
        $this->cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('traffic_sessions_'),
            $expected,
        );

        $result = $this->subject->loadSessionsData('my-site', 30);

        self::assertSame($expected, $result);
    }

    #[Test]
    public function loadSessionsDataReturnsNullAndLogsWarningOnApiException(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchSiteSessionsGraph')->willThrowException(
            new AnalyticsApiException('API error', 500),
        );
        $this->logger->expects(self::once())->method('warning');

        $result = $this->subject->loadSessionsData('my-site', 30);

        self::assertNull($result);
    }

    #[Test]
    public function loadVisitorsDataReturnsNullWhenSiteNotFound(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(null);

        $result = $this->subject->loadVisitorsData('unknown-site', 30);

        self::assertNull($result);
    }

    #[Test]
    public function loadVisitorsDataReturnsCachedDataOnCacheHit(): void
    {
        $cached = ['labels' => ['2024-01-01'], 'data' => [5]];
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn($cached);
        $this->analyticsClient->expects(self::never())->method('fetchSiteVisitorsGraph');

        $result = $this->subject->loadVisitorsData('my-site', 30);

        self::assertSame($cached, $result);
    }

    #[Test]
    public function loadVisitorsDataFetchesAndCachesDataOnCacheMiss(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchSiteVisitorsGraph')->willReturn([
            'labels' => ['2024-01-01', '2024-01-02'],
            'datasets' => [['data' => [10, 20]]],
        ]);
        $expected = ['labels' => ['2024-01-01', '2024-01-02'], 'data' => [10, 20]];
        $this->cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('traffic_visitors_'),
            $expected,
        );

        $result = $this->subject->loadVisitorsData('my-site', 30);

        self::assertSame($expected, $result);
    }

    #[Test]
    public function loadVisitorsDataReturnsNullAndLogsWarningOnApiException(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchSiteVisitorsGraph')->willThrowException(
            new AnalyticsApiException('API error', 500),
        );
        $this->logger->expects(self::once())->method('warning');

        $result = $this->subject->loadVisitorsData('my-site', 30);

        self::assertNull($result);
    }
}
