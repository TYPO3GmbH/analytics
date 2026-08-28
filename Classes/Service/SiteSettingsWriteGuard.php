<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;

readonly class SiteSettingsWriteGuard implements SiteSettingsWriteGuardInterface
{
    public function __construct(
        private SiteSettingsFactory $siteSettingsFactory,
        private string $sitesConfigPath = '',
    ) {
    }

    public function assertDirectoryWritable(Site $site): void
    {
        $identifier = $site->getIdentifier();
        $configDir = $this->resolveSitesConfigPath() . '/' . $identifier;

        if (!is_dir($configDir) || !is_writable($configDir)) {
            throw new AnalyticsApiException(
                'Cannot write to config/sites/' . $identifier . '/. Check file system permissions for this directory.',
                0
            );
        }
    }

    public function assertSettingsPersisted(Site $site, array $expected): void
    {
        $identifier = $site->getIdentifier();
        $persisted = $this->siteSettingsFactory->loadLocalSettings($identifier) ?? [];

        foreach ($expected as $key => $value) {
            if (($persisted[$key] ?? '') !== $value) {
                throw new AnalyticsApiException(
                    'Settings could not be written to config/sites/' . $identifier . '/settings.yaml. Check file system permissions for this directory.',
                    0
                );
            }
        }
    }

    private function resolveSitesConfigPath(): string
    {
        return $this->sitesConfigPath !== '' ? $this->sitesConfigPath : Environment::getConfigPath() . '/sites';
    }
}
