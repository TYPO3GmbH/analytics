<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Site\Entity\Site;

interface SiteDataStorageInterface
{
    /**
     * Reads the value from the site settings, or from the separate file with siteDataPath. Null when not set.
     * With siteDataPath, reads the site settings until the first write creates the file.
     */
    public function get(Site $site, string $key): mixed;

    /**
     * Same as get(). Empty string when not set or not a scalar value.
     */
    public function getString(Site $site, string $key): string;

    /**
     * Merges the values into the stored data: settings.yaml, or the separate file with siteDataPath.
     * The first write into the separate file copies the values of the extension from settings.yaml.
     *
     * @param array<string, mixed> $values
     * @throws SiteConfigurationWriteException
     */
    public function write(Site $site, array $values): void;

    /**
     * Reads the stored data again from the disk: settings.yaml, or the separate file with siteDataPath.
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
