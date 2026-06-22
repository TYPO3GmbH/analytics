<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final readonly class TrafficGraphService implements TrafficGraphServiceInterface
{
    public function __construct(
        private AnalyticsDataClientInterface $analyticsClient,
        private LoggerInterface $logger,
        private FrontendInterface $cache,
        private AnalyticsSiteProviderInterface $siteProvider,
    ) {
    }

    /**
     * @return array{labels: list<string>, data: list<int>}|null
     */
    public function loadGraphData(string $siteIdentifier, int $days): ?array
    {
        $siteData = $this->siteProvider->resolveAnalyticsSite($siteIdentifier);
        if ($siteData === null) {
            return null;
        }

        $days = max(1, $days);
        $websiteId = $siteData['websiteId'];
        $apiKey = $siteData['apiKey'];

        // Cache key has no date component — partial-day data is served from cache until TTL.
        // Consistent with TopPagesService and SitePerformanceService.
        $cacheKey = 'traffic_graph_' . md5($websiteId . '_' . $days);

        /** @var array{labels: list<string>, data: list<int>}|false $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $to = new \DateTimeImmutable('today 23:59:59');
        // $from inherits the 23:59:59 time, but fetchSiteVisitsGraph formats dates with a
        // hardcoded T00:00:00 pattern so only the date portion reaches the API.
        $from = $to->modify('-' . ($days - 1) . ' days');

        try {
            $result = $this->analyticsClient->fetchSiteVisitsGraph($websiteId, $apiKey, $from, $to);
            $data = [
                'labels' => $result['labels'] ?? [],
                'data' => $result['datasets'][0]['data'] ?? [],
            ];
            $this->cache->set($cacheKey, $data);
            return $data;
        } catch (AnalyticsApiException $e) {
            $this->logger->warning('TrafficGraphService: Failed to fetch graph data.', ['reason' => $e->reason]);
            return null;
        }
    }

    public function loadSessionsData(string $siteIdentifier, int $days): ?array
    {
        $siteData = $this->siteProvider->resolveAnalyticsSite($siteIdentifier);
        if ($siteData === null) {
            return null;
        }

        $days = max(1, $days);
        $websiteId = $siteData['websiteId'];
        $apiKey = $siteData['apiKey'];

        $cacheKey = 'traffic_sessions_' . md5($websiteId . '_' . $days);

        /** @var array{labels: list<string>, data: list<int>}|false $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $to = new \DateTimeImmutable('today 23:59:59');
        $from = $to->modify('-' . ($days - 1) . ' days');

        try {
            $result = $this->analyticsClient->fetchSiteSessionsGraph($websiteId, $apiKey, $from, $to);
            $data = [
                'labels' => $result['labels'] ?? [],
                'data' => $result['datasets'][0]['data'] ?? [],
            ];
            $this->cache->set($cacheKey, $data);
            return $data;
        } catch (AnalyticsApiException $e) {
            $this->logger->warning('TrafficGraphService: Failed to fetch sessions data.', ['reason' => $e->reason]);
            return null;
        }
    }

    public function loadVisitorsData(string $siteIdentifier, int $days): ?array
    {
        $siteData = $this->siteProvider->resolveAnalyticsSite($siteIdentifier);
        if ($siteData === null) {
            return null;
        }

        $days = max(1, $days);
        $websiteId = $siteData['websiteId'];
        $apiKey = $siteData['apiKey'];

        $cacheKey = 'traffic_visitors_' . md5($websiteId . '_' . $days);

        /** @var array{labels: list<string>, data: list<int>}|false $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $to = new \DateTimeImmutable('today 23:59:59');
        $from = $to->modify('-' . ($days - 1) . ' days');

        try {
            $result = $this->analyticsClient->fetchSiteVisitorsGraph($websiteId, $apiKey, $from, $to);
            $data = [
                'labels' => $result['labels'] ?? [],
                'data' => $result['datasets'][0]['data'] ?? [],
            ];
            $this->cache->set($cacheKey, $data);
            return $data;
        } catch (AnalyticsApiException $e) {
            $this->logger->warning('TrafficGraphService: Failed to fetch visitors data.', ['reason' => $e->reason]);
            return null;
        }
    }
}
