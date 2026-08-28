<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Site\Entity\Site;

interface SiteSettingsWriteGuardInterface
{
    /**
     * Checks that the site's config directory is writable before any API call is made.
     *
     * @throws AnalyticsApiException when the directory does not exist or is not writable
     */
    public function assertDirectoryWritable(Site $site): void;

    /**
     * Re-reads the persisted settings and verifies that every key in $expected matches.
     * Call this after writeSettings() to detect silent write failures.
     *
     * @param array<string, string> $expected key-value pairs that must be present after the write
     * @throws AnalyticsApiException when any value is absent or does not match
     */
    public function assertSettingsPersisted(Site $site, array $expected): void;
}
