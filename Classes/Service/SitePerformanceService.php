<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\View\SparklineRenderer;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final readonly class SitePerformanceService implements SitePerformanceServiceInterface
{
    private const EMPTY_SERIES = ['dates' => [], 'visits' => [], 'visitors' => [], 'bounceRate' => [], 'avgDuration' => []];

    public function __construct(
        private AnalyticsDataClientInterface $analyticsClient,
        private LoggerInterface $logger,
        private FrontendInterface $cache,
        private MetricFormatterInterface $formatter,
        private AnalyticsSiteProviderInterface $siteProvider,
        private SparklineRenderer $sparklineRenderer,
    ) {
    }

    /**
     * @return array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     series: array{dates: list<string>, visits: list<int>, visitors: list<int>, bounceRate: list<float>, avgDuration: list<int>}
     * }|null
     */
    public function loadPerformanceData(string $siteIdentifier, int $days): ?array
    {
        $siteData = $this->siteProvider->resolveAnalyticsSite($siteIdentifier);
        if ($siteData === null) {
            return null;
        }

        $days = max(1, $days);
        $websiteId = $siteData['websiteId'];
        $apiKey = $siteData['apiKey'];

        $cacheKey = 'site_performance_v3_' . md5($websiteId . '_' . $days);

        /** @var array{current: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int}, previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int}, series: array{dates: list<string>, visits: list<int>, visitors: list<int>, bounceRate: list<float>, avgDuration: list<int>}}|false $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $to = new \DateTimeImmutable('today 23:59:59');
        $from = $to->modify('-' . ($days - 1) . ' days');
        $prevTo = $from->modify('-1 day');
        $prevFrom = $prevTo->modify('-' . ($days - 1) . ' days');

        try {
            $result = $this->analyticsClient->fetchSitePerformance($websiteId, $apiKey, $from, $to, $prevFrom, $prevTo);
        } catch (AnalyticsApiException $e) {
            $this->logger->warning('SitePerformanceService: Failed to fetch performance data.', ['reason' => $e->reason]);
            return null;
        }

        $series = $this->loadSeries($websiteId, $apiKey, $from, $to);
        $data = [
            'current' => $result['current'],
            'previous' => $result['previous'],
            'series' => $series ?? self::EMPTY_SERIES,
        ];
        // A failed series is not cached, so the details return with the next request instead of after the cache lifetime.
        if ($series !== null) {
            $this->cache->set($cacheKey, $data);
        }
        return $data;
    }

    /**
     * Daily values for the metric details. A failure here only drops the details,
     * the aggregated metrics are still shown.
     *
     * @return array{dates: list<string>, visits: list<int>, visitors: list<int>, bounceRate: list<float>, avgDuration: list<int>}|null
     */
    private function loadSeries(string $websiteId, string $apiKey, \DateTimeImmutable $from, \DateTimeImmutable $to): ?array
    {
        try {
            return $this->analyticsClient->fetchSiteDailyMetrics($websiteId, $apiKey, $from, $to);
        } catch (AnalyticsApiException $e) {
            $this->logger->warning('SitePerformanceService: Failed to fetch time series.', ['reason' => $e->reason]);
            return null;
        }
    }

    /**
     * @param array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     series?: array{dates: list<string>, visits: list<int>, visitors: list<int>, bounceRate: list<float>, avgDuration: list<int>}
     * } $data
     * @return list<array{label: string, value: string, tone: string, icon: string, trend: string, trendDirection: string, trendLabel: string, details: list<string>|null, sparkline: string}>
     */
    public function buildMetricItems(array $data, string $visitsLabel, string $visitorsLabel, string $bounceRateLabel, string $avgDurationLabel, string $trendLabel, string $chartLabel = ''): array
    {
        $current = $data['current'];
        $previous = $data['previous'];
        $series = ($data['series'] ?? []) + self::EMPTY_SERIES;

        return [
            [
                'label' => $visitsLabel,
                'value' => $this->formatter->formatNumber($current['visitCount']),
                'tone' => 'visits',
                'icon' => 'eye',
                'trend' => $this->formatter->formatAbsoluteCountTrend($current['visitCount'], $previous['visitCount']),
                'trendDirection' => $this->formatter->trendDirection((float)$current['visitCount'], (float)$previous['visitCount']),
                'trendLabel' => $trendLabel,
                'details' => $this->buildSeriesDetails($series['visits'], $this->formatCount(...)),
                'sparkline' => $this->renderSparkline($series['visits'], $series['dates'], 'visits', $chartLabel . ': ' . $visitsLabel, $this->formatCount(...)),
            ],
            [
                'label' => $visitorsLabel,
                'value' => $this->formatter->formatNumber($current['visitorCount']),
                'tone' => 'visitors',
                'icon' => 'circle-plus',
                'trend' => $this->formatter->formatAbsoluteCountTrend($current['visitorCount'], $previous['visitorCount']),
                'trendDirection' => $this->formatter->trendDirection((float)$current['visitorCount'], (float)$previous['visitorCount']),
                'trendLabel' => $trendLabel,
                'details' => $this->buildSeriesDetails($series['visitors'], $this->formatCount(...)),
                'sparkline' => $this->renderSparkline($series['visitors'], $series['dates'], 'visitors', $chartLabel . ': ' . $visitorsLabel, $this->formatCount(...)),
            ],
            [
                'label' => $bounceRateLabel,
                'value' => $this->formatter->formatPercentage($current['bounceRate']),
                'tone' => 'bounce-rate',
                'icon' => 'arrow-right-from-bracket',
                'trend' => $this->formatter->formatAbsolutePercentPointTrend($current['bounceRate'], $previous['bounceRate']),
                'trendDirection' => $this->formatter->trendDirection($previous['bounceRate'], $current['bounceRate']),
                'trendLabel' => $trendLabel,
                'details' => $this->buildSeriesDetails($series['bounceRate'], $this->formatter->formatPercentage(...)),
                'sparkline' => $this->renderSparkline($series['bounceRate'], $series['dates'], 'bounce-rate', $chartLabel . ': ' . $bounceRateLabel, $this->formatter->formatPercentage(...)),
            ],
            [
                'label' => $avgDurationLabel,
                'value' => $this->formatter->formatDuration($current['avgDuration']),
                'tone' => 'avg-duration',
                'icon' => 'clock',
                'trend' => $this->formatter->formatAbsoluteDurationTrend($current['avgDuration'], $previous['avgDuration']),
                'trendDirection' => $this->formatter->trendDirection((float)$current['avgDuration'], (float)$previous['avgDuration']),
                'trendLabel' => $trendLabel,
                'details' => $this->buildSeriesDetails($series['avgDuration'], $this->formatDuration(...)),
                'sparkline' => $this->renderSparkline($series['avgDuration'], $series['dates'], 'avg-duration', $chartLabel . ': ' . $avgDurationLabel, $this->formatDuration(...)),
            ],
        ];
    }

    /**
     * Today, yesterday and peak of a daily series, formatted like the metric value.
     *
     * @param list<int|float> $values
     * @param \Closure(int|float): string $format
     * @return list<string>|null
     */
    private function buildSeriesDetails(array $values, \Closure $format): ?array
    {
        if ($values === []) {
            return null;
        }
        $count = count($values);
        return [
            $format($values[$count - 1]),
            $count >= 2 ? $format($values[$count - 2]) : '-',
            $format(max($values)),
        ];
    }

    /**
     * @param list<int|float> $values
     * @param list<string> $dates
     * @param \Closure(int|float): string $format
     */
    private function renderSparkline(array $values, array $dates, string $tone, string $label, \Closure $format): string
    {
        if (count($values) < 2) {
            return '';
        }
        $labels = [];
        foreach ($values as $index => $value) {
            $date = isset($dates[$index]) ? (new \DateTimeImmutable($dates[$index]))->format('d.m.Y') . ': ' : '';
            $labels[] = $date . $format($value);
        }
        return $this->sparklineRenderer->render($values, [
            'label' => $label,
            'class' => 'tx-analytics-site-performance-sparkline',
            'tone' => $tone,
            'labels' => $labels,
            'smooth' => true,
            'axes' => true,
            // The bottom axis is drawn at yMin, so it has to be zero to read as one.
            'yMin' => 0.0,
        ]);
    }

    private function formatCount(int|float $value): string
    {
        return $this->formatter->formatNumber((int)$value);
    }

    private function formatDuration(int|float $value): string
    {
        return $this->formatter->formatDuration((int)$value);
    }
}
