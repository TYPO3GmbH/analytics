<?php

declare(strict_types=1);

namespace T3G\Analytics\Configuration;

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Location of the site data: settings.yaml (default) or <siteDataPath>/<identifier>.yaml.
 */
final class SiteDataConfiguration
{
    private ?string $directory = null;

    public function __construct(private readonly ExtensionConfiguration $extensionConfiguration)
    {
    }

    /**
     * Absolute directory, or empty for settings.yaml.
     */
    public function getDirectory(): string
    {
        return $this->directory ??= $this->readDirectory();
    }

    public function usesSiteSettings(): bool
    {
        return $this->getDirectory() === '';
    }

    private function readDirectory(): string
    {
        try {
            $siteDataPath = (string)$this->extensionConfiguration->get('analytics', 'siteDataPath');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return '';
        }
        $siteDataPath = rtrim(trim($siteDataPath), '/');
        if ($siteDataPath !== '' && !PathUtility::isAbsolutePath($siteDataPath)) {
            $siteDataPath = Environment::getProjectPath() . '/' . $siteDataPath;
        }
        return $siteDataPath;
    }
}
