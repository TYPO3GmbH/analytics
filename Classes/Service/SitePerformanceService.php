<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

final readonly class SitePerformanceService implements SitePerformanceServiceInterface
{
    public function __construct(
        private SiteFinder $siteFinder,
        private AnalyticsDataClientInterface $analyticsClient,
        private CipherServiceInterface $cipherService,
        private LoggerInterface $logger,
        private FrontendInterface $cache,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function siteOptions(): array
    {
        $options = [];

        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                if (!$this->isAnalyticsSite($site)) {
                    continue;
                }
                if (!$this->userCanAccessPage($site->getRootPageId())) {
                    continue;
                }
                $siteTitle = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));
                $options[$site->getIdentifier()] = $siteTitle === ''
                    ? $site->getIdentifier()
                    : $siteTitle . ' (' . $site->getIdentifier() . ')';
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load sites for site performance widget: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        return $options;
    }

    /**
     * @return array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int}
     * }|null
     */
    public function loadPerformanceData(string $siteIdentifier, int $days): ?array
    {
        $siteData = $this->resolveAnalyticsSite($siteIdentifier);
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
                'value' => $this->formatNumber($current['visitCount']),
                'tone' => 'primary',
                'icon' => 'eye',
                'trend' => $this->formatRelativeTrend((float)$current['visitCount'], (float)$previous['visitCount']),
                'trendDirection' => $this->trendDirection((float)$current['visitCount'], (float)$previous['visitCount']),
                'trendLabel' => $trendLabel,
            ],
            [
                'label' => $visitorsLabel,
                'value' => $this->formatNumber($current['visitorCount']),
                'tone' => 'success',
                'icon' => 'circle-plus',
                'trend' => $this->formatRelativeTrend((float)$current['visitorCount'], (float)$previous['visitorCount']),
                'trendDirection' => $this->trendDirection((float)$current['visitorCount'], (float)$previous['visitorCount']),
                'trendLabel' => $trendLabel,
            ],
            [
                'label' => $bounceRateLabel,
                'value' => $this->formatPercentage($current['bounceRate']),
                'tone' => 'danger',
                'icon' => 'arrow-right-from-bracket',
                'trend' => $this->formatRelativeTrend($previous['bounceRate'], $current['bounceRate']),
                'trendDirection' => $this->trendDirection($previous['bounceRate'], $current['bounceRate']),
                'trendLabel' => $trendLabel,
            ],
            [
                'label' => $avgDurationLabel,
                'value' => $this->formatDuration($current['avgDuration']),
                'tone' => 'info',
                'icon' => 'clock',
                'trend' => $this->formatRelativeTrend((float)$current['avgDuration'], (float)$previous['avgDuration']),
                'trendDirection' => $this->trendDirection((float)$current['avgDuration'], (float)$previous['avgDuration']),
                'trendLabel' => $trendLabel,
            ],
        ];
    }

    private function formatNumber(int $value): string
    {
        return number_format($value, 0, '.', '.');
    }

    private function formatPercentage(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') . '%';
    }

    private function formatDuration(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private function formatRelativeTrend(float $currentValue, float $previousValue): string
    {
        if ($previousValue === 0.0) {
            return '';
        }
        $formatted = $this->formatSignedNumber((($currentValue - $previousValue) / $previousValue) * 100);
        return $formatted === '±0' ? '' : $formatted . '%';
    }

    private function trendDirection(float $currentValue, float $previousValue): string
    {
        if ($currentValue === $previousValue) {
            return 'neutral';
        }
        return $currentValue > $previousValue ? 'up' : 'down';
    }

    private function formatSignedNumber(float $value): string
    {
        $formattedValue = rtrim(rtrim(number_format(abs($value), 1, '.', ''), '0'), '.');
        if ($formattedValue === '0') {
            return '±0';
        }
        return ($value >= 0 ? '+' : '-') . $formattedValue;
    }

    private function isAnalyticsSite(Site $site): bool
    {
        return $this->extractSiteCredentials($site) !== null;
    }

    private function userCanAccessPage(int $pageId): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return true;
        }
        return BackendUtility::readPageAccess(
            $pageId,
            $backendUser->getPagePermsClause(Permission::PAGE_SHOW)
        ) !== false;
    }

    /**
     * @return array{site: Site, websiteId: string, apiKey: string}|null
     */
    private function resolveAnalyticsSite(string $siteIdentifier): ?array
    {
        try {
            $sites = $siteIdentifier !== ''
                ? [$this->siteFinder->getSiteByIdentifier($siteIdentifier)]
                : $this->siteFinder->getAllSites();
        } catch (\Throwable) {
            return null;
        }

        foreach ($sites as $site) {
            if (!$this->userCanAccessPage($site->getRootPageId())) {
                continue;
            }
            $credentials = $this->extractSiteCredentials($site);
            if ($credentials !== null) {
                return $credentials;
            }
        }

        return null;
    }

    /**
     * @return array{site: Site, websiteId: string, apiKey: string}|null
     */
    private function extractSiteCredentials(Site $site): ?array
    {
        try {
            $settings = $site->getSettings();
            $websiteId = (string)($settings->get('externalWebsiteId', '') ?: '');
            $encryptedApiKey = (string)($settings->get('apiKey', '') ?: '');
            if ($websiteId === '' || $encryptedApiKey === '') {
                return null;
            }
            $apiKey = $this->cipherService->decrypt($encryptedApiKey);
            return ['site' => $site, 'websiteId' => $websiteId, 'apiKey' => $apiKey];
        } catch (\Throwable) {
            return null;
        }
    }
}
