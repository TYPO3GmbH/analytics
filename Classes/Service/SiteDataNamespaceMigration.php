<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ArrayUtility;

/**
 * Reads and writes settings.yaml without processing: imports and placeholders stay.
 * The caller must flush the core cache.
 */
final readonly class SiteDataNamespaceMigration
{
    public function __construct(
        private SiteDataStorageInterface $siteDataStorage,
        private SiteWriter $siteWriter,
    ) {
    }

    public function isNecessary(Site $site): bool
    {
        return $this->getLegacyValues($this->loadSettingsYaml($site) ?? []) !== [];
    }

    /**
     * @throws SiteConfigurationWriteException
     */
    public function migrate(Site $site): void
    {
        $settings = $this->loadSettingsYaml($site);
        $legacyValues = $this->getLegacyValues($settings ?? []);
        if ($settings === null || $legacyValues === []) {
            return;
        }

        if ($this->siteDataStorage->usesSeparateFile()) {
            // Do not replace a value that the extension already wrote into the separate file.
            $existing = $this->siteDataStorage->loadPersisted($site);
            $this->siteDataStorage->write($site, array_diff_key($legacyValues, $existing));
        } else {
            foreach ($legacyValues as $key => $value) {
                $identifier = SiteDataStorageInterface::SETTINGS_PATH . '.' . $key;
                // Keep a value below the new path, also when the settings editor of the core wrote it as a map.
                if (!array_key_exists($identifier, $settings) && !ArrayUtility::isValidPath($settings, $identifier, '.')) {
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
