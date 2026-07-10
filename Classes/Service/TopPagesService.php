<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
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
        private UriBuilder $uriBuilder,
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

        $pages = $this->annotateWithPageAccess($site, $cached);
        return array_values(array_slice($pages, 0, $limit));
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @return list<array{position: int, url: string, title: string, visitCount: string, visitPercentOfTotal: string, trend: string, trendDirection: string, trendLabel: string, pageId: int|null, languageId: int|null, flagIdentifier: string, slug: string, pageModuleUri: string|null}>
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

            $pageId = isset($page['pageId']) ? (int)$page['pageId'] : null;
            $languageId = isset($page['languageId']) ? (int)$page['languageId'] : null;
            $pageModuleUri = null;
            if ($pageId !== null) {
                $routeParams = ['id' => $pageId];
                if ($languageId !== null) {
                    $routeParams['languages'] = [$languageId];
                }
                $pageModuleUri = (string)$this->uriBuilder->buildUriFromRoute('web_layout', $routeParams);
            }

            $urlString = (string)($page['pageUrl'] ?? '');
            $slug = $pageId !== null ? ((string)(parse_url($urlString, PHP_URL_PATH) ?: '/')) : '';

            $items[] = [
                'position' => $index + 1,
                'url' => $urlString,
                'title' => (string)($page['pageTitle'] ?? ($page['pageUrl'] ?? '')),
                'slug' => $slug,
                'visitCount' => $this->formatter->formatNumber($currentVisitCount),
                'visitPercentOfTotal' => $this->formatter->formatPercentageWidth((float)($page['visitPercentOfTotal'] ?? 0.0)),
                'trend' => $trend,
                'trendDirection' => $trendDirection,
                'trendLabel' => $trendLabel,
                'pageId' => $pageId,
                'languageId' => $languageId,
                'flagIdentifier' => (string)($page['flagIdentifier'] ?? ''),
                'pageModuleUri' => $pageModuleUri,
            ];
        }

        return $items;
    }

    /**
     * Passes all pages through; annotates each with pageId + languageId only when the user
     * has both page access and language access, which enables the page-module link in the UI.
     *
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private function annotateWithPageAccess(Site $site, array $pages): array
    {
        $result = [];
        foreach ($pages as $page) {
            $url = (string)($page['pageUrl'] ?? '');
            if ($url === '') {
                continue;
            }
            $language = $this->findLanguageForUrl($site, $url);
            if ($language === null) {
                // URL belongs to a different domain — not part of this site.
                continue;
            }
            $page['flagIdentifier'] = $language->getFlagIdentifier();
            $resolved = $this->resolvePageArguments($site, $language, $url);
            if (
                $resolved !== null
                && $this->pageAccessChecker->userCanAccessPage($resolved->getPageId())
                && $this->pageAccessChecker->userCanAccessLanguage($language->getLanguageId())
            ) {
                $page['pageId'] = $resolved->getPageId();
                $page['languageId'] = $language->getLanguageId();
            }
            $result[] = $page;
        }
        return $result;
    }

    private function findLanguageForUrl(Site $site, string $url): ?\TYPO3\CMS\Core\Site\Entity\SiteLanguage
    {
        $normalizedUrl = rtrim($url, '/');
        $bestMatch = null;
        $bestLength = 0;
        foreach ($site->getLanguages() as $siteLanguage) {
            $base = $this->absoluteLanguageBase($site, $siteLanguage);
            if ($base !== '' && str_starts_with($normalizedUrl, $base) && strlen($base) > $bestLength) {
                $bestMatch = $siteLanguage;
                $bestLength = strlen($base);
            }
        }
        return $bestMatch;
    }

    private function resolvePageArguments(Site $site, \TYPO3\CMS\Core\Site\Entity\SiteLanguage $language, string $url): ?PageArguments
    {
        try {
            $uri = new Uri($url);
            $base = $this->absoluteLanguageBase($site, $language);
            $tail = ltrim(substr(rtrim($url, '/'), strlen($base)), '/');
            $previousResult = new SiteRouteResult($uri, $site, $language, $tail);
            $result = $site->getRouter()->matchRequest(new ServerRequest($uri, 'GET'), $previousResult);
            return $result instanceof PageArguments ? $result : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function absoluteLanguageBase(Site $site, \TYPO3\CMS\Core\Site\Entity\SiteLanguage $language): string
    {
        $base = (string)$language->getBase();
        if (!str_contains($base, '://')) {
            // Relative base (e.g. "/" or "/de/") — combine with the site's absolute base.
            $base = rtrim((string)$site->getBase(), '/') . '/' . ltrim($base, '/');
        }
        return rtrim($base, '/');
    }
}
