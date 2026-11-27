<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Site\Entity\Site;

interface SiteDataStorageInterface
{
    public const SETTINGS_PATH = 'plugin.tx_analytics.settings';

    /**
     * Keys below SETTINGS_PATH.
     */
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
     * Without siteDataPath: reads the value below SETTINGS_PATH from the site settings.
     * Falls back to the key on the top level (version 1.1 and earlier) until the upgrade wizard ran.
     * With siteDataPath: reads the value below SETTINGS_PATH from the separate file. Reads the site settings until the first write creates the file.
     * Null when not set.
     */
    public function get(Site $site, string $key): mixed;

    /**
     * Same as get(). Empty string when not set or not a scalar value.
     */
    public function getString(Site $site, string $key): string;

    /**
     * Merges the values into the stored data: settings.yaml, or the separate file with siteDataPath.
     * The first write into the separate file copies the values of the extension from settings.yaml.
     * In settings.yaml and in the separate file, the values are below SETTINGS_PATH.
     *
     * @param array<string, mixed> $values
     * @throws SiteConfigurationWriteException
     */
    public function write(Site $site, array $values): void;

    /**
     * Reads the stored data again from the disk: settings.yaml, or the separate file with siteDataPath.
     * Returns the values with the keys of KEYS.
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
}
