<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Service\AnalyticsDataClientInterface;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\MetricFormatter;
use T3G\Analytics\Service\SitePerformanceService;
use T3G\Analytics\Service\TrendNote;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SitePerformanceServiceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private SitePerformanceService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new SitePerformanceService(
            $this->createMock(AnalyticsDataClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(FrontendInterface::class),
            new MetricFormatter(),
            $this->createMock(AnalyticsSiteProviderInterface::class),
        );
    }

    /** buildMetricItems — structure */

    #[Test]
    public function buildMetricItemsReturnsFourItems(): void
    {
        $result = $this->subject->buildMetricItems($this->data(), 'Visits', 'Visitors', 'Bounce rate', 'Avg. duration', 'vs. prev.');

        self::assertCount(4, $result);
    }

    #[Test]
    public function buildMetricItemsAssignsLabels(): void
    {
        $result = $this->subject->buildMetricItems($this->data(), 'Visits', 'Visitors', 'Bounce', 'Duration', 'trend');

        self::assertSame('Visits', $result[0]['label']);
        self::assertSame('Visitors', $result[1]['label']);
        self::assertSame('Bounce', $result[2]['label']);
        self::assertSame('Duration', $result[3]['label']);
    }

    #[Test]
    public function buildMetricItemsAssignsTones(): void
    {
        $result = $this->subject->buildMetricItems($this->data(), '', '', '', '', '');

        self::assertSame('visits', $result[0]['tone']);
        self::assertSame('visitors', $result[1]['tone']);
        self::assertSame('bounce-rate', $result[2]['tone']);
        self::assertSame('avg-duration', $result[3]['tone']);
    }

    /** buildMetricItems — value formatting */

    #[Test]
    public function buildMetricItemsFormatsVisitCountWithThousandSeparator(): void
    {
        $result = $this->subject->buildMetricItems($this->data(visitCount: 1234), '', '', '', '', '');

        self::assertSame('1.234', $result[0]['value']);
    }

    #[Test]
    public function buildMetricItemsFormatsBounceRateAsPercentage(): void
    {
        $result = $this->subject->buildMetricItems($this->data(bounceRate: 42.5), '', '', '', '', '');

        self::assertSame('42.50%', $result[2]['value']);
    }

    #[Test]
    public function buildMetricItemsFormatsBounceRateWithTwoDecimals(): void
    {
        $result = $this->subject->buildMetricItems($this->data(bounceRate: 50.0), '', '', '', '', '');

        self::assertSame('50.00%', $result[2]['value']);
    }

    #[Test]
    public function buildMetricItemsFormatsAvgTimeOnPageAsMinutesAndSeconds(): void
    {
        $result = $this->subject->buildMetricItems($this->data(avgTimeOnPage: 125), '', '', '', '', '');

        self::assertSame('2:05', $result[3]['value']);
    }

    #[Test]
    public function buildMetricItemsFormatsZeroAvgTimeOnPageAsZeroZero(): void
    {
        $result = $this->subject->buildMetricItems($this->data(avgTimeOnPage: 0), '', '', '', '', '');

        self::assertSame('0:00', $result[3]['value']);
    }

    /** buildMetricItems — trend direction */

    #[Test]
    public function buildMetricItemsShowsUpTrendWhenVisitsIncreased(): void
    {
        $result = $this->subject->buildMetricItems($this->data(visitCount: 20, prevVisitCount: 10), '', '', '', '', '');

        self::assertSame('up', $result[0]['trendDirection']);
        self::assertSame('+10', $result[0]['trend']);
    }

    #[Test]
    public function buildMetricItemsShowsDownTrendWhenVisitsDecreased(): void
    {
        $result = $this->subject->buildMetricItems($this->data(visitCount: 5, prevVisitCount: 10), '', '', '', '', '');

        self::assertSame('down', $result[0]['trendDirection']);
        self::assertSame('-5', $result[0]['trend']);
    }

    #[Test]
    public function buildMetricItemsShowsCountTrendsWhenPreviousPeriodIsZero(): void
    {
        $result = $this->subject->buildMetricItems($this->data(visitCount: 2, prevVisitCount: 0, visitorCount: 1, prevVisitorCount: 0), '', '', '', '', '');

        self::assertSame('+2', $result[0]['trend']);
        self::assertSame('up', $result[0]['trendDirection']);
        self::assertSame('', $result[0]['trendNote']);
        self::assertSame('+1', $result[1]['trend']);
        self::assertSame('', $result[1]['trendNote']);
    }

    #[Test]
    public function buildMetricItemsHidesRateTrendsWithoutPreviousVisits(): void
    {
        $result = $this->subject->buildMetricItems($this->data(visitCount: 2, prevVisitCount: 0, bounceRate: 50.0, avgTimeOnPage: 3), '', '', '', '', '');

        self::assertSame('', $result[2]['trend']);
        self::assertSame(TrendNote::NoPreviousVisits->value, $result[2]['trendNote']);
        self::assertSame('', $result[3]['trend']);
        self::assertSame(TrendNote::NoPreviousVisits->value, $result[3]['trendNote']);
    }

    #[Test]
    public function buildMetricItemsShowsRateTrendsFromZeroWithPreviousVisits(): void
    {
        $result = $this->subject->buildMetricItems($this->data(visitCount: 2, prevVisitCount: 4, bounceRate: 50.0, prevBounceRate: 0.0, avgTimeOnPage: 3, prevAvgTimeOnPage: 0), '', '', '', '', '');

        self::assertSame('+50.00%', $result[2]['trend']);
        self::assertSame('', $result[2]['trendNote']);
        self::assertSame('+3s', $result[3]['trend']);
        self::assertSame('', $result[3]['trendNote']);
    }

    #[Test]
    public function buildMetricItemsShowsNeutralTrendWhenValuesAreUnchanged(): void
    {
        $result = $this->subject->buildMetricItems(
            $this->data(visitCount: 10, prevVisitCount: 10, visitorCount: 8, prevVisitorCount: 8, bounceRate: 40.0, prevBounceRate: 40.0, avgTimeOnPage: 60, prevAvgTimeOnPage: 60),
            '',
            '',
            '',
            '',
            ''
        );

        self::assertSame(['±0', '±0', '±0.00%', '±0s'], array_column($result, 'trend'));
        self::assertSame(['neutral', 'neutral', 'neutral', 'neutral'], array_column($result, 'trendDirection'));
        self::assertSame(['', '', '', ''], array_column($result, 'trendNote'));
    }

    #[Test]
    public function buildMetricItemsBounceRateIsInvertedLowerIsBetter(): void
    {
        // bounce rate went down (good) → trend direction "up"
        $result = $this->subject->buildMetricItems($this->data(bounceRate: 30.0, prevBounceRate: 50.0), '', '', '', '', '');

        self::assertSame('up', $result[2]['trendDirection']);
    }

    #[Test]
    public function buildMetricItemsBounceRateInvertedHigherIsBad(): void
    {
        // bounce rate went up (bad) → trend direction "down"
        $result = $this->subject->buildMetricItems($this->data(bounceRate: 60.0, prevBounceRate: 40.0), '', '', '', '', '');

        self::assertSame('down', $result[2]['trendDirection']);
    }

    #[Test]
    public function buildMetricItemsIncludesTrendLabel(): void
    {
        $result = $this->subject->buildMetricItems($this->data(), '', '', '', '', 'Compared to previous period');

        self::assertSame('Compared to previous period', $result[0]['trendLabel']);
    }

    #[Test]
    public function buildMetricItemsUsesPercentagePointLabelForBounceRateTrend(): void
    {
        $result = $this->subject->buildMetricItems($this->data(), '', '', '', '', 'Compared to previous period', '', 'Percentage points compared to previous period');

        self::assertSame('Compared to previous period', $result[0]['trendLabel']);
        self::assertSame('Percentage points compared to previous period', $result[2]['trendLabel']);
        self::assertSame('Compared to previous period', $result[3]['trendLabel']);
    }

    /** buildMetricItems — series details */

    #[Test]
    public function buildMetricItemsHasNoDetailsWithoutSeries(): void
    {
        $result = $this->subject->buildMetricItems($this->data(), 'Visits', 'Visitors', 'Bounce rate', 'Avg. duration', '');

        foreach ($result as $item) {
            self::assertNull($item['details']);
            self::assertNull($item['chart']);
        }
    }

    #[Test]
    public function buildMetricItemsBuildsTodayYesterdayAndPeakFromSeries(): void
    {
        $data = $this->data() + ['series' => [
            'dates' => ['2026-10-04', '2026-10-05', '2026-10-06'],
            'visits' => [1200, 3400, 50],
            'visitors' => [10, 20, 30],
            'bounceRate' => [40.0, 55.5, 30.25],
            'avgTimeOnPage' => [65, 125, 90],
        ]];

        $result = $this->subject->buildMetricItems($data, 'Visits', 'Visitors', 'Bounce rate', 'Avg. duration', '', 'Trend');

        self::assertSame(['50', '3.400', '3.400'], $result[0]['details']);
        self::assertSame(['30', '20', '30'], $result[1]['details']);
        self::assertSame(['30.25%', '55.50%', '55.50%'], $result[2]['details']);
        self::assertSame(['1:30', '2:05', '2:05'], $result[3]['details']);
        self::assertSame([1200, 3400, 50], $result[0]['chart']['values'] ?? null);
        self::assertSame('Trend: Visits', $result[0]['chart']['label'] ?? null);
        self::assertSame('Trend: Avg. duration', $result[3]['chart']['label'] ?? null);
        // Point labels: date in the locale of the backend user (English without one) and the formatted value.
        self::assertSame(['Oct 4, 2026: 1:05', 'Oct 5, 2026: 2:05', 'Oct 6, 2026: 1:30'], $result[3]['chart']['labels'] ?? null);
    }

    /** loadPerformanceData */

    #[Test]
    public function loadPerformanceDataReturnsAllPartsAndCachesThem(): void
    {
        $series = ['dates' => ['2026-10-05', '2026-10-06'], 'visits' => [3, 4], 'visitors' => [2, 3], 'bounceRate' => [50.0, 25.0], 'avgTimeOnPage' => [30, 60]];
        $client = $this->createMock(AnalyticsDataClientInterface::class);
        $client->expects(self::once())->method('fetchSitePerformance')->willReturn($this->data(10, 5) + ['series' => $series, 'failures' => []]);
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);
        $cache->expects(self::once())->method('set')->with(self::anything(), self::anything(), [], null);

        $result = $this->subjectWith($client, $cache)->loadPerformanceData('main', 2);

        self::assertNotNull($result);
        self::assertSame(10, $result['current']['visitCount']);
        self::assertSame(5, $result['previous']['visitCount'] ?? null);
        self::assertSame($series, $result['series']);
    }

    #[Test]
    public function loadPerformanceDataReturnsCachedDataWithoutRequest(): void
    {
        $cached = $this->data(10, 5) + ['series' => ['dates' => [], 'visits' => [], 'visitors' => [], 'bounceRate' => [], 'avgTimeOnPage' => []]];
        $client = $this->createMock(AnalyticsDataClientInterface::class);
        $client->expects(self::never())->method('fetchSitePerformance');
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn($cached);

        self::assertSame($cached, $this->subjectWith($client, $cache)->loadPerformanceData('main', 7));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function currentFailures(): iterable
    {
        yield 'current sessions' => ['current'];
        yield 'current time on page' => ['currentTimeOnPage'];
    }

    #[Test]
    #[DataProvider('currentFailures')]
    public function loadPerformanceDataReturnsNullAndSkipsCacheWhenCurrentValuesFail(string $failedKey): void
    {
        $client = $this->createMock(AnalyticsDataClientInterface::class);
        $client->method('fetchSitePerformance')->willReturn($this->data(0, 5) + ['series' => $this->emptySeries(), 'failures' => [$failedKey => 'timeout']]);
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);
        $cache->expects(self::never())->method('set');

        self::assertNull($this->subjectWith($client, $cache)->loadPerformanceData('main', 7));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function previousFailures(): iterable
    {
        yield 'previous sessions' => ['previous'];
        yield 'previous time on page' => ['previousTimeOnPage'];
    }

    #[Test]
    #[DataProvider('previousFailures')]
    public function loadPerformanceDataDropsPreviousPeriodAndStillCachesWhenItFails(string $failedKey): void
    {
        $client = $this->createMock(AnalyticsDataClientInterface::class);
        $client->method('fetchSitePerformance')->willReturn($this->data(10, 0) + ['series' => $this->emptySeries(), 'failures' => [$failedKey => 'timeout']]);
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);
        $cache->expects(self::once())->method('set');

        $result = $this->subjectWith($client, $cache)->loadPerformanceData('main', 7);

        self::assertNotNull($result);
        self::assertSame(10, $result['current']['visitCount']);
        self::assertNull($result['previous']);
    }

    #[Test]
    public function loadPerformanceDataKeepsMetricsAndCachesEmptySeriesWhenSeriesFail(): void
    {
        $series = ['dates' => ['2026-10-05'], 'visits' => [3], 'visitors' => [2], 'bounceRate' => [50.0], 'avgTimeOnPage' => [30]];
        $client = $this->createMock(AnalyticsDataClientInterface::class);
        $client->method('fetchSitePerformance')->willReturn($this->data(10, 5) + ['series' => $series, 'failures' => ['series' => 'timeout']]);
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);
        $cache->expects(self::once())->method('set');

        $result = $this->subjectWith($client, $cache)->loadPerformanceData('main', 7);

        self::assertNotNull($result);
        self::assertSame(10, $result['current']['visitCount']);
        self::assertSame($this->emptySeries(), $result['series']);
    }

    #[Test]
    public function buildMetricItemsShowsNoTrendWithoutPreviousPeriod(): void
    {
        $data = $this->data(visitCount: 2, visitorCount: 1, bounceRate: 50.0, avgTimeOnPage: 3);
        $data['previous'] = null;

        $result = $this->subject->buildMetricItems($data, '', '', '', '', '');

        foreach ($result as $item) {
            self::assertSame('', $item['trend']);
            self::assertSame('neutral', $item['trendDirection']);
            self::assertSame(TrendNote::PreviousUnavailable->value, $item['trendNote']);
        }
    }

    /**
     * @return array{dates: list<string>, visits: list<int>, visitors: list<int>, bounceRate: list<float>, avgTimeOnPage: list<int>}
     */
    private function emptySeries(): array
    {
        return ['dates' => [], 'visits' => [], 'visitors' => [], 'bounceRate' => [], 'avgTimeOnPage' => []];
    }

    private function subjectWith(AnalyticsDataClientInterface $client, FrontendInterface $cache): SitePerformanceService
    {
        $siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);
        $siteProvider->method('resolveAnalyticsSite')->willReturn(['site' => new Site('main', 1, []), 'websiteId' => 'w', 'apiKey' => 'k']);
        return new SitePerformanceService($client, $this->createMock(LoggerInterface::class), $cache, new MetricFormatter(), $siteProvider);
    }

    /**
     * @return array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgTimeOnPage: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgTimeOnPage: int}
     * }
     */
    private function data(
        int $visitCount = 10,
        int $prevVisitCount = 0,
        int $visitorCount = 8,
        int $prevVisitorCount = 0,
        float $bounceRate = 0.0,
        float $prevBounceRate = 0.0,
        int $avgTimeOnPage = 60,
        int $prevAvgTimeOnPage = 0,
    ): array {
        return [
            'current' => ['visitCount' => $visitCount, 'visitorCount' => $visitorCount, 'bounceRate' => $bounceRate, 'avgTimeOnPage' => $avgTimeOnPage],
            'previous' => ['visitCount' => $prevVisitCount, 'visitorCount' => $prevVisitorCount, 'bounceRate' => $prevBounceRate, 'avgTimeOnPage' => $prevAvgTimeOnPage],
        ];
    }
}
