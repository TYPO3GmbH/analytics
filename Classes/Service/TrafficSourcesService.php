<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final readonly class TrafficSourcesService implements TrafficSourcesServiceInterface
{
    public function __construct(
        private AnalyticsDataClientInterface $analyticsClient,
        private LoggerInterface $logger,
        private FrontendInterface $cache,
        private AnalyticsSiteProviderInterface $siteProvider,
    ) {
    }

    /**
     * @return array<string, array{current: int, previous: int}>|null
     */
    public function loadTrafficSources(string $siteIdentifier, int $days): ?array
    {
        $siteData = $this->siteProvider->resolveAnalyticsSite($siteIdentifier);
        if ($siteData === null) {
            return null;
        }

        $days = max(1, $days);
        $websiteId = $siteData['websiteId'];
        $apiKey = $siteData['apiKey'];

        $cacheKey = 'traffic_sources_' . md5($websiteId . '_' . $days);

        /** @var array<string, array{current: int, previous: int}>|false $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $to = new \DateTimeImmutable('today 23:59:59');
        $from = $to->modify('-' . ($days - 1) . ' days');
        $previousTo = $from->modify('-1 day');
        $previousFrom = $previousTo->modify('-' . ($days - 1) . ' days');

        try {
            $currentResult = $this->analyticsClient->fetchTrafficShareInDepth($websiteId, $apiKey, $from, $to);
            $data = [];
            foreach ($currentResult['datasets'] as $dataset) {
                $label = (string)($dataset['label'] ?? '');
                if ($label !== '') {
                    $data[$label] = ['current' => (int)($dataset['total'] ?? 0), 'previous' => 0];
                }
            }

            try {
                $previousResult = $this->analyticsClient->fetchTrafficShareInDepth($websiteId, $apiKey, $previousFrom, $previousTo);
                foreach ($previousResult['datasets'] as $dataset) {
                    $label = (string)($dataset['label'] ?? '');
                    if ($label !== '' && isset($data[$label])) {
                        $data[$label]['previous'] = (int)($dataset['total'] ?? 0);
                    }
                }
            } catch (AnalyticsApiException $e) {
                $this->logger->warning('TrafficSourcesService: Failed to fetch previous traffic sources.', ['reason' => $e->reason]);
            }

            $this->cache->set($cacheKey, $data);
            return $data;
        } catch (AnalyticsApiException $e) {
            $this->logger->warning('TrafficSourcesService: Failed to fetch traffic sources.', ['reason' => $e->reason]);
            return null;
        }
    }

    /**
     * @return list<array{deviceType: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}>|null
     */
    public function loadDeviceData(string $siteIdentifier, int $days): ?array
    {
        /** @var list<array{deviceType: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}>|null $result */
        $result = $this->loadSessionData(
            $siteIdentifier,
            $days,
            'traffic_devices_',
            fn (\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $pFrom, \DateTimeImmutable $pTo, string $wid, string $key): array
                => $this->analyticsClient->fetchDeviceSessions($wid, $key, $from, $to, $pFrom, $pTo),
        );
        return $result;
    }

    /**
     * @return list<array{browserName: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}>|null
     */
    public function loadBrowserData(string $siteIdentifier, int $days): ?array
    {
        /** @var list<array{browserName: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}>|null $result */
        $result = $this->loadSessionData(
            $siteIdentifier,
            $days,
            'traffic_browsers_',
            fn (\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $pFrom, \DateTimeImmutable $pTo, string $wid, string $key): array
                => $this->analyticsClient->fetchBrowserSessions($wid, $key, $from, $to, $pFrom, $pTo),
        );
        return $result;
    }

    /**
     * @return list<array{countryCode: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}>|null
     */
    public function loadCountryData(string $siteIdentifier, int $days): ?array
    {
        /** @var list<array{countryCode: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}>|null $result */
        $result = $this->loadSessionData(
            $siteIdentifier,
            $days,
            'traffic_countries_',
            fn (\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $pFrom, \DateTimeImmutable $pTo, string $wid, string $key): array
                => $this->analyticsClient->fetchCountrySessions($wid, $key, $from, $to, $pFrom, $pTo),
        );
        return $result;
    }

    /**
     * @param callable(\DateTimeImmutable, \DateTimeImmutable, \DateTimeImmutable, \DateTimeImmutable, string, string): list<array<string, mixed>> $fetcher
     * @return list<array<string, mixed>>|null
     */
    private function loadSessionData(string $siteIdentifier, int $days, string $cacheKeyPrefix, callable $fetcher): ?array
    {
        $siteData = $this->siteProvider->resolveAnalyticsSite($siteIdentifier);
        if ($siteData === null) {
            return null;
        }

        $days = max(1, $days);
        $websiteId = $siteData['websiteId'];
        $apiKey = $siteData['apiKey'];

        $cacheKey = $cacheKeyPrefix . md5($websiteId . '_' . $days);

        /** @var list<array<string, mixed>>|false $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $to = new \DateTimeImmutable('today 23:59:59');
        $from = $to->modify('-' . ($days - 1) . ' days');
        $previousTo = $from->modify('-1 day');
        $previousFrom = $previousTo->modify('-' . ($days - 1) . ' days');

        try {
            $data = $fetcher($from, $to, $previousFrom, $previousTo, $websiteId, $apiKey);
            $this->cache->set($cacheKey, $data);
            return $data;
        } catch (AnalyticsApiException $e) {
            $this->logger->warning('TrafficSourcesService: Failed to fetch session data.', ['reason' => $e->reason]);
            return null;
        }
    }
}
