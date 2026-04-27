<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Analytics;
use T3G\Analytics\Utility\ApiExceptionHelper;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;

readonly class AnalyticsStatusService
{
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

        if ($status !== null) {
            $this->cache->set($cacheKey, $status, [], 86400);
        }

        return $status;
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
                    'headers' => $this->buildHmacHeaders('GET', $path, $instanceId, $instanceSecret),
                    'verify' => false,
                ]
            );

            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return isset($data['checkoutUrl']) ? (string)$data['checkoutUrl'] : null;
        } catch (\Throwable $e) {
            $this->logger->error('getManagePlanUrl: API request failed.', ['websiteId' => $websiteId, 'reason' => ApiExceptionHelper::extractReason($e)]);
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
                    'headers' => $this->buildHmacHeaders('GET', $path, $instanceId, $instanceSecret),
                    'verify' => false,
                ]
            );

            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return isset($data['dashboardUrl']) ? (string)$data['dashboardUrl'] : null;
        } catch (\Throwable $e) {
            $this->logger->error('getDashboardUrl: API request failed.', ['websiteId' => $websiteId, 'reason' => ApiExceptionHelper::extractReason($e)]);
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
                    'headers' => $this->buildHmacHeaders('GET', $path, $instanceId, $instanceSecret),
                    'verify' => false,
                ]
            );

            /** @var array<string, mixed> $data */
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $data['_fetchedAt'] = time();

            $this->persistStatusSettings($site, $data);

            return $data;
        } catch (\Throwable $e) {
            $this->logger->error('fetchFromApi: API request failed.', ['websiteId' => $websiteId, 'reason' => ApiExceptionHelper::extractReason($e)]);
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
        $websiteId = $settings->get('websiteId', '');
        $instanceId = $settings->get('instanceId', '');
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
     * @return array<string, string>
     */
    private function buildHmacHeaders(string $method, string $path, string $instanceId, string $instanceSecret): array
    {
        $timestamp = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
        $contentHash = hash('sha256', '');

        $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $contentHash]);
        $signature = base64_encode(hash_hmac('sha256', $canonical, $instanceSecret, true));

        return [
            'Authorization' => 'HMAC ' . $instanceId . ':' . $signature,
            'X-Timestamp' => $timestamp,
            'X-Content-Hash' => $contentHash,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function persistStatusSettings(Site $site, array $data): void
    {
        $trackingCode = (string)($data['maxPrivacyModeTrackingCode'] ?? '');
        $newStatus = (string)($data['status'] ?? '');
        $settings = $site->getSettings();

        $update = [];
        if ($trackingCode !== '' && $trackingCode !== $settings->get('trackingCode', '')) {
            $update['trackingCode'] = $trackingCode;
        }
        if ($newStatus !== '' && $newStatus !== $settings->get('status', '')) {
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

    private function cacheKey(Site $site): string
    {
        return 'status_' . sha1($site->getIdentifier());
    }
}
