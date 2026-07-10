<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;

readonly class AnalyticsStatusService implements AnalyticsStatusServiceInterface
{
    private const CREDIT_WARNING_REMAINING_RATIO = 0.25;
    public const STATUS_CACHE_LIFETIME = 86400;

    public function __construct(
        private FrontendInterface $cache,
        private AnalyticsApiClientInterface $apiClient,
        private CipherServiceInterface $cipherService,
        private LoggerInterface $logger,
        private SiteSettingsService $siteSettingsService,
        private SiteSettingsFactory $siteSettingsFactory,
    ) {
    }

    /**
     * Returns the cached status for the given site, fetching from the API if
     * the cache is empty or $forceRefresh is true.
     *
     * Returns null when the site is not fully registered or the API call fails.
     *
     * @return array<string, mixed>|null
     */
    public function getStatus(Site $site, bool $forceRefresh = false): ?array
    {
        $cacheKey = $this->cacheKey($site);

        if (!$forceRefresh && $this->cache->has($cacheKey)) {
            /** @var array<string, mixed> $cached */
            $cached = $this->cache->get($cacheKey);
            return $cached;
        }

        $status = $this->fetchFromApi($site);
        $prepared = $this->prepareStatus($status);

        if ($prepared !== null) {
            $this->cache->set($cacheKey, $prepared, [], self::STATUS_CACHE_LIFETIME);
        }

        return $prepared;
    }

    public function clearCache(Site $site): void
    {
        $this->cache->remove($this->cacheKey($site));
    }

    /**
     * Persists trackingCode and status from a status API response to the site settings.
     * Must be called explicitly after a successful status fetch — not on every read.
     *
     * @param array<string, mixed> $data
     */
    public function syncSiteSettingsFromStatus(Site $site, array $data): void
    {
        $trackingCode = (string)($data['maxPrivacyModeTrackingCode'] ?? '');
        $newStatus = (string)($data['status'] ?? '');
        $newExternalWebsiteId = (string)($data['externalWebsiteId'] ?? '');
        $siteIdentifier = $site->getIdentifier();
        $settings = $site->getSettings();

        try {
            $existingTrackingCode = $settings->get('trackingCode', '');
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
            $this->logger->warning('syncSiteSettingsFromStatus: failed to read trackingCode.', ['siteIdentifier' => $siteIdentifier, 'exception' => $e]);
            $existingTrackingCode = '';
        }
        try {
            $existingStatus = $settings->get('status', '');
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
            $this->logger->warning('syncSiteSettingsFromStatus: failed to read status.', ['siteIdentifier' => $siteIdentifier, 'exception' => $e]);
            $existingStatus = '';
        }
        try {
            $existingExternalWebsiteId = (string)($settings->get('externalWebsiteId', '') ?: '');
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
            $existingExternalWebsiteId = '';
        }

        $update = [];
        if ($trackingCode !== '' && $trackingCode !== $existingTrackingCode) {
            // @todo probably a custom HTML sanitizer instance shall be used here
            $update['trackingCode'] = $trackingCode;
        }
        if ($newStatus !== '' && $newStatus !== $existingStatus) {
            $update['status'] = $newStatus;
        }
        if ($newExternalWebsiteId !== '' && $newExternalWebsiteId !== $existingExternalWebsiteId) {
            $update['externalWebsiteId'] = $newExternalWebsiteId;
        }

        if ($update === []) {
            return;
        }

        $existing = $this->siteSettingsFactory->loadLocalSettings($site->getIdentifier()) ?? [];
        $this->siteSettingsService->writeSettings($site, array_merge($existing, $update));
        $this->logger->info(
            'Site settings updated from status response.',
            ['siteIdentifier' => $site->getIdentifier(), 'update' => $update]
        );
    }

    /**
     * Fetches a fresh manage plan iframe URL from the API.
     * The returned JWT is short-lived, so this is never cached.
     */
    public function getManagePlanUrl(Site $site): ?string
    {
        $credentials = $this->resolveCredentials($site);
        if ($credentials === null) {
            return null;
        }
        [$websiteId, $instanceId, $instanceSecret] = $credentials;
        try {
            return $this->apiClient->fetchCheckoutUrl($websiteId, $instanceId, $instanceSecret);
        } catch (AnalyticsApiException $e) {
            $this->logger->error('getManagePlanUrl: API request failed.', ['websiteId' => $websiteId, 'reason' => $e->reason]);
            return null;
        }
    }

    /**
     * Fetches a fresh dashboard iframe URL from the API.
     * The returned JWT is short-lived (~300 s), so this is never cached.
     */
    public function getDashboardUrl(Site $site, bool $watcher = false): ?string
    {
        $credentials = $this->resolveCredentials($site);
        if ($credentials === null) {
            return null;
        }
        [$websiteId, $instanceId, $instanceSecret] = $credentials;
        try {
            return $this->apiClient->fetchDashboardUrl($websiteId, $instanceId, $instanceSecret, $watcher);
        } catch (AnalyticsApiException $e) {
            $this->logger->error('getDashboardUrl: API request failed.', ['websiteId' => $websiteId, 'reason' => $e->reason]);
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchFromApi(Site $site): ?array
    {
        $credentials = $this->resolveCredentials($site);
        if ($credentials === null) {
            return null;
        }
        [$websiteId, $instanceId, $instanceSecret] = $credentials;
        try {
            $data = $this->apiClient->fetchStatus($websiteId, $instanceId, $instanceSecret);
            $data['_fetchedAt'] = time();
            return $data;
        } catch (AnalyticsApiException $e) {
            $this->logger->error('fetchFromApi: API request failed.', ['websiteId' => $websiteId, 'reason' => $e->reason]);
            return null;
        }
    }

    /**
     * Reads and decrypts credentials from site settings.
     *
     * @return array{0: string, 1: string, 2: string}|null  [websiteId, instanceId, instanceSecret]
     */
    private function resolveCredentials(Site $site): ?array
    {
        $siteIdentifier = $site->getIdentifier();
        $settings = $site->getSettings();
        try {
            $websiteId = $settings->get('websiteId', '');
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
            $this->logger->warning('resolveCredentials: failed to read websiteId.', ['siteIdentifier' => $siteIdentifier, 'exception' => $e]);
            $websiteId = '';
        }
        try {
            $instanceId = $settings->get('instanceId', '');
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
            $this->logger->warning('resolveCredentials: failed to read instanceId.', ['siteIdentifier' => $siteIdentifier, 'exception' => $e]);
            $instanceId = '';
        }
        try {
            $encryptedSecret = $settings->get('instanceSecret', '');
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
            $this->logger->warning('resolveCredentials: failed to read instanceSecret.', ['siteIdentifier' => $siteIdentifier, 'exception' => $e]);
            $encryptedSecret = '';
        }

        if ($websiteId === '' || $instanceId === '' || $encryptedSecret === '') {
            return null;
        }

        try {
            $instanceSecret = $this->cipherService->decrypt($encryptedSecret);
        } catch (\Throwable $e) {
            $this->logger->error('resolveCredentials: failed to decrypt instanceSecret.', ['siteIdentifier' => $siteIdentifier, 'exception' => $e]);
            return null;
        }

        return [$websiteId, $instanceId, $instanceSecret];
    }

    /**
     * @param array<string, mixed>|null $status
     * @return array<string, mixed>|null
     */
    private function prepareStatus(?array $status): ?array
    {
        if (!is_array($status['consumption'] ?? null)) {
            return $status;
        }

        $consumption = $status['consumption'];
        $hasLimit = array_key_exists('stpLimit', $consumption);
        $limit = $hasLimit ? (int)$consumption['stpLimit'] : 0;

        if ($limit === -1) {
            $consumption['limited'] = false;
            $consumption['exhausted'] = false;
            $consumption['stpRemaining'] = PHP_INT_MAX;
            $consumption['warning'] = false;
        } else {
            $remaining = array_key_exists('stpRemaining', $consumption)
                ? (int)$consumption['stpRemaining']
                : max(0, $limit - (int)($consumption['stpConsumed'] ?? 0));
            $exhausted = (bool)($consumption['exhausted'] ?? false);

            $consumption['limited'] = $hasLimit;
            $consumption['stpRemaining'] = $remaining;
            $consumption['exhausted'] = $exhausted;
            $consumption['warning'] = $hasLimit && !$exhausted && $limit > 0
                && ($remaining / $limit) <= self::CREDIT_WARNING_REMAINING_RATIO;
        }

        $status['consumption'] = $consumption;
        return $status;
    }

    private function cacheKey(Site $site): string
    {
        return 'status_' . sha1($site->getIdentifier());
    }
}
