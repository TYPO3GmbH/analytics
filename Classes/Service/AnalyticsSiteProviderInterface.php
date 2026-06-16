<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Site\Entity\Site;

interface AnalyticsSiteProviderInterface
{
    /**
     * @return array<string, string>
     */
    public function siteOptions(): array;

    /**
     * @return array{site: Site, websiteId: string, apiKey: string}|null
     */
    public function resolveAnalyticsSite(string $siteIdentifier): ?array;
}
