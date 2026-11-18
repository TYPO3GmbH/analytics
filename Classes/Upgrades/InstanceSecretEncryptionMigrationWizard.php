<?php

declare(strict_types=1);

namespace T3G\Analytics\Upgrades;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\SiteSettingsLockedException;
use T3G\Analytics\Service\CipherServiceInterface;
use T3G\Analytics\Service\SiteSettingsLock;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

#[UpgradeWizard('analytics_instanceSecretEncryptionMigration')]
final class InstanceSecretEncryptionMigrationWizard implements UpgradeWizardInterface
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly CipherServiceInterface $cipherService,
        private readonly SiteSettingsService $siteSettingsService,
        private readonly SiteSettingsFactory $siteSettingsFactory,
        private readonly LoggerInterface $logger,
        private readonly SiteSettingsLock $siteSettingsLock,
    ) {
    }

    public function getTitle(): string
    {
        return 'Analytics: Migrate instance secret encryption (v13 → v14)';
    }

    public function getDescription(): string
    {
        return 'Re-encrypts instanceSecret values stored in site settings from the TYPO3 v13 '
            . 'format ("ciphertext" key) to the TYPO3 v14 core cipher format ("cipher" key). '
            . 'Required after upgrading from TYPO3 v13 to v14.';
    }

    public function updateNecessary(): bool
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                $secret = $site->getSettings()->get('instanceSecret', '');
                if ($secret !== '' && $this->cipherService->isLegacyFormat($secret)) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return false;
    }

    public function executeUpdate(): bool
    {
        $success = true;
        foreach ($this->siteFinder->getAllSites() as $site) {
            $identifier = $site->getIdentifier();
            try {
                $encrypted = $site->getSettings()->get('instanceSecret', '');
            } catch (\Throwable) {
                continue;
            }
            if ($encrypted === '' || !$this->cipherService->isLegacyFormat($encrypted)) {
                continue;
            }
            try {
                $this->siteSettingsLock->run($identifier, function () use ($site): void {
                    $this->migrateWhileLocked($site);
                });
            } catch (SiteSettingsLockedException) {
                // Another request changes the settings. The secret stays in the legacy format, so the wizard runs again.
                $this->logger->warning('InstanceSecretEncryptionMigrationWizard: settings are locked by another request.', [
                    'siteIdentifier' => $identifier,
                ]);
                $success = false;
            } catch (\Throwable $e) {
                $this->logger->error('InstanceSecretEncryptionMigrationWizard: migration failed.', [
                    'siteIdentifier' => $identifier,
                    'exception' => $e->getMessage(),
                ]);
                $success = false;
            }
        }
        return $success;
    }

    private function migrateWhileLocked(Site $site): void
    {
        // Read the file again: another request may have changed the secret before this request got the lock.
        $existing = $this->siteSettingsFactory->loadLocalSettings($site->getIdentifier()) ?? [];
        $encrypted = (string)($existing['instanceSecret'] ?? '');
        if ($encrypted === '' || !$this->cipherService->isLegacyFormat($encrypted)) {
            return;
        }
        $reEncrypted = $this->cipherService->encrypt($this->cipherService->decrypt($encrypted));
        $this->siteSettingsService->writeSettings($site, array_merge($existing, ['instanceSecret' => $reEncrypted]));
    }


    public function getPrerequisites(): array
    {
        return [];
    }
}
