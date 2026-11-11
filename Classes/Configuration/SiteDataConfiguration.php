<?php

declare(strict_types=1);

namespace T3G\Analytics\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Location of the site data: settings.yaml (default) or <siteDataPath>/<identifier>.yaml.
 */
final readonly class SiteDataConfiguration
{
    private string $directory;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        $siteDataPath = rtrim(trim((string)($extensionConfiguration->get('analytics')['siteDataPath'] ?? '')), '/');
        if ($siteDataPath !== '' && !PathUtility::isAbsolutePath($siteDataPath)) {
            $siteDataPath = Environment::getProjectPath() . '/' . $siteDataPath;
        }
        $this->directory = $siteDataPath;
    }

    /**
     * Absolute directory, or empty for settings.yaml.
     */
    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function usesSiteSettings(): bool
    {
        return $this->directory === '';
    }
}
