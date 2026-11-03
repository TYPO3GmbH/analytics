<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;

readonly class ApiKeyService implements ApiKeyServiceInterface
{
    public function __construct(
        private AnalyticsApiClientInterface $apiClient,
        private CipherServiceInterface $cipherService,
        private SiteSettingsService $siteSettingsService,
        private SiteSettingsFactory $siteSettingsFactory,
        private SiteSettingsWriteVerifierInterface $writeGuard,
        private LoggerInterface $logger,
        private SiteFinder $siteFinder,
    ) {
    }

    /**
     * Creates and stores a TYPO3 Analytics API key for the site when it is active, and
     * no key has been provisioned yet. The apiKey value is only returned once
     * by the API and is stored encrypted in the site settings.
     *
     * A site attached to an active site uses the key of the active site and never creates a key.
     * When the active site gets a key, its attached sites get the same key. When this write
     * failed, the next call for the attached site copies the key of the active site.
     *
     * @param array<string, mixed> $currentStatus the freshly fetched status response
     */
    public function provisionIfNeeded(Site $site, array $currentStatus): void
    {
        $siteIdentifier = $site->getIdentifier();

        if (($currentStatus['status'] ?? '') !== 'active') {
            return;
        }
        if (SiteAttachment::isAttached($site)) {
            $this->copyApiKeyOfActiveSite($site);
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
        try {
            $this->siteSettingsService->writeSettings($site, array_merge($existing, [
                'apiKeyId' => $result['apiKeyId'],
                'apiKey' => $encryptedApiKey,
            ]));
        } catch (SiteConfigurationWriteException $e) {
            $this->logger->error('ApiKeyService: Failed to write API key settings to site configuration.', [
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

        $this->shareWithAttachedSites($siteIdentifier, ['apiKeyId' => $result['apiKeyId'], 'apiKey' => $encryptedApiKey]);
    }

    /**
     * @param array{apiKeyId: string, apiKey: string} $apiKeySettings
     */
    private function shareWithAttachedSites(string $siteIdentifier, array $apiKeySettings): void
    {
        foreach ($this->siteFinder->getAllSites() as $attachedSite) {
            if (SiteAttachment::attachedTo($attachedSite) === $siteIdentifier) {
                $this->writeApiKeyOfActiveSite($attachedSite, $siteIdentifier, $apiKeySettings);
            }
        }
    }

    private function copyApiKeyOfActiveSite(Site $attachedSite): void
    {
        $settings = $this->siteSettingsFactory->loadLocalSettings($attachedSite->getIdentifier()) ?? [];
        if ((string)($settings['apiKeyId'] ?? '') !== '') {
            return;
        }
        $activeSiteIdentifier = SiteAttachment::attachedTo($attachedSite);
        $activeSettings = $this->siteSettingsFactory->loadLocalSettings($activeSiteIdentifier) ?? [];
        $apiKeyId = (string)($activeSettings['apiKeyId'] ?? '');
        $apiKey = (string)($activeSettings['apiKey'] ?? '');
        if ($apiKeyId === '' || $apiKey === '') {
            return;
        }
        $this->writeApiKeyOfActiveSite($attachedSite, $activeSiteIdentifier, ['apiKeyId' => $apiKeyId, 'apiKey' => $apiKey]);
    }

    /**
     * The core catches a failed write itself, so the method reads the settings back to find the error.
     *
     * @param array{apiKeyId: string, apiKey: string} $apiKeySettings
     */
    private function writeApiKeyOfActiveSite(Site $attachedSite, string $activeSiteIdentifier, array $apiKeySettings): void
    {
        $existing = $this->siteSettingsFactory->loadLocalSettings($attachedSite->getIdentifier()) ?? [];
        try {
            $this->siteSettingsService->writeSettings($attachedSite, array_merge($existing, $apiKeySettings));
            $this->writeGuard->assertSettingsPersisted($attachedSite, ['apiKeyId' => $apiKeySettings['apiKeyId']]);
        } catch (SiteConfigurationWriteException|AnalyticsApiException $e) {
            $this->logger->error('ApiKeyService: Failed to write the API key to an attached site. The next status refresh of the site tries again.', [
                'siteIdentifier' => $attachedSite->getIdentifier(),
                'activeSiteIdentifier' => $activeSiteIdentifier,
                'exception' => $e->getMessage(),
            ]);
            return;
        }
        $this->logger->info('ApiKeyService: API key of the active site written to an attached site.', [
            'siteIdentifier' => $attachedSite->getIdentifier(),
            'activeSiteIdentifier' => $activeSiteIdentifier,
        ]);
    }
}
