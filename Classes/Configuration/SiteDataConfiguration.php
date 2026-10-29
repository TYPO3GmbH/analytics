<?php

declare(strict_types=1);

namespace T3G\Analytics\Configuration;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Location of the site data: settings.yaml (default) or <siteDataPath>/<identifier>.yaml.
 */
final readonly class SiteDataConfiguration
{
    /**
     * Absolute directory, or empty for settings.yaml.
     */
    public function getDirectory(): string
    {
        $configured = getenv('TYPO3_ANALYTICS_SITE_DATA_PATH');
        if ($configured === false || $configured === '') {
            $configured = (string)($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] ?? '');
        }
        $configured = rtrim(trim($configured), '/');
        if ($configured === '') {
            return '';
        }
        if (!PathUtility::isAbsolutePath($configured)) {
            $configured = Environment::getProjectPath() . '/' . $configured;
        }
        return $configured;
    }

    public function usesSiteSettings(): bool
    {
        return $this->getDirectory() === '';
    }
}
