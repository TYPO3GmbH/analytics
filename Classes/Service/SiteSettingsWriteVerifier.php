<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;

final readonly class SiteSettingsWriteVerifier implements SiteSettingsWriteVerifierInterface
{
    public function __construct(
        private SiteSettingsFactory $siteSettingsFactory,
    ) {
    }

    public function assertDirectoryWritable(Site $site): void
    {
        $identifier = $site->getIdentifier();
        $configDir = Environment::getConfigPath() . '/sites/' . $identifier;

        if (!is_dir($configDir) || !is_writable($configDir)) {
            throw new AnalyticsApiException(
                'Cannot write to site configuration directory for "' . $identifier . '". Check file system permissions.',
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
                    'Settings for site "' . $identifier . '" could not be persisted. Check file system permissions.',
                    0
                );
            }
        }
    }

}
