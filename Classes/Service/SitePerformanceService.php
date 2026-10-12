<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final readonly class SitePerformanceService implements SitePerformanceServiceInterface
{
    private const EMPTY_SERIES = ['dates' => [], 'visits' => [], 'visitors' => [], 'bounceRate' => [], 'avgTimeOnPage' => []];

    public function __construct(
        private AnalyticsDataClientInterface $analyticsClient,
        private LoggerInterface $logger,
        private FrontendInterface $cache,
        private MetricFormatterInterface $formatter,
        private AnalyticsSiteProviderInterface $siteProvider,
    ) {
    }

    /**
     * @return array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgTimeOnPage: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgTimeOnPage: int}|null,
     *     series: array{dates: list<string>, visits: list<int>, visitors: list<int>, bounceRate: list<float>, avgTimeOnPage: list<int>}
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

        /** @var array{current: array{visitCount: int, visitorCount: int, bounceRate: float, avgTimeOnPage: int}, previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgTimeOnPage: int}|null, series: array{dates: list<string>, visits: list<int>, visitors: list<int>, bounceRate: list<float>, avgTimeOnPage: list<int>}}|false $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $to = new \DateTimeImmutable('today 23:59:59');
        $from = $to->modify('-' . ($days - 1) . ' days');
        $prevTo = $from->modify('-1 day');
        $prevFrom = $prevTo->modify('-' . ($days - 1) . ' days');

        $result = $this->analyticsClient->fetchSitePerformance($websiteId, $apiKey, $from, $to, $prevFrom, $prevTo);
        $failures = $result['failures'];
        if ($failures !== []) {
            $this->logger->warning('SitePerformanceService: Failed to fetch performance data.', ['failures' => $failures]);
        }

        // Without the current values the widget has nothing to show. Nothing is cached, so the next request tries again.
        if (isset($failures['current']) || isset($failures['currentTimeOnPage'])) {
            return null;
        }

        $data = [
            'current' => $result['current'],
            // A failed previous period has no values to compare with, so the metrics show no trend.
            'previous' => isset($failures['previous']) || isset($failures['previousTimeOnPage']) ? null : $result['previous'],
            'series' => isset($failures['series']) ? self::EMPTY_SERIES : $result['series'],
        ];
        // A partial result is cached as well, so a lasting failure of one request does not hit the API on every widget load.
        $this->cache->set($cacheKey, $data);
        return $data;
    }

    /**
     * @param array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgTimeOnPage: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgTimeOnPage: int}|null,
     *     series?: array{dates: list<string>, visits: list<int>, visitors: list<int>, bounceRate: list<float>, avgTimeOnPage: list<int>}
     * } $data
     * @return list<array{label: string, value: string, tone: string, icon: string, trend: string, trendDirection: string, trendLabel: string, trendNote: string, details: list<string>|null, chart: array{values: list<int|float>, labels: list<string>, label: string}|null}>
     */
    public function buildMetricItems(array $data, string $visitsLabel, string $visitorsLabel, string $bounceRateLabel, string $avgTimeOnPageLabel, string $trendLabel, string $chartLabel = '', string $percentagePointTrendLabel = ''): array
    {
        $current = $data['current'];
        // Without a previous period, the current values stand in for it: the note replaces the trend, the direction is neutral.
        $previous = $data['previous'] ?? $current;
        $series = ($data['series'] ?? []) + self::EMPTY_SERIES;
        // The note replaces the trend and tells why there is none. Counts compare with 0 visits as a measured value.
        // The bounce rate and the time on page of a period without visits are 0 by default, not measured values.
        $countNote = $data['previous'] === null ? TrendNote::PreviousUnavailable->value : '';
        $rateNote = match (true) {
            $data['previous'] === null => TrendNote::PreviousUnavailable->value,
            $previous['visitCount'] === 0 => TrendNote::NoPreviousVisits->value,
            default => '',
        };

        return [
            [
                'label' => $visitsLabel,
                'value' => $this->formatter->formatNumber($current['visitCount']),
                'tone' => 'visits',
                'icon' => 'eye',
                'trend' => $countNote === '' ? $this->formatter->formatAbsoluteCountTrend($current['visitCount'], $previous['visitCount']) : '',
                'trendNote' => $countNote,
                'trendDirection' => $this->formatter->trendDirection((float)$current['visitCount'], (float)$previous['visitCount']),
                'trendLabel' => $trendLabel,
                'details' => $this->buildSeriesDetails($series['visits'], $this->formatCount(...)),
                'chart' => $this->buildChart($series['visits'], $series['dates'], $chartLabel . ': ' . $visitsLabel, $this->formatCount(...)),
            ],
            [
                'label' => $visitorsLabel,
                'value' => $this->formatter->formatNumber($current['visitorCount']),
                'tone' => 'visitors',
                'icon' => 'circle-plus',
                'trend' => $countNote === '' ? $this->formatter->formatAbsoluteCountTrend($current['visitorCount'], $previous['visitorCount']) : '',
                'trendNote' => $countNote,
                'trendDirection' => $this->formatter->trendDirection((float)$current['visitorCount'], (float)$previous['visitorCount']),
                'trendLabel' => $trendLabel,
                'details' => $this->buildSeriesDetails($series['visitors'], $this->formatCount(...)),
                'chart' => $this->buildChart($series['visitors'], $series['dates'], $chartLabel . ': ' . $visitorsLabel, $this->formatCount(...)),
            ],
            [
                'label' => $bounceRateLabel,
                'value' => $this->formatter->formatPercentage($current['bounceRate']),
                'tone' => 'bounce-rate',
                'icon' => 'arrow-right-from-bracket',
                'trend' => $rateNote === '' ? $this->formatter->formatAbsolutePercentPointTrend($current['bounceRate'], $previous['bounceRate']) : '',
                'trendNote' => $rateNote,
                'trendDirection' => $this->formatter->trendDirection($previous['bounceRate'], $current['bounceRate']),
                'trendLabel' => $percentagePointTrendLabel !== '' ? $percentagePointTrendLabel : $trendLabel,
                'details' => $this->buildSeriesDetails($series['bounceRate'], $this->formatter->formatPercentage(...)),
                'chart' => $this->buildChart($series['bounceRate'], $series['dates'], $chartLabel . ': ' . $bounceRateLabel, $this->formatter->formatPercentage(...)),
            ],
            [
                'label' => $avgTimeOnPageLabel,
                'value' => $this->formatter->formatDuration($current['avgTimeOnPage']),
                'tone' => 'avg-duration',
                'icon' => 'clock',
                'trend' => $rateNote === '' ? $this->formatter->formatAbsoluteDurationTrend($current['avgTimeOnPage'], $previous['avgTimeOnPage']) : '',
                'trendNote' => $rateNote,
                'trendDirection' => $this->formatter->trendDirection((float)$current['avgTimeOnPage'], (float)$previous['avgTimeOnPage']),
                'trendLabel' => $trendLabel,
                'details' => $this->buildSeriesDetails($series['avgTimeOnPage'], $this->formatDuration(...)),
                'chart' => $this->buildChart($series['avgTimeOnPage'], $series['dates'], $chartLabel . ': ' . $avgTimeOnPageLabel, $this->formatDuration(...)),
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
     * Values and point labels of a sparkline. The template draws it, see SitePerformanceMetrics.html.
     *
     * @param list<int|float> $values
     * @param list<string> $dates
     * @param \Closure(int|float): string $format
     * @return array{values: list<int|float>, labels: list<string>, label: string}|null
     */
    private function buildChart(array $values, array $dates, string $label, \Closure $format): ?array
    {
        if (count($values) < 2) {
            return null;
        }
        $labels = [];
        foreach ($values as $index => $value) {
            $date = isset($dates[$index]) ? $this->formatter->formatDate(new \DateTimeImmutable($dates[$index])) . ': ' : '';
            $labels[] = $date . $format($value);
        }
        return ['values' => $values, 'labels' => $labels, 'label' => $label];
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
