<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Analytics;
use T3G\Analytics\Utility\ApiExceptionUtility;
use T3G\Analytics\Utility\HmacUtility;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;

readonly class AnalyticsStatusService
{
    private const float CREDIT_WARNING_REMAINING_RATIO = 0.25;

    public function __construct(
        private FrontendInterface $cache,
        private RequestFactory $requestFactory,
        private CipherService $cipherService,
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
            $this->cache->set($cacheKey, $prepared, [], 86400);
        }

        return $prepared;
    }

    public function clearCache(Site $site): void
    {
        $this->cache->remove($this->cacheKey($site));
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
        $path = '/api/checkout-url/' . $websiteId;

        try {
            $response = $this->requestFactory->request(
                Analytics::getApiBaseUrl() . '/checkout-url/' . $websiteId,
                'GET',
                [
                    'headers' => HmacUtility::buildHeaders('GET', $path, $instanceId, $instanceSecret),
                    'verify' => false,
                ]
            );

            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return isset($data['checkoutUrl']) ? (string)$data['checkoutUrl'] : null;
        } catch (\Throwable $e) {
            $this->logger->error('getManagePlanUrl: API request failed.', ['websiteId' => $websiteId, 'reason' => ApiExceptionUtility::extractReason($e)]);
            return null;
        }
    }

    /**
     * Fetches a fresh dashboard iframe URL from the API.
     * The returned JWT is short-lived (~300 s), so this is never cached.
     */
    public function getDashboardUrl(Site $site): ?string
    {
        $credentials = $this->resolveCredentials($site);
        if ($credentials === null) {
            return null;
        }

        [$websiteId, $instanceId, $instanceSecret] = $credentials;
        $path = '/api/dashboard-url/' . $websiteId;

        try {
            $response = $this->requestFactory->request(
                Analytics::getApiBaseUrl() . '/dashboard-url/' . $websiteId,
                'GET',
                [
                    'headers' => HmacUtility::buildHeaders('GET', $path, $instanceId, $instanceSecret),
                    'verify' => false,
                ]
            );

            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return isset($data['dashboardUrl']) ? (string)$data['dashboardUrl'] : null;
        } catch (\Throwable $e) {
            $this->logger->error('getDashboardUrl: API request failed.', ['websiteId' => $websiteId, 'reason' => ApiExceptionUtility::extractReason($e)]);
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
        $path = '/api/status/' . $websiteId;

        try {
            $response = $this->requestFactory->request(
                Analytics::getApiBaseUrl() . '/status/' . $websiteId,
                'GET',
                [
                    'headers' => HmacUtility::buildHeaders('GET', $path, $instanceId, $instanceSecret),
                    'verify' => false,
                ]
            );

            /** @var array<string, mixed> $data */
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $data['_fetchedAt'] = time();

            $this->persistStatusSettings($site, $data);

            return $data;
        } catch (\Throwable $e) {
            $this->logger->error('fetchFromApi: API request failed.', ['websiteId' => $websiteId, 'reason' => ApiExceptionUtility::extractReason($e)]);
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
        $settings = $site->getSettings();
        /** @var string $websiteId */
        $websiteId = $settings->get('websiteId', '');
        /** @var string $instanceId */
        $instanceId = $settings->get('instanceId', '');
        /** @var string $encryptedSecret */
        $encryptedSecret = $settings->get('instanceSecret', '');

        if ($websiteId === '' || $instanceId === '' || $encryptedSecret === '') {
            return null;
        }

        try {
            $instanceSecret = $this->cipherService->decrypt($encryptedSecret);
        } catch (\Throwable) {
            return null;
        }

        return [$websiteId, $instanceId, $instanceSecret];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function persistStatusSettings(Site $site, array $data): void
    {
        $trackingCode = (string)($data['maxPrivacyModeTrackingCode'] ?? '');
        $newStatus = (string)($data['status'] ?? '');
        $settings = $site->getSettings();
        /** @var string $existingTrackingCode */
        $existingTrackingCode = $settings->get('trackingCode', '');
        /** @var string $existingStatus */
        $existingStatus = $settings->get('status', '');

        $update = [];
        if ($trackingCode !== '' && $trackingCode !== $existingTrackingCode) {
            $update['trackingCode'] = $trackingCode;
        }
        if ($newStatus !== '' && $newStatus !== $existingStatus) {
            $update['status'] = $newStatus;
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
