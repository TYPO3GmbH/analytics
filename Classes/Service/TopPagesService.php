<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

final readonly class TopPagesService implements TopPagesServiceInterface
{
    public function __construct(
        private SiteFinder $siteFinder,
        private AnalyticsDataClientInterface $analyticsClient,
        private CipherServiceInterface $cipherService,
        private LoggerInterface $logger,
        private FrontendInterface $cache,
    ) {
    }

    public function isAnalyticsSite(Site $site): bool
    {
        return $this->extractSiteCredentials($site) !== null;
    }

    public function userCanAccessPage(int $pageId): bool
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
     * @return list<array<string, mixed>>|null
     */
    public function loadTopPagesData(string $siteIdentifier, int $days): ?array
    {
        $siteData = $this->resolveAnalyticsSite($siteIdentifier);
        if ($siteData === null) {
            return null;
        }

        $days = max(1, $days);
        $websiteId = $siteData['websiteId'];
        $apiKey = $siteData['apiKey'];
        $site = $siteData['site'];

        $cacheKey = 'top_pages_' . md5($websiteId . '_' . $days);

        /** @var list<array<string, mixed>>|false $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached === false) {
            $to = new \DateTimeImmutable('today 23:59:59');
            $from = $to->modify('-' . ($days - 1) . ' days');
            $prevTo = $from->modify('-1 day');
            $prevFrom = $prevTo->modify('-' . ($days - 1) . ' days');

            try {
                $cached = $this->analyticsClient->fetchTopPages($websiteId, $apiKey, $from, $to, $prevFrom, $prevTo);
                $this->cache->set($cacheKey, $cached);
            } catch (AnalyticsApiException $e) {
                $this->logger->warning('TopPagesService: Failed to fetch top pages.', ['reason' => $e->reason]);
                return [];
            }
        }

        return $this->filterPagesByAccess($site, $cached);
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @return list<array{position: int, url: string, title: string, visitCount: string, visitPercentOfTotal: string, trend: string, trendDirection: string, trendLabel: string}>
     */
    public function buildPageItems(array $pages, string $trendLabel): array
    {
        $items = [];
        foreach ($pages as $index => $page) {
            $currentVisitCount = (int)($page['visitCount'] ?? 0);
            $previousVisitCount = (int)($page['previousVisitCount'] ?? 0);
            $percentageChange = (float)($page['visitCountPercentageChange'] ?? 0.0);

            $trend = '';
            $trendDirection = 'neutral';
            if ($previousVisitCount > 0) {
                $formatted = $this->formatSignedNumber($percentageChange);
                $trend = $formatted === '±0' ? '' : $formatted . '%';
                if ($trend !== '') {
                    $trendDirection = $currentVisitCount > $previousVisitCount ? 'up' : 'down';
                }
            }

            $items[] = [
                'position' => $index + 1,
                'url' => (string)($page['pageUrl'] ?? ''),
                'title' => (string)($page['pageTitle'] ?? ($page['pageUrl'] ?? '')),
                'visitCount' => $this->formatNumber($currentVisitCount),
                'visitPercentOfTotal' => $this->formatPercentageWidth((float)($page['visitPercentOfTotal'] ?? 0.0)),
                'trend' => $trend,
                'trendDirection' => $trendDirection,
                'trendLabel' => $trendLabel,
            ];
        }

        return $items;
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
    public function extractSiteCredentials(Site $site): ?array
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

    /**
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private function filterPagesByAccess(Site $site, array $pages): array
    {
        return array_values(array_filter($pages, function (array $page) use ($site): bool {
            $url = (string)($page['pageUrl'] ?? '');
            if ($url === '') {
                return false;
            }
            $pageId = $this->resolvePageId($site, $url);
            if ($pageId === null) {
                return true;
            }
            return $this->userCanAccessPage($pageId);
        }));
    }

    private function resolvePageId(Site $site, string $url): ?int
    {
        try {
            $request = new ServerRequest($url, 'GET');
            $result = $site->getRouter()->matchRequest($request);
            return $result instanceof PageArguments ? $result->getPageId() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatNumber(int $value): string
    {
        return number_format($value, 0, '.', '.');
    }

    private function formatPercentageWidth(float $value): string
    {
        return rtrim(rtrim(number_format(max(0.0, min(100.0, $value)), 1, '.', ''), '0'), '.');
    }

    private function formatSignedNumber(float $value): string
    {
        $formattedValue = rtrim(rtrim(number_format(abs($value), 1, '.', ''), '0'), '.');
        if ($formattedValue === '0') {
            return '±0';
        }
        return ($value >= 0 ? '+' : '-') . $formattedValue;
    }
}
