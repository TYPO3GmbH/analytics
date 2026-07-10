<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;

final readonly class TopPagesService implements TopPagesServiceInterface
{
    private const MAX_LIMIT = 20;

    public function __construct(
        private AnalyticsDataClientInterface $analyticsClient,
        private LoggerInterface $logger,
        private FrontendInterface $cache,
        private BackendPageAccessCheckerInterface $pageAccessChecker,
        private MetricFormatterInterface $formatter,
        private AnalyticsSiteProviderInterface $siteProvider,
    ) {
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function loadTopPagesData(string $siteIdentifier, int $days, int $limit = 10): ?array
    {
        $siteData = $this->siteProvider->resolveAnalyticsSite($siteIdentifier);
        if ($siteData === null) {
            return null;
        }

        $days = max(1, $days);
        $limit = max(1, $limit);
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
                $cached = $this->analyticsClient->fetchTopPages($websiteId, $apiKey, $from, $to, $prevFrom, $prevTo, self::MAX_LIMIT);
                $this->cache->set($cacheKey, $cached);
            } catch (AnalyticsApiException $e) {
                $this->logger->warning('TopPagesService: Failed to fetch top pages.', ['reason' => $e->reason]);
                return [];
            }
        }

        $pages = $this->filterPagesByAccess($site, $cached);
        return array_values(array_slice($pages, 0, $limit));
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
                $formatted = $this->formatter->formatSignedNumber($percentageChange);
                $trend = $formatted === '±0' ? '' : $formatted . '%';
                if ($trend !== '') {
                    $trendDirection = $currentVisitCount > $previousVisitCount ? 'up' : 'down';
                }
            }

            $items[] = [
                'position' => $index + 1,
                'url' => (string)($page['pageUrl'] ?? ''),
                'title' => (string)($page['pageTitle'] ?? ($page['pageUrl'] ?? '')),
                'visitCount' => $this->formatter->formatNumber($currentVisitCount),
                'visitPercentOfTotal' => $this->formatter->formatPercentageWidth((float)($page['visitPercentOfTotal'] ?? 0.0)),
                'trend' => $trend,
                'trendDirection' => $trendDirection,
                'trendLabel' => $trendLabel,
            ];
        }

        return $items;
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
                // Deny by default: a URL that cannot be resolved to a page is not access-checkable.
                return false;
            }
            return $this->pageAccessChecker->userCanAccessPage($pageId);
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
}
