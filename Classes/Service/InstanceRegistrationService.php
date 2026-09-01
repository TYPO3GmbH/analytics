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
}
