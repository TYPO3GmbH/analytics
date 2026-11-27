<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use T3G\Analytics\Configuration\SiteDataConfiguration;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ArrayUtility;

/**
 * Reads and writes settings.yaml without processing: placeholders stay.
 * The caller must flush the core cache.
 */
final readonly class SiteDataNamespaceMigration
{
    public function __construct(
        private SiteDataStorageInterface $siteDataStorage,
        private SiteDataConfiguration $configuration,
        private SiteWriter $siteWriter,
    ) {
    }

    public function isNecessary(Site $site): bool
    {
        return $this->isNecessaryFor($this->loadSettingsYaml($site) ?? []);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function isNecessaryFor(array $settings): bool
    {
        if ($this->getLegacyValues($settings) !== []) {
            return true;
        }
        // With siteDataPath, the extension ignores the values below SETTINGS_PATH in settings.yaml.
        return !$this->configuration->usesSiteSettings() && $this->removeValuesBelowSettingsPath($settings) !== $settings;
    }

    /**
     * @throws SiteConfigurationWriteException
     */
    public function migrate(Site $site): void
    {
        $settings = $this->loadSettingsYaml($site);
        if ($settings === null || !$this->isNecessaryFor($settings)) {
            return;
        }
        $legacyValues = $this->getLegacyValues($settings);

        if (!$this->configuration->usesSiteSettings()) {
            // The first write copies the values from settings.yaml into the separate file. An existing file has priority.
            $this->siteDataStorage->write($site, []);
            $settings = $this->removeValuesBelowSettingsPath($settings);
        } else {
            foreach ($legacyValues as $key => $value) {
                $identifier = SiteDataStorageInterface::SETTINGS_PATH . '.' . $key;
                $existing = $settings[$identifier]
                    ?? (ArrayUtility::isValidPath($settings, $identifier, '.') ? ArrayUtility::getValueByPath($settings, $identifier, '.') : null);
                // Same priority as SiteDataStorage::get(): keep a value below the new path, but not an empty value.
                if ($existing === '' || $existing === null) {
                    unset($settings[$identifier]);
                    $settings = ArrayUtility::setValueByPath($settings, $identifier, $value, '.');
                }
            }
        }

        foreach (array_keys($legacyValues) as $key) {
            unset($settings[$key]);
        }
        $this->siteWriter->writeSettings($site->getIdentifier(), $settings);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function removeValuesBelowSettingsPath(array $settings): array
    {
        foreach (SiteDataStorageInterface::KEYS as $key) {
            $identifier = SiteDataStorageInterface::SETTINGS_PATH . '.' . $key;
            unset($settings[$identifier]);
            if (ArrayUtility::isValidPath($settings, $identifier, '.')) {
                $settings = ArrayUtility::removeByPath($settings, $identifier, '.');
            }
        }
        foreach ([SiteDataStorageInterface::SETTINGS_PATH, 'plugin.tx_analytics', 'plugin'] as $path) {
            if (ArrayUtility::isValidPath($settings, $path, '.') && ArrayUtility::getValueByPath($settings, $path, '.') === []) {
                $settings = ArrayUtility::removeByPath($settings, $path, '.');
            }
        }
        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, string>
     */
    private function getLegacyValues(array $settings): array
    {
        $values = [];
        foreach (SiteDataStorageInterface::KEYS as $key) {
            if (array_key_exists($key, $settings) && is_scalar($settings[$key])) {
                $values[$key] = (string)$settings[$key];
            }
        }
        return $values;
    }

    /**
     * @return array<string, mixed>|null null when the file does not exist or cannot be parsed
     */
    private function loadSettingsYaml(Site $site): ?array
    {
        $fileName = Environment::getConfigPath() . '/sites/' . $site->getIdentifier() . '/settings.yaml';
        if (!is_file($fileName)) {
            return null;
        }
        try {
            $settings = Yaml::parseFile($fileName);
        } catch (ParseException) {
            return null;
        }
        return is_array($settings) ? $settings : null;
    }
}
