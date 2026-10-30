<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Site\Entity\Site;

final readonly class SiteSettingsWriteVerifier implements SiteSettingsWriteVerifierInterface
{
    public function __construct(
        private SiteDataStorageInterface $siteDataStorage,
    ) {
    }

    public function assertDirectoryWritable(Site $site): void
    {
        if (!$this->siteDataStorage->isWritable($site)) {
            throw new AnalyticsApiException(
                'Cannot write to ' . $this->siteDataStorage->getLocation($site) . ' for site "' . $site->getIdentifier() . '". Check file system permissions.',
                0
            );
        }
    }

    public function assertSettingsPersisted(Site $site, array $expected): void
    {
        $persisted = $this->siteDataStorage->loadPersisted($site);

        foreach ($expected as $key => $value) {
            if (($persisted[$key] ?? '') !== $value) {
                throw new AnalyticsApiException(
                    'Settings for site "' . $site->getIdentifier() . '" could not be persisted. Check file system permissions.',
                    0
                );
            }
        }
    }
}
