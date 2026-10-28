<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Exception\SiteAlreadyRegisteredException;
use T3G\Analytics\Exception\SiteSettingsLockedException;
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
        private SiteSettingsLock $siteSettingsLock,
    ) {
    }

    /**
     * Registers a site instance with the Analytics API and persists the
     * returned credentials to site settings.
     *
     * @throws AnalyticsApiException when the API call fails or the response is incomplete
     * @throws SiteSettingsLockedException when another request changes the settings of the site
     * @throws SiteAlreadyRegisteredException when the settings already contain a websiteId
     */
    public function register(Site $site, string $email): void
    {
        $siteIdentifier = $site->getIdentifier();

        $this->writeGuard->assertDirectoryWritable($site);

        // Hold the lock before the API call: when the settings cannot be written,
        // the middleware must not register a new instance.
        try {
            $websiteId = $this->siteSettingsLock->run($siteIdentifier, fn (): string => $this->registerWhileLocked($site, $email));
        } catch (SiteSettingsLockedException $e) {
            $this->logger->warning('Registration: settings are locked by another request.', ['siteIdentifier' => $siteIdentifier]);
            throw $e;
        }

        $this->logger->info('Site successfully registered.', ['siteIdentifier' => $siteIdentifier, 'websiteId' => $websiteId]);
    }

    private function registerWhileLocked(Site $site, string $email): string
    {
        $siteIdentifier = $site->getIdentifier();

        // Read the file, not the Site object: another request may have registered the site a moment before.
        $existing = $this->siteSettingsFactory->loadLocalSettings($siteIdentifier) ?? [];
        if ((string)($existing['websiteId'] ?? '') !== '') {
            $this->logger->warning('Registration: site is already registered.', ['siteIdentifier' => $siteIdentifier]);
            throw new SiteAlreadyRegisteredException('The site is already registered.', 1760521202);
        }

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

        return $websiteId;
    }
}
