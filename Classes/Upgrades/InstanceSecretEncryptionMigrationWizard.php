<?php

declare(strict_types=1);

namespace T3G\Analytics\Upgrades;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Service\CipherServiceInterface;
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
            if ($encrypted === '' || !$this->isLegacyFormat($encrypted)) {
                continue;
            }
            try {
                $plaintext = $this->cipherService->decrypt($encrypted);
                $reEncrypted = $this->cipherService->encrypt($plaintext);
                $existing = $this->siteSettingsFactory->loadLocalSettings($identifier) ?? [];
                $this->siteSettingsService->writeSettings($site, array_merge($existing, ['instanceSecret' => $reEncrypted]));
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


    public function getPrerequisites(): array
    {
        return [];
    }
}
