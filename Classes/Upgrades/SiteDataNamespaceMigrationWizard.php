<?php

declare(strict_types=1);

namespace T3G\Analytics\Upgrades;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use T3G\Analytics\Service\SiteDataNamespaceMigration;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

#[UpgradeWizard('analytics_siteDataNamespaceMigration')]
final readonly class SiteDataNamespaceMigrationWizard implements UpgradeWizardInterface
{
    public function __construct(
        private SiteFinder $siteFinder,
        private SiteDataNamespaceMigration $migration,
        private LoggerInterface $logger,
        #[Autowire(service: 'cache.core')]
        private FrontendInterface $coreCache,
    ) {
    }

    public function getTitle(): string
    {
        return 'Analytics: Move the site data to plugin.tx_analytics.settings';
    }

    public function getDescription(): string
    {
        return 'Moves the values of the extension (websiteId, instanceId, instanceSecret, trackingCode, status, '
            . 'apiKeyId, apiKey and externalWebsiteId) in settings.yaml to plugin.tx_analytics.settings. '
            . 'With siteDataPath, the wizard moves the values into the separate file. '
            . 'Run "Analytics: Migrate instance secret encryption" after this wizard.';
    }

    public function updateNecessary(): bool
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($this->migration->isNecessary($site)) {
                return true;
            }
        }
        return false;
    }

    public function executeUpdate(): bool
    {
        $success = true;
        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                $this->migration->migrate($site);
            } catch (\Throwable $e) {
                $this->logger->error('SiteDataNamespaceMigrationWizard: migration failed.', [
                    'siteIdentifier' => $site->getIdentifier(),
                    'exception' => $e->getMessage(),
                ]);
                $success = false;
            }
        }
        $this->coreCache->flush();
        return $success;
    }

    public function getPrerequisites(): array
    {
        return [];
    }
}
