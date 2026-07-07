<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Site\Entity\Site;

interface AnalyticsApiClientInterface
{
    /**
     * @return array<string, mixed>
     * @throws AnalyticsApiException
     */
    public function registerInstance(Site $site, string $email): array;

    /**
     * @return array<string, mixed>
     * @throws AnalyticsApiException
     */
    public function fetchStatus(string $websiteId, string $instanceId, string $instanceSecret): array;

    /**
     * @throws AnalyticsApiException
     */
    public function fetchDashboardUrl(string $websiteId, string $instanceId, string $instanceSecret, bool $watcher = false): ?string;

    /**
     * @return array{apiKeyId: string, apiKey: string}
     * @throws AnalyticsApiException
     */
    public function createApiKey(string $websiteId, string $instanceId, string $instanceSecret): array;

    /**
     * @throws AnalyticsApiException
     */
    public function fetchCheckoutUrl(string $websiteId, string $instanceId, string $instanceSecret): ?string;
}
