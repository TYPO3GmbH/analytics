<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Site\Entity\Site;

interface AnalyticsStatusServiceInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function getStatus(Site $site, bool $forceRefresh = false): ?array;

    public function clearCache(Site $site): void;

    /**
     * @param array<string, mixed> $data
     */
    public function syncSiteSettingsFromStatus(Site $site, array $data): void;

    public function getManagePlanUrl(Site $site): ?string;

    public function getDashboardUrl(Site $site): ?string;
}
