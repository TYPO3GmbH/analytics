<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface TopPagesServiceInterface
{
    /**
     * @return list<array<string, mixed>>|null
     */
    public function loadTopPagesData(string $siteIdentifier, int $days, int $limit = 10): ?array;

    /**
     * @param list<array<string, mixed>> $pages
     * @return list<array{position: int, url: string, title: string, visitCount: string, visitPercentOfTotal: string, trend: string, trendDirection: string, trendLabel: string, pageId: int|null, languageId: int|null, flagIdentifier: string, slug: string, pageModuleUri: string|null}>
     */
    public function buildPageItems(array $pages, string $trendLabel): array;
}
