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
        // Only a site with own analytics can take other sites; the list stays flat.
        if ((string)($targetSettings['attachedTo'] ?? '') !== '') {
            throw new AnalyticsApiException('The selected site is itself attached to an active site.', 0);
        }
        // The API whitelists host names: the host of the site and the hosts of its languages.
        if ($site->getBase()->getHost() === '') {
            throw new AnalyticsApiException('The site has no domain in its base.', 0);
        }
        $hosts = SiteAttachment::hosts($site);

        $this->writeGuard->assertDirectoryWritable($site);

        $instanceSecret = $this->cipherService->decrypt($encryptedSecret);
        $addHost = fn (string $host) => $this->apiClient->addWhitelistedDomain($websiteId, $instanceId, $instanceSecret, $host);
        $removeHost = fn (string $host) => $this->apiClient->removeWhitelistedDomain($websiteId, $instanceId, $instanceSecret, $host);

        $added = [];
        try {
            foreach ($hosts as $host) {
                $addHost($host);
                $added[] = $host;
            }
        } catch (AnalyticsApiException $e) {
            $this->logger->error('Attach: API request failed.', ['siteIdentifier' => $siteIdentifier, 'target' => $target->getIdentifier(), 'reason' => $e->reason]);
            $this->rollBack('Attach', $siteIdentifier, $added, $removeHost);
            throw $e;
        }

        $copied = array_intersect_key($targetSettings, array_flip(self::ATTACHED_SETTINGS));
        $existing = $this->siteSettingsFactory->loadLocalSettings($siteIdentifier) ?? [];
        try {
            // Detach removes the stored hosts, also when the languages of the site change later.
            $this->writeSettings($site, array_merge($existing, $copied, ['attachedTo' => $target->getIdentifier(), 'attachedHosts' => $hosts]), ['websiteId' => $websiteId]);
        } catch (AnalyticsApiException $e) {
            // Without the settings, the site does not track into the website, so the hosts must leave the whitelist again.
            $this->logger->error('Attach: settings could not be written.', ['siteIdentifier' => $siteIdentifier, 'exception' => $e->getMessage()]);
            $this->rollBack('Attach', $siteIdentifier, $hosts, $removeHost);
            throw $e;
        }

        $this->logger->info('Site attached to an active site.', ['siteIdentifier' => $siteIdentifier, 'target' => $target->getIdentifier(), 'websiteId' => $websiteId, 'hosts' => $hosts]);
    }

    public function detach(Site $site): void
    {
        $siteIdentifier = $site->getIdentifier();
        $settings = $this->siteSettingsFactory->loadLocalSettings($siteIdentifier) ?? [];
        $websiteId = (string)($settings['websiteId'] ?? '');
        $instanceId = (string)($settings['instanceId'] ?? '');
        $encryptedSecret = (string)($settings['instanceSecret'] ?? '');
        if ((string)($settings['attachedTo'] ?? '') === '' || $websiteId === '' || $instanceId === '' || $encryptedSecret === '') {
            throw new AnalyticsApiException('The site is not attached to an active site.', 0);
        }

        $this->writeGuard->assertDirectoryWritable($site);

        // Attach stores the hosts that it added. Without stored hosts, detach removes only the settings.
        $hosts = array_values(array_filter(array_map('strval', (array)($settings['attachedHosts'] ?? [])), static fn (string $host): bool => $host !== ''));

        $instanceSecret = $this->cipherService->decrypt($encryptedSecret);
        $addHost = fn (string $host) => $this->apiClient->addWhitelistedDomain($websiteId, $instanceId, $instanceSecret, $host);
        $removeHost = fn (string $host) => $this->apiClient->removeWhitelistedDomain($websiteId, $instanceId, $instanceSecret, $host);

        $removed = [];
        try {
            foreach ($hosts as $host) {
                $removeHost($host);
                $removed[] = $host;
            }
        } catch (AnalyticsApiException $e) {
            $this->logger->error('Detach: API request failed.', ['siteIdentifier' => $siteIdentifier, 'reason' => $e->reason]);
            $this->rollBack('Detach', $siteIdentifier, $removed, $addHost);
            throw $e;
        }

        $remaining = array_diff_key($settings, array_flip([...self::ATTACHED_SETTINGS, 'attachedTo', 'attachedHosts']));
        try {
            $this->writeSettings($site, $remaining, ['attachedTo' => '']);
        } catch (AnalyticsApiException $e) {
            // The site still tracks into the website with the old settings, so the hosts must go back on the whitelist.
            $this->logger->error('Detach: settings could not be written.', ['siteIdentifier' => $siteIdentifier, 'exception' => $e->getMessage()]);
            $this->rollBack('Detach', $siteIdentifier, $hosts, $addHost);
            throw $e;
        }

        $this->logger->info('Site detached from the active site.', ['siteIdentifier' => $siteIdentifier, 'websiteId' => $websiteId, 'hosts' => $hosts]);
    }

    /**
     * Writes the settings and reads them back from the file.
     *
     * @param array<string, mixed> $settings
     * @param array<string, string> $expected
     * @throws AnalyticsApiException
     */
    private function writeSettings(Site $site, array $settings, array $expected): void
    {
        try {
            $this->siteSettingsService->writeSettings($site, $settings);
        } catch (SiteConfigurationWriteException) {
            throw new AnalyticsApiException('Settings could not be written to config/sites/' . $site->getIdentifier() . '/settings.yaml. Check file system permissions for this directory.', 0);
        }
        $this->writeGuard->assertSettingsPersisted($site, $expected);
    }

    /**
     * Undoes the whitelist changes for the given hosts. A failed rollback is only logged, so the
     * caller can report the original error.
     *
     * @param list<string> $hosts
     * @param callable(string): mixed $undo
     */
    private function rollBack(string $action, string $siteIdentifier, array $hosts, callable $undo): void
    {
        foreach ($hosts as $host) {
            try {
                $undo($host);
                $this->logger->info($action . ': whitelist change rolled back.', ['siteIdentifier' => $siteIdentifier, 'host' => $host]);
            } catch (\Throwable $e) {
                $this->logger->error($action . ': rollback of the whitelist change failed. Correct the whitelisted domains manually.', ['siteIdentifier' => $siteIdentifier, 'host' => $host, 'exception' => $e->getMessage()]);
            }
        }
    }
}
