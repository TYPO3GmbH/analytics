<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Site\Entity\Site;

interface SiteSettingsWriteVerifierInterface
{
    /**
     * Asserts that the site configuration directory is writable.
     *
     * @throws \T3G\Analytics\Exception\AnalyticsApiException
     */
    public function assertDirectoryWritable(Site $site): void;

    /**
     * Asserts that the given settings were actually persisted to disk.
     * Call this after writeSettings() to detect silent write failures.
     *
     * @param array<string, mixed> $expected key-value pairs that must appear in the persisted settings
     * @throws \T3G\Analytics\Exception\AnalyticsApiException
     */
    public function assertSettingsPersisted(Site $site, array $expected): void;
}
