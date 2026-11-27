<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The messages of the exceptions go to the backend user. Only the log contains the file paths.
 */
final readonly class SiteSettingsWriteVerifier implements SiteSettingsWriteVerifierInterface
{
    private const SEE_LOG = ' See the TYPO3 log for details.';

    public function __construct(
        private SiteDataStorageInterface $siteDataStorage,
        private LoggerInterface $logger,
    ) {
    }

    public function assertDirectoryWritable(Site $site): void
    {
        if (!$this->siteDataStorage->isWritable($site)) {
            $this->logger->error('Cannot write the site data. Check file system permissions.', $this->getContext($site));
            throw new AnalyticsApiException(
                'Cannot write the site data for site "' . $site->getIdentifier() . '". Check file system permissions.' . self::SEE_LOG,
                0
            );
        }
    }

    public function assertSettingsPersisted(Site $site, array $expected): void
    {
        $persisted = $this->siteDataStorage->loadPersisted($site);

        foreach ($expected as $key => $value) {
            if (($persisted[$key] ?? '') === $value) {
                continue;
            }
            $this->logger->error('The site data does not contain the written values. Check file system permissions.', $this->getContext($site) + ['key' => $key]);
            throw new AnalyticsApiException(
                'Settings for site "' . $site->getIdentifier() . '" could not be persisted. Check file system permissions.' . self::SEE_LOG,
                0
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function getContext(Site $site): array
    {
        return ['siteIdentifier' => $site->getIdentifier(), 'location' => $this->siteDataStorage->getLocation($site)];
    }
}
