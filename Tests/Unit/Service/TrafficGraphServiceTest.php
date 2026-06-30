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
        $labels = ['2024-01-01'];
        $overall = ['labels' => $labels, 'data' => [5]];
        $cached = [
            'new' => ['labels' => $labels, 'data' => [3]],
            'returning' => ['labels' => $labels, 'data' => [2]],
            'overall' => $overall,
        ];
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn($cached);
        $this->analyticsClient->expects(self::never())->method('fetchSiteVisitorsGraph');

        $result = $this->subject->loadVisitorsData('my-site', 30);

        self::assertSame($overall, $result);
    }

    #[Test]
    public function loadVisitorsDataFetchesAndCachesDataOnCacheMiss(): void
    {
        $labels = ['2024-01-01', '2024-01-02'];
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchSiteVisitorsGraph')->willReturn([
            'labels' => $labels,
            'datasets' => [
                ['data' => [3, 5]],
                ['data' => [2, 4]],
                ['data' => [5, 9]],
            ],
        ]);
        $expectedBreakdown = [
            'new' => ['labels' => $labels, 'data' => [3, 5]],
            'returning' => ['labels' => $labels, 'data' => [2, 4]],
            'overall' => ['labels' => $labels, 'data' => [5, 9]],
        ];
        $this->cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('traffic_visitors_breakdown_'),
            $expectedBreakdown,
        );

        $result = $this->subject->loadVisitorsData('my-site', 30);

        self::assertSame($expectedBreakdown['overall'], $result);
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

    #[Test]
    public function loadVisitorsBreakdownDataReturnsNullWhenSiteNotFound(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(null);

        $result = $this->subject->loadVisitorsBreakdownData('unknown-site', 30);

        self::assertNull($result);
    }

    #[Test]
    public function loadVisitorsBreakdownDataReturnsCachedDataOnCacheHit(): void
    {
        $labels = ['2024-01-01'];
        $cached = [
            'new' => ['labels' => $labels, 'data' => [3]],
            'returning' => ['labels' => $labels, 'data' => [2]],
            'overall' => ['labels' => $labels, 'data' => [5]],
        ];
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn($cached);
        $this->analyticsClient->expects(self::never())->method('fetchSiteVisitorsGraph');

        $result = $this->subject->loadVisitorsBreakdownData('my-site', 30);

        self::assertSame($cached, $result);
    }

    #[Test]
    public function loadVisitorsBreakdownDataFetchesAndCachesAllThreeDatasets(): void
    {
        $labels = ['2024-01-01', '2024-01-02'];
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchSiteVisitorsGraph')->willReturn([
            'labels' => $labels,
            'datasets' => [
                ['data' => [3, 5]],
                ['data' => [2, 4]],
                ['data' => [5, 9]],
            ],
        ]);
        $expected = [
            'new' => ['labels' => $labels, 'data' => [3, 5]],
            'returning' => ['labels' => $labels, 'data' => [2, 4]],
            'overall' => ['labels' => $labels, 'data' => [5, 9]],
        ];
        $this->cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('traffic_visitors_breakdown_'),
            $expected,
        );

        $result = $this->subject->loadVisitorsBreakdownData('my-site', 30);

        self::assertSame($expected, $result);
    }

    #[Test]
    public function loadVisitorsBreakdownDataReturnsNullAndLogsWarningOnApiException(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchSiteVisitorsGraph')->willThrowException(
            new AnalyticsApiException('API error', 500),
        );
        $this->logger->expects(self::once())->method('warning');

        $result = $this->subject->loadVisitorsBreakdownData('my-site', 30);

        self::assertNull($result);
    }
}
