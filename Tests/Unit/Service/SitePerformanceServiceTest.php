<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Service\AnalyticsDataClientInterface;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\MetricFormatter;
use T3G\Analytics\Service\SitePerformanceService;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
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
    public function buildMetricItemsFormatsAvgDurationAsMinutesAndSeconds(): void
    {
        $result = $this->subject->buildMetricItems($this->data(avgDuration: 125), '', '', '', '', '');

        self::assertSame('2:05', $result[3]['value']);
    }

    #[Test]
    public function buildMetricItemsFormatsZeroAvgDurationAsZeroZero(): void
    {
        $result = $this->subject->buildMetricItems($this->data(avgDuration: 0), '', '', '', '', '');

        self::assertSame('0:00', $result[3]['value']);
    }

    /** buildMetricItems — trend direction */

    #[Test]
    public function buildMetricItemsShowsUpTrendWhenVisitsIncreased(): void
    {
        $result = $this->subject->buildMetricItems($this->data(visitCount: 20, prevVisitCount: 10), '', '', '', '', '');

        self::assertSame('up', $result[0]['trendDirection']);
        self::assertSame('+100.00%', $result[0]['trend']);
    }

    #[Test]
    public function buildMetricItemsShowsDownTrendWhenVisitsDecreased(): void
    {
        $result = $this->subject->buildMetricItems($this->data(visitCount: 5, prevVisitCount: 10), '', '', '', '', '');

        self::assertSame('down', $result[0]['trendDirection']);
        self::assertSame('-50.00%', $result[0]['trend']);
    }

    #[Test]
    public function buildMetricItemsHasEmptyTrendStringWhenPreviousVisitsIsZero(): void
    {
        $result = $this->subject->buildMetricItems($this->data(visitCount: 10, prevVisitCount: 0), '', '', '', '', '');

        self::assertSame('', $result[0]['trend']);
    }

    #[Test]
    public function buildMetricItemsIsNeutralWhenCurrentAndPreviousAreEqual(): void
    {
        $result = $this->subject->buildMetricItems($this->data(visitCount: 10, prevVisitCount: 10), '', '', '', '', '');

        self::assertSame('', $result[0]['trend']);
        self::assertSame('neutral', $result[0]['trendDirection']);
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

    /**
     * @return array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int}
     * }
     */
    private function data(
        int $visitCount = 10,
        int $prevVisitCount = 0,
        int $visitorCount = 8,
        int $prevVisitorCount = 0,
        float $bounceRate = 0.0,
        float $prevBounceRate = 0.0,
        int $avgDuration = 60,
        int $prevAvgDuration = 0,
    ): array {
        return [
            'current' => ['visitCount' => $visitCount, 'visitorCount' => $visitorCount, 'bounceRate' => $bounceRate, 'avgDuration' => $avgDuration],
            'previous' => ['visitCount' => $prevVisitCount, 'visitorCount' => $prevVisitorCount, 'bounceRate' => $prevBounceRate, 'avgDuration' => $prevAvgDuration],
        ];
    }
}
