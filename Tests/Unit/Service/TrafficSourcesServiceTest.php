<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Service\AnalyticsDataClientInterface;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\TrafficSourcesService;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TrafficSourcesServiceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private AnalyticsDataClientInterface&MockObject $analyticsClient;
    private LoggerInterface&MockObject $logger;
    private FrontendInterface&MockObject $cache;
    private AnalyticsSiteProviderInterface&MockObject $siteProvider;
    private TrafficSourcesService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyticsClient = $this->createMock(AnalyticsDataClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cache = $this->createMock(FrontendInterface::class);
        $this->siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);

        $this->subject = new TrafficSourcesService(
            $this->analyticsClient,
            $this->logger,
            $this->cache,
            $this->siteProvider,
        );
    }

    #[Test]
    public function loadTrafficSourcesReturnsNullWhenSiteNotFound(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(null);

        $result = $this->subject->loadTrafficSources('unknown-site', 30);

        self::assertNull($result);
    }

    #[Test]
    public function loadTrafficSourcesReturnsCachedDataOnCacheHit(): void
    {
        $cached = ['direct' => ['current' => 10, 'previous' => 5], 'search' => ['current' => 20, 'previous' => 15]];
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn($cached);
        $this->analyticsClient->expects(self::never())->method('fetchTrafficShareInDepth');

        $result = $this->subject->loadTrafficSources('my-site', 30);

        self::assertSame($cached, $result);
    }

    #[Test]
    public function loadTrafficSourcesFetchesAndCachesDataOnCacheMiss(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchTrafficShareInDepth')->willReturnOnConsecutiveCalls(
            [
                'labels' => ['2026-06-01T00:00:00+02:00'],
                'datasets' => [
                    ['label' => 'direct', 'data' => [4], 'total' => 4],
                    ['label' => 'search', 'data' => [7], 'total' => 7],
                ],
            ],
            [
                'labels' => ['2026-05-01T00:00:00+02:00'],
                'datasets' => [
                    ['label' => 'direct', 'data' => [2], 'total' => 2],
                    ['label' => 'search', 'data' => [3], 'total' => 3],
                ],
            ],
        );
        $expected = [
            'direct' => ['current' => 4, 'previous' => 2],
            'search' => ['current' => 7, 'previous' => 3],
        ];
        $this->cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('traffic_sources_'),
            $expected,
        );

        $result = $this->subject->loadTrafficSources('my-site', 30);

        self::assertSame($expected, $result);
    }

    #[Test]
    public function loadTrafficSourcesReturnsNullAndLogsWarningOnApiException(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchTrafficShareInDepth')->willThrowException(
            new AnalyticsApiException('API error', 500),
        );
        $this->logger->expects(self::once())->method('warning');

        $result = $this->subject->loadTrafficSources('my-site', 30);

        self::assertNull($result);
    }

    #[Test]
    public function loadTrafficSourcesNormalizesDaysToAtLeastOne(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchTrafficShareInDepth')->willReturn(['labels' => [], 'datasets' => []]);
        $this->cache->expects(self::once())->method('set')->with(
            'traffic_sources_' . md5('w1_1'),
            self::anything(),
        );

        $this->subject->loadTrafficSources('my-site', 0);
    }

    #[Test]
    public function loadTrafficSourcesSkipsDatasetsWithEmptyLabel(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchTrafficShareInDepth')->willReturn([
            'labels' => [],
            'datasets' => [
                ['label' => '', 'data' => [], 'total' => 5],
                ['label' => 'direct', 'data' => [], 'total' => 3],
            ],
        ]);

        $result = $this->subject->loadTrafficSources('my-site', 7);

        self::assertArrayNotHasKey('', $result);
        self::assertSame(['direct' => ['current' => 3, 'previous' => 3]], $result);
    }

    #[Test]
    public function loadDeviceDataReturnsNullWhenSiteNotFound(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(null);

        $result = $this->subject->loadDeviceData('unknown-site', 30);

        self::assertNull($result);
    }

    #[Test]
    public function loadDeviceDataReturnsCachedDataOnCacheHit(): void
    {
        $cached = [['deviceType' => 'desktop', 'sessionCount' => 5, 'sessionPercentOfTotal' => 100.0]];
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn($cached);
        $this->analyticsClient->expects(self::never())->method('fetchDeviceSessions');

        $result = $this->subject->loadDeviceData('my-site', 30);

        self::assertSame($cached, $result);
    }

    #[Test]
    public function loadDeviceDataFetchesAndCachesDataOnCacheMiss(): void
    {
        $apiData = [
            ['deviceType' => 'desktop', 'sessionCount' => 10, 'sessionPercentOfTotal' => 66.7],
            ['deviceType' => 'mobile', 'sessionCount' => 5, 'sessionPercentOfTotal' => 33.3],
        ];
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchDeviceSessions')->willReturn($apiData);
        $this->cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('traffic_devices_'),
            $apiData,
        );

        $result = $this->subject->loadDeviceData('my-site', 30);

        self::assertSame($apiData, $result);
    }

    #[Test]
    public function loadDeviceDataReturnsNullAndLogsWarningOnApiException(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchDeviceSessions')->willThrowException(
            new AnalyticsApiException('API error', 500),
        );
        $this->logger->expects(self::once())->method('warning');

        $result = $this->subject->loadDeviceData('my-site', 30);

        self::assertNull($result);
    }

    #[Test]
    public function loadBrowserDataReturnsNullWhenSiteNotFound(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(null);

        $result = $this->subject->loadBrowserData('unknown-site', 30);

        self::assertNull($result);
    }

    #[Test]
    public function loadBrowserDataReturnsCachedDataOnCacheHit(): void
    {
        $cached = [['browserName' => 'Chrome', 'sessionCount' => 3, 'sessionPercentOfTotal' => 100.0]];
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn($cached);
        $this->analyticsClient->expects(self::never())->method('fetchBrowserSessions');

        $result = $this->subject->loadBrowserData('my-site', 30);

        self::assertSame($cached, $result);
    }

    #[Test]
    public function loadBrowserDataFetchesAndCachesDataOnCacheMiss(): void
    {
        $apiData = [['browserName' => 'Chrome', 'sessionCount' => 3, 'sessionPercentOfTotal' => 100.0]];
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchBrowserSessions')->willReturn($apiData);
        $this->cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('traffic_browsers_'),
            $apiData,
        );

        $result = $this->subject->loadBrowserData('my-site', 30);

        self::assertSame($apiData, $result);
    }

    #[Test]
    public function loadBrowserDataReturnsNullAndLogsWarningOnApiException(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchBrowserSessions')->willThrowException(
            new AnalyticsApiException('API error', 500),
        );
        $this->logger->expects(self::once())->method('warning');

        $result = $this->subject->loadBrowserData('my-site', 30);

        self::assertNull($result);
    }

    #[Test]
    public function loadCountryDataReturnsNullWhenSiteNotFound(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(null);

        $result = $this->subject->loadCountryData('unknown-site', 30);

        self::assertNull($result);
    }

    #[Test]
    public function loadCountryDataFetchesAndCachesDataOnCacheMiss(): void
    {
        $apiData = [['countryCode' => 'DE', 'sessionCount' => 3, 'sessionPercentOfTotal' => 100.0]];
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchCountrySessions')->willReturn($apiData);
        $this->cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('traffic_countries_'),
            $apiData,
        );

        $result = $this->subject->loadCountryData('my-site', 30);

        self::assertSame($apiData, $result);
    }

    #[Test]
    public function loadCountryDataReturnsNullAndLogsWarningOnApiException(): void
    {
        $this->siteProvider->method('resolveAnalyticsSite')->willReturn(['websiteId' => 'w1', 'apiKey' => 'k1']);
        $this->cache->method('get')->willReturn(false);
        $this->analyticsClient->method('fetchCountrySessions')->willThrowException(
            new AnalyticsApiException('API error', 500),
        );
        $this->logger->expects(self::once())->method('warning');

        $result = $this->subject->loadCountryData('my-site', 30);

        self::assertNull($result);
    }
}
