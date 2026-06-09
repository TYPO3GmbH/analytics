<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;

final readonly class ApiKeyService
{
    public function __construct(
        private AnalyticsApiClient $apiClient,
        private CipherService $cipherService,
        private SiteSettingsService $siteSettingsService,
        private SiteSettingsFactory $siteSettingsFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Creates and stores a TYPO3 Analytics API key for the site when it is active, and
     * no key has been provisioned yet. The apiKey value is only returned once
     * by the API and is stored encrypted in the site settings.
     *
     * @param array<string, mixed> $currentStatus the freshly fetched status response
     */
    public function provisionIfNeeded(Site $site, array $currentStatus): void
    {
        $siteIdentifier = $site->getIdentifier();

        if (($currentStatus['status'] ?? '') !== 'active') {
            return;
        }

        $settings = $site->getSettings();
        try {
            $websiteId = (string)($settings->get('websiteId', '') ?: '');
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface) {
            $websiteId = '';
        }
        try {
            $instanceId = (string)($settings->get('instanceId', '') ?: '');
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface) {
            $instanceId = '';
        }
        try {
            $encryptedSecret = (string)($settings->get('instanceSecret', '') ?: '');
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface) {
            $encryptedSecret = '';
        }
        try {
            $apiKeyId = (string)($settings->get('apiKeyId', '') ?: '');
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface) {
            $apiKeyId = '';
        }

        if ($websiteId === '' || $instanceId === '' || $encryptedSecret === '' || $apiKeyId !== '') {
            return;
        }

        try {
            $instanceSecret = $this->cipherService->decrypt($encryptedSecret);
        } catch (\Throwable $e) {
            $this->logger->error('ApiKeyService: Failed to decrypt instanceSecret.', [
                'siteIdentifier' => $siteIdentifier,
                'exception' => $e,
            ]);
            return;
        }

        try {
            $result = $this->apiClient->createApiKey($websiteId, $instanceId, $instanceSecret);
        } catch (AnalyticsApiException $e) {
            $this->logger->error('ApiKeyService: API key creation failed.', [
                'siteIdentifier' => $siteIdentifier,
                'reason' => $e->reason,
            ]);
            return;
        }

        if ($result['apiKeyId'] === '' || $result['apiKey'] === '') {
            $this->logger->error('ApiKeyService: API response is missing id or apiKey.', [
                'siteIdentifier' => $siteIdentifier,
            ]);
            return;
        }

        try {
            $encryptedApiKey = $this->cipherService->encrypt($result['apiKey']);
        } catch (\Throwable $e) {
            $this->logger->error('ApiKeyService: Failed to encrypt API key.', [
                'siteIdentifier' => $siteIdentifier,
                'exception' => $e,
            ]);
            return;
        }

        $existing = $this->siteSettingsFactory->loadLocalSettings($siteIdentifier) ?? [];
        $this->siteSettingsService->writeSettings($site, array_merge($existing, [
            'apiKeyId' => $result['apiKeyId'],
            'apiKey' => $encryptedApiKey,
        ]));

        $this->logger->info('ApiKeyService: API key provisioned.', [
            'siteIdentifier' => $siteIdentifier,
            'apiKeyId' => $result['apiKeyId'],
        ]);
    }
}
