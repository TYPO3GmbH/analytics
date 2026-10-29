<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Site\Entity\Site;

readonly class ApiKeyService implements ApiKeyServiceInterface
{
    public function __construct(
        private AnalyticsApiClientInterface $apiClient,
        private CipherServiceInterface $cipherService,
        private SiteDataStorageInterface $siteDataStorage,
        private SiteSettingsWriteVerifierInterface $writeGuard,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Creates and stores a TYPO3 Analytics API key for the site when it is active, and
     * no key has been provisioned yet. The apiKey value is only returned once
     * by the API and is stored encrypted in the site data.
     *
     * @param array<string, mixed> $currentStatus the freshly fetched status response
     */
    public function provisionIfNeeded(Site $site, array $currentStatus): void
    {
        $siteIdentifier = $site->getIdentifier();

        if (($currentStatus['status'] ?? '') !== 'active') {
            return;
        }

        $websiteId = $this->siteDataStorage->get($site, 'websiteId');
        $instanceId = $this->siteDataStorage->get($site, 'instanceId');
        $encryptedSecret = $this->siteDataStorage->get($site, 'instanceSecret');
        $apiKeyId = $this->siteDataStorage->get($site, 'apiKeyId');

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

        try {
            $this->siteDataStorage->write($site, [
                'apiKeyId' => $result['apiKeyId'],
                'apiKey' => $encryptedApiKey,
            ]);
        } catch (SiteConfigurationWriteException $e) {
            $this->logger->error('ApiKeyService: Failed to write the API key to the site data.', [
                'siteIdentifier' => $siteIdentifier,
                'exception' => $e->getMessage(),
            ]);
            return;
        }

        try {
            $this->writeGuard->assertSettingsPersisted($site, ['apiKeyId' => $result['apiKeyId']]);
        } catch (AnalyticsApiException $e) {
            $this->logger->error('ApiKeyService: API key could not be persisted.', [
                'siteIdentifier' => $siteIdentifier,
                'apiKeyId' => $result['apiKeyId'],
            ]);
            return;
        }

        $this->logger->info('ApiKeyService: API key provisioned.', [
            'siteIdentifier' => $siteIdentifier,
            'apiKeyId' => $result['apiKeyId'],
        ]);
    }
}
