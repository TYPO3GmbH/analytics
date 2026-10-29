<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Site\Entity\Site;

interface SiteDataStorageInterface
{
    public const KEYS = [
        'websiteId',
        'instanceId',
        'instanceSecret',
        'trackingCode',
        'status',
        'apiKeyId',
        'apiKey',
        'externalWebsiteId',
    ];

    /**
     * Reads the value from the site settings. Empty string when not set.
     */
    public function get(Site $site, string $key): string;

    /**
     * Merges the values into the stored data: settings.yaml, or the separate file with siteDataPath.
     *
     * @param array<string, string> $values
     * @throws SiteConfigurationWriteException
     */
    public function write(Site $site, array $values): void;

    /**
     * Reads the local site settings again from the disk, with imports.
     *
     * @return array<string, mixed>
     */
    public function loadPersisted(Site $site): array;

    /**
     * With a separate file: writes the current data again.
     */
    public function isWritable(Site $site): bool;

    /**
     * File path, relative to the project root when possible.
     */
    public function getLocation(Site $site): string;

    /**
     * Path of the settings.yaml, relative to the project root when possible.
     */
    public function getSettingsLocation(Site $site): string;

    /**
     * True with siteDataPath: the settings.yaml must import the separate file.
     */
    public function usesSeparateFile(): bool;

    /**
     * True when the settings.yaml imports the separate file with "glob: true". Always true without siteDataPath.
     */
    public function isImportedBySiteSettings(Site $site): bool;
}
