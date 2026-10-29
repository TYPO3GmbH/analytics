<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Site\Entity\Site;

readonly class AnalyticsStatusService implements AnalyticsStatusServiceInterface
{
    private const CREDIT_WARNING_REMAINING_RATIO = 0.25;
    public const STATUS_CACHE_LIFETIME = 86400;

    public function __construct(
        private FrontendInterface $cache,
        private AnalyticsApiClientInterface $apiClient,
        private CipherServiceInterface $cipherService,
        private LoggerInterface $logger,
        private SiteDataStorageInterface $siteDataStorage,
        private SiteSettingsWriteVerifierInterface $writeGuard,
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
     * Persists trackingCode and status from a status API response to the site data.
     * Must be called explicitly after a successful status fetch — not on every read.
     *
     * @param array<string, mixed> $data
     */
    public function syncSiteSettingsFromStatus(Site $site, array $data): void
    {
        $trackingCode = (string)($data['maxPrivacyModeTrackingCode'] ?? '');
        $newStatus = (string)($data['status'] ?? '');
        $newExternalWebsiteId = (string)($data['externalWebsiteId'] ?? '');
        $existingTrackingCode = $this->siteDataStorage->get($site, 'trackingCode');
        $existingStatus = $this->siteDataStorage->get($site, 'status');
        $existingExternalWebsiteId = $this->siteDataStorage->get($site, 'externalWebsiteId');

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

        try {
            $this->siteDataStorage->write($site, $update);
        } catch (SiteConfigurationWriteException $e) {
            $this->logger->warning(
                'syncSiteSettingsFromStatus: writeSettings threw. Check file system permissions.',
                ['siteIdentifier' => $site->getIdentifier(), 'exception' => $e->getMessage()]
            );
            return;
        }

        try {
            $this->writeGuard->assertSettingsPersisted($site, $update);
        } catch (AnalyticsApiException) {
            $this->logger->warning(
                'syncSiteSettingsFromStatus: settings could not be persisted. Check file system permissions.',
                ['siteIdentifier' => $site->getIdentifier()]
            );
            return;
        }

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
     * Reads and decrypts credentials from the site data.
     *
     * @return array{0: string, 1: string, 2: string}|null  [websiteId, instanceId, instanceSecret]
     */
    private function resolveCredentials(Site $site): ?array
    {
        $siteIdentifier = $site->getIdentifier();
        $websiteId = $this->siteDataStorage->get($site, 'websiteId');
        $instanceId = $this->siteDataStorage->get($site, 'instanceId');
        $encryptedSecret = $this->siteDataStorage->get($site, 'instanceSecret');

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
