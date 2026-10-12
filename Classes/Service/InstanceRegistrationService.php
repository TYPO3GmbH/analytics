<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;

readonly class InstanceRegistrationService implements InstanceRegistrationServiceInterface
{
    public function __construct(
        private AnalyticsApiClientInterface $apiClient,
        private CipherServiceInterface $cipherService,
        private SiteSettingsService $siteSettingsService,
        private SiteSettingsFactory $siteSettingsFactory,
        private SiteSettingsWriteVerifierInterface $writeGuard,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Registers a site instance with the Analytics API and persists the
     * returned credentials to site settings.
     *
     * @throws AnalyticsApiException when the API call fails or the response is incomplete
     */
    public function register(Site $site, string $email): void
    {
        $siteIdentifier = $site->getIdentifier();

        $this->writeGuard->assertDirectoryWritable($site);

        try {
            $data = $this->apiClient->registerInstance($site, $email);
        } catch (AnalyticsApiException $e) {
            $this->logger->error('Registration: API request failed.', ['siteIdentifier' => $siteIdentifier, 'reason' => $e->reason]);
            throw $e;
        }

        $instanceId = (string)($data['instanceId'] ?? '');
        $websiteId = (string)($data['websiteId'] ?? '');
        $instanceSecret = (string)($data['instanceSecret'] ?? '');

        if ($websiteId === '' || $instanceId === '') {
            // Log only the response shape, never the values — $data may still hold the plaintext instanceSecret.
            $this->logger->error('Registration: API response incomplete.', ['siteIdentifier' => $siteIdentifier, 'receivedKeys' => array_keys($data)]);
            throw new AnalyticsApiException('API response is missing websiteId or instanceId.', 0);
        }

        $encryptedSecret = $instanceSecret !== '' ? $this->cipherService->encrypt($instanceSecret) : '';

        $existing = $this->siteSettingsFactory->loadLocalSettings($siteIdentifier) ?? [];
        try {
            $this->siteSettingsService->writeSettings($site, array_merge($existing, [
                'websiteId' => $websiteId,
                'instanceId' => $instanceId,
                'instanceSecret' => $encryptedSecret,
            ]));
        } catch (SiteConfigurationWriteException $e) {
            $this->logger->error('Registration: writeSettings threw.', ['siteIdentifier' => $siteIdentifier, 'exception' => $e->getMessage()]);
            throw new AnalyticsApiException('Settings could not be written to config/sites/' . $siteIdentifier . '/settings.yaml. Check file system permissions for this directory.', 0);
        }

        try {
            $this->writeGuard->assertSettingsPersisted($site, ['websiteId' => $websiteId]);
        } catch (AnalyticsApiException $e) {
            $this->logger->error('Registration: settings could not be persisted.', ['siteIdentifier' => $siteIdentifier]);
            throw $e;
        }

        $this->logger->info('Site successfully registered.', ['siteIdentifier' => $siteIdentifier, 'websiteId' => $websiteId]);
    }

    /**
     * Settings of the target site that the attached site copies. The site then tracks into
     * the website of the target and reads its analytics with the same credentials.
     */
    private const ATTACHED_SETTINGS = ['websiteId', 'instanceId', 'instanceSecret', 'externalWebsiteId', 'apiKeyId', 'apiKey', 'trackingCode', 'status'];

    public function attach(Site $site, Site $target): void
    {
        $siteIdentifier = $site->getIdentifier();
        $targetSettings = $this->siteSettingsFactory->loadLocalSettings($target->getIdentifier()) ?? [];
        $websiteId = (string)($targetSettings['websiteId'] ?? '');
        $instanceId = (string)($targetSettings['instanceId'] ?? '');
        $encryptedSecret = (string)($targetSettings['instanceSecret'] ?? '');
        if ($websiteId === '' || $instanceId === '' || $encryptedSecret === '') {
            throw new AnalyticsApiException('The selected site has no analytics credentials.', 0);
        }

        $this->writeGuard->assertDirectoryWritable($site);

        $domain = rtrim($site->getBase()->__toString(), '/');
        try {
            $this->apiClient->addWhitelistedDomain($websiteId, $instanceId, $this->cipherService->decrypt($encryptedSecret), $domain);
        } catch (AnalyticsApiException $e) {
            $this->logger->error('Attach: API request failed.', ['siteIdentifier' => $siteIdentifier, 'target' => $target->getIdentifier(), 'reason' => $e->reason]);
            throw $e;
        }

        $copied = array_intersect_key($targetSettings, array_flip(self::ATTACHED_SETTINGS));
        $existing = $this->siteSettingsFactory->loadLocalSettings($siteIdentifier) ?? [];
        try {
            $this->siteSettingsService->writeSettings($site, array_merge($existing, $copied, ['attachedTo' => $target->getIdentifier()]));
        } catch (SiteConfigurationWriteException $e) {
            $this->logger->error('Attach: writeSettings threw.', ['siteIdentifier' => $siteIdentifier, 'exception' => $e->getMessage()]);
            throw new AnalyticsApiException('Settings could not be written to config/sites/' . $siteIdentifier . '/settings.yaml. Check file system permissions for this directory.', 0);
        }

        $this->writeGuard->assertSettingsPersisted($site, ['websiteId' => $websiteId]);
        $this->logger->info('Site attached to an active site.', ['siteIdentifier' => $siteIdentifier, 'target' => $target->getIdentifier(), 'websiteId' => $websiteId]);
    }

    public function detach(Site $site): void
    {
        $siteIdentifier = $site->getIdentifier();
        $settings = $this->siteSettingsFactory->loadLocalSettings($siteIdentifier) ?? [];
        $websiteId = (string)($settings['websiteId'] ?? '');
        $instanceId = (string)($settings['instanceId'] ?? '');
        $encryptedSecret = (string)($settings['instanceSecret'] ?? '');
        if ((string)($settings['attachedTo'] ?? '') === '' || $websiteId === '' || $instanceId === '' || $encryptedSecret === '') {
            throw new AnalyticsApiException('The site is not added to an active site.', 0);
        }

        $this->writeGuard->assertDirectoryWritable($site);

        $host = (string)$site->getBase()->getHost();
        try {
            $this->apiClient->removeWhitelistedDomain($websiteId, $instanceId, $this->cipherService->decrypt($encryptedSecret), $host);
        } catch (AnalyticsApiException $e) {
            $this->logger->error('Detach: API request failed.', ['siteIdentifier' => $siteIdentifier, 'reason' => $e->reason]);
            throw $e;
        }

        $remaining = array_diff_key($settings, array_flip([...self::ATTACHED_SETTINGS, 'attachedTo']));
        try {
            $this->siteSettingsService->writeSettings($site, $remaining);
        } catch (SiteConfigurationWriteException $e) {
            $this->logger->error('Detach: writeSettings threw.', ['siteIdentifier' => $siteIdentifier, 'exception' => $e->getMessage()]);
            throw new AnalyticsApiException('Settings could not be written to config/sites/' . $siteIdentifier . '/settings.yaml. Check file system permissions for this directory.', 0);
        }

        $this->logger->info('Site detached from the active site.', ['siteIdentifier' => $siteIdentifier, 'websiteId' => $websiteId]);
    }
}
