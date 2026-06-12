<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Site\Entity\Site;

interface TopPagesServiceInterface
{
    public function isAnalyticsSite(Site $site): bool;

    public function userCanAccessPage(int $pageId): bool;

    /**
     * @return list<array<string, mixed>>|null
     */
    public function loadTopPagesData(string $siteIdentifier, int $days, int $limit = 10): ?array;

    /**
     * @param list<array<string, mixed>> $pages
     * @return list<array{position: int, url: string, title: string, visitCount: string, visitPercentOfTotal: string, trend: string, trendDirection: string, trendLabel: string}>
     */
    public function buildPageItems(array $pages, string $trendLabel): array;
}
