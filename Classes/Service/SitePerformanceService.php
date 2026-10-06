<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\View\SparklineRenderer;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final readonly class SitePerformanceService implements SitePerformanceServiceInterface
{
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
     *     series: array{dates: list<string>, visits: list<int>, visitors: list<int>}
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

        $cacheKey = 'site_performance_v2_' . md5($websiteId . '_' . $days);

        /** @var array{current: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int}, previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int}, series: array{dates: list<string>, visits: list<int>, visitors: list<int>}}|false $cached */
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
            $data = [
                'current' => $result['current'],
                'previous' => $result['previous'],
                'series' => $this->loadSeries($websiteId, $apiKey, $from, $to),
            ];
            $this->cache->set($cacheKey, $data);
            return $data;
        } catch (AnalyticsApiException $e) {
            $this->logger->warning('SitePerformanceService: Failed to fetch performance data.', ['reason' => $e->reason]);
            return null;
        }
    }

    /**
     * Daily values for the details of visits and visitors. A failure here only drops
     * the details, the aggregated metrics are still shown.
     *
     * @return array{dates: list<string>, visits: list<int>, visitors: list<int>}
     */
    private function loadSeries(string $websiteId, string $apiKey, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $series = ['dates' => [], 'visits' => [], 'visitors' => []];
        try {
            $visits = $this->analyticsClient->fetchSiteVisitsGraph($websiteId, $apiKey, $from, $to);
            $series['dates'] = $visits['labels'];
            $series['visits'] = $visits['datasets'][0]['data'] ?? [];

            // Dataset order of the visitors graph: new, returning, overall.
            $visitors = $this->analyticsClient->fetchSiteVisitorsGraph($websiteId, $apiKey, $from, $to);
            $series['visitors'] = $visitors['datasets'][2]['data'] ?? [];
        } catch (AnalyticsApiException $e) {
            $this->logger->warning('SitePerformanceService: Failed to fetch time series.', ['reason' => $e->reason]);
        }
        return $series;
    }

    /**
     * @param array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     series?: array{dates: list<string>, visits: list<int>, visitors: list<int>}
     * } $data
     * @return list<array{label: string, value: string, tone: string, icon: string, trend: string, trendDirection: string, trendLabel: string, details: list<string>|null, sparkline: string}>
     */
    public function buildMetricItems(array $data, string $visitsLabel, string $visitorsLabel, string $bounceRateLabel, string $avgDurationLabel, string $trendLabel, string $chartLabel = ''): array
    {
        $current = $data['current'];
        $previous = $data['previous'];
        $series = $data['series'] ?? ['dates' => [], 'visits' => [], 'visitors' => []];

        return [
            [
                'label' => $visitsLabel,
                'value' => $this->formatter->formatNumber($current['visitCount']),
                'tone' => 'visits',
                'icon' => 'eye',
                'trend' => $this->formatter->formatAbsoluteCountTrend($current['visitCount'], $previous['visitCount']),
                'trendDirection' => $this->formatter->trendDirection((float)$current['visitCount'], (float)$previous['visitCount']),
                'trendLabel' => $trendLabel,
                'details' => $this->buildSeriesDetails($series['visits']),
                'sparkline' => $this->renderSparkline($series['visits'], $series['dates'], 'visits', $chartLabel . ': ' . $visitsLabel),
            ],
            [
                'label' => $visitorsLabel,
                'value' => $this->formatter->formatNumber($current['visitorCount']),
                'tone' => 'visitors',
                'icon' => 'circle-plus',
                'trend' => $this->formatter->formatAbsoluteCountTrend($current['visitorCount'], $previous['visitorCount']),
                'trendDirection' => $this->formatter->trendDirection((float)$current['visitorCount'], (float)$previous['visitorCount']),
                'trendLabel' => $trendLabel,
                'details' => $this->buildSeriesDetails($series['visitors']),
                'sparkline' => $this->renderSparkline($series['visitors'], $series['dates'], 'visitors', $chartLabel . ': ' . $visitorsLabel),
            ],
            [
                'label' => $bounceRateLabel,
                'value' => $this->formatter->formatPercentage($current['bounceRate']),
                'tone' => 'bounce-rate',
                'icon' => 'arrow-right-from-bracket',
                'trend' => $this->formatter->formatAbsolutePercentPointTrend($current['bounceRate'], $previous['bounceRate']),
                'trendDirection' => $this->formatter->trendDirection($previous['bounceRate'], $current['bounceRate']),
                'trendLabel' => $trendLabel,
                'details' => null,
                'sparkline' => '',
            ],
            [
                'label' => $avgDurationLabel,
                'value' => $this->formatter->formatDuration($current['avgDuration']),
                'tone' => 'avg-duration',
                'icon' => 'clock',
                'trend' => $this->formatter->formatAbsoluteDurationTrend($current['avgDuration'], $previous['avgDuration']),
                'trendDirection' => $this->formatter->trendDirection((float)$current['avgDuration'], (float)$previous['avgDuration']),
                'trendLabel' => $trendLabel,
                'details' => null,
                'sparkline' => '',
            ],
        ];
    }

    /**
     * Today, yesterday and peak of a daily series, formatted like the metric value.
     *
     * @param list<int> $values
     * @return list<string>|null
     */
    private function buildSeriesDetails(array $values): ?array
    {
        if ($values === []) {
            return null;
        }
        $count = count($values);
        return [
            $this->formatter->formatNumber($values[$count - 1]),
            $count >= 2 ? $this->formatter->formatNumber($values[$count - 2]) : '-',
            $this->formatter->formatNumber(max($values)),
        ];
    }

    /**
     * @param list<int> $values
     * @param list<string> $dates
     */
    private function renderSparkline(array $values, array $dates, string $tone, string $label): string
    {
        if (count($values) < 2) {
            return '';
        }
        $labels = [];
        foreach ($values as $index => $value) {
            $date = isset($dates[$index]) ? (new \DateTimeImmutable($dates[$index]))->format('d.m.Y') . ': ' : '';
            $labels[] = $date . $this->formatter->formatNumber($value);
        }
        return $this->sparklineRenderer->render($values, [
            'label' => $label,
            'class' => 'tx-analytics-site-performance-sparkline',
            'tone' => $tone,
            'labels' => $labels,
            'smooth' => true,
            'axes' => true,
        ]);
    }
}
