<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final readonly class SitePerformanceService implements SitePerformanceServiceInterface
{
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
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int}
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

        $cacheKey = 'site_performance_' . md5($websiteId . '_' . $days);

        /** @var array{current: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int}, previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int}}|false $cached */
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
            ];
            $this->cache->set($cacheKey, $data);
            return $data;
        } catch (AnalyticsApiException $e) {
            $this->logger->warning('SitePerformanceService: Failed to fetch performance data.', ['reason' => $e->reason]);
            return null;
        }
    }

    /**
     * @param array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int}
     * } $data
     * @return list<array{label: string, value: string, tone: string, icon: string, trend: string, trendDirection: string, trendLabel: string}>
     */
    public function buildMetricItems(array $data, string $visitsLabel, string $visitorsLabel, string $bounceRateLabel, string $avgDurationLabel, string $trendLabel): array
    {
        $current = $data['current'];
        $previous = $data['previous'];

        return [
            [
                'label' => $visitsLabel,
                'value' => $this->formatter->formatNumber($current['visitCount']),
                'tone' => 'visits',
                'icon' => 'eye',
                'trend' => $this->formatter->formatRelativeTrend((float)$current['visitCount'], (float)$previous['visitCount']),
                'trendDirection' => $this->formatter->trendDirection((float)$current['visitCount'], (float)$previous['visitCount']),
                'trendLabel' => $trendLabel,
            ],
            [
                'label' => $visitorsLabel,
                'value' => $this->formatter->formatNumber($current['visitorCount']),
                'tone' => 'visitors',
                'icon' => 'circle-plus',
                'trend' => $this->formatter->formatRelativeTrend((float)$current['visitorCount'], (float)$previous['visitorCount']),
                'trendDirection' => $this->formatter->trendDirection((float)$current['visitorCount'], (float)$previous['visitorCount']),
                'trendLabel' => $trendLabel,
            ],
            [
                'label' => $bounceRateLabel,
                'value' => $this->formatter->formatPercentage($current['bounceRate']),
                'tone' => 'bounce-rate',
                'icon' => 'arrow-right-from-bracket',
                'trend' => $this->formatter->formatRelativeTrend($previous['bounceRate'], $current['bounceRate']),
                'trendDirection' => $this->formatter->trendDirection($previous['bounceRate'], $current['bounceRate']),
                'trendLabel' => $trendLabel,
            ],
            [
                'label' => $avgDurationLabel,
                'value' => $this->formatter->formatDuration($current['avgDuration']),
                'tone' => 'avg-duration',
                'icon' => 'clock',
                'trend' => $this->formatter->formatRelativeTrend((float)$current['avgDuration'], (float)$previous['avgDuration']),
                'trendDirection' => $this->formatter->trendDirection((float)$current['avgDuration'], (float)$previous['avgDuration']),
                'trendLabel' => $trendLabel,
            ],
        ];
    }
}
