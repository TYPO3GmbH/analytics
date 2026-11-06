<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use T3G\Analytics\Configuration\SiteDataConfiguration;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\Exception as LockingException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * The extension always reads the values from the site settings.
 * With siteDataPath, the extension writes the values into a separate file. The settings.yaml must import this file.
 */
final readonly class SiteDataStorage implements SiteDataStorageInterface
{
    public function __construct(
        private SiteDataConfiguration $configuration,
        private SiteSettingsService $siteSettingsService,
        private SiteSettingsFactory $siteSettingsFactory,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
        #[Autowire(service: 'cache.core')]
        private FrontendInterface $coreCache,
    ) {
    }

    public function get(Site $site, string $key): mixed
    {
        try {
            $settings = $site->getSettings();
            $value = $settings->get(self::SETTINGS_PATH . '.' . $key);
            // Fallback for version 1.1 and earlier until the upgrade wizard moved the value. Remove in the next major version.
            if ($value === '' || $value === null) {
                $value = $settings->get($key) ?? $value;
            }
            return $value;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to read site settings.', ['siteIdentifier' => $site->getIdentifier(), 'key' => $key, 'exception' => $e]);
            return null;
        }
    }

    public function getString(Site $site, string $key): string
    {
        $value = $this->get($site, $key);
        return is_scalar($value) ? (string)$value : '';
    }

    public function write(Site $site, array $values): void
    {
        if (!$this->configuration->usesSiteSettings()) {
            // get() falls back to the old value when the file contains an empty value.
            if ($this->hasLegacyValueForEmptyValue($site, $values)) {
                throw new SiteConfigurationWriteException(
                    $this->getSettingsLocation($site) . ' contains values of version 1.1. Run the upgrade wizard "analytics_siteDataNamespaceMigration".',
                    1794408252
                );
            }
            $this->createDirectory(dirname($this->getFileName($site)));
        }
        $locker = $this->acquireLock($site);
        try {
            // Read in the lock: another request can have changed the data.
            if ($this->configuration->usesSiteSettings()) {
                $existing = $this->siteSettingsFactory->loadLocalSettings($site->getIdentifier()) ?? [];
                $this->siteSettingsService->writeSettings($site, $this->mergeValues($this->moveLegacyValues($existing), $values));
                return;
            }
            $data = $this->loadFile($site);
            if ($data === null) {
                throw new SiteConfigurationWriteException('Unable to read ' . $this->getLocation($site), 1794494912);
            }
            $this->writeFile($site, $this->mergeValues($data, $values));
        } finally {
            $locker->release();
        }
        // The site settings are cached in the core cache. SiteSettingsService::writeSettings() flushes it the same way.
        $this->coreCache->flush();
    }

    public function loadPersisted(Site $site): array
    {
        // With imports: this is the data that the next request reads from the site settings.
        $settings = $this->siteSettingsFactory->loadLocalSettings($site->getIdentifier()) ?? [];
        $values = [];
        foreach (self::KEYS as $key) {
            $identifier = self::SETTINGS_PATH . '.' . $key;
            // The settings editor of the core writes a map with the identifier as key, the extension writes a tree.
            if (array_key_exists($identifier, $settings)) {
                $values[$key] = $settings[$identifier];
            } elseif (ArrayUtility::isValidPath($settings, $identifier, '.')) {
                $values[$key] = ArrayUtility::getValueByPath($settings, $identifier, '.');
            }
        }
        return $values;
    }

    public function isWritable(Site $site): bool
    {
        if ($this->configuration->usesSiteSettings()) {
            $directory = Environment::getConfigPath() . '/sites/' . $site->getIdentifier();
            $fileName = $directory . '/settings.yaml';
            return is_dir($directory) && is_writable($directory) && (!is_file($fileName) || is_writable($fileName));
        }

        // Write the current data again: is_writable() does not detect a full disk, open_basedir or a broken symlink.
        try {
            $this->write($site, []);
        } catch (SiteConfigurationWriteException) {
            return false;
        }
        return true;
    }

    public function getLocation(Site $site): string
    {
        return $this->getRelativePath(
            $this->configuration->usesSiteSettings() ? $this->getSettingsFileName($site) : $this->getFileName($site)
        );
    }

    public function getSettingsLocation(Site $site): string
    {
        return $this->getRelativePath($this->getSettingsFileName($site));
    }

    public function usesSeparateFile(): bool
    {
        return !$this->configuration->usesSiteSettings();
    }

    public function isImportedBySiteSettings(Site $site): bool
    {
        if ($this->configuration->usesSiteSettings()) {
            return true;
        }
        $settingsFileName = $this->getSettingsFileName($site);
        try {
            $settings = is_file($settingsFileName) ? Yaml::parseFile($settingsFileName) : null;
        } catch (ParseException) {
            return false;
        }
        if (!is_array($settings) || !is_array($settings['imports'] ?? null)) {
            return false;
        }
        $fileName = PathUtility::getCanonicalPath($this->getFileName($site));
        foreach ($settings['imports'] as $import) {
            $resource = is_array($import) && is_string($import['resource'] ?? null) ? $import['resource'] : '';
            if ($resource === '') {
                continue;
            }
            // Resolve the path like the YamlFileLoader of the core.
            $importedFileName = PathUtility::isExtensionPath($resource) || PathUtility::isAbsolutePath($resource)
                ? GeneralUtility::getFileAbsFileName($resource)
                : PathUtility::getAbsolutePathOfRelativeReferencedFileOrPath($settingsFileName, $resource);
            if ($importedFileName === '') {
                continue;
            }
            $importedFileName = PathUtility::getCanonicalPath($importedFileName);
            // Without glob, TYPO3 14.3.11 and later do not load the site when the file is missing.
            if (($import['glob'] ?? false) === true && fnmatch($importedFileName, $fileName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function hasLegacyValueForEmptyValue(Site $site, array $values): bool
    {
        $emptyKeys = array_keys(array_filter($values, static fn (mixed $value): bool => $value === '' || $value === null));
        if ($emptyKeys === []) {
            return false;
        }
        $fileName = $this->getSettingsFileName($site);
        try {
            $settings = is_file($fileName) ? Yaml::parseFile($fileName) : null;
        } catch (ParseException) {
            return false;
        }
        if (!is_array($settings)) {
            return false;
        }
        foreach ($emptyKeys as $key) {
            $legacyValue = $settings[$key] ?? null;
            if (in_array($key, self::KEYS, true) && is_scalar($legacyValue) && (string)$legacyValue !== '') {
                return true;
            }
        }
        return false;
    }

    private function acquireLock(Site $site): LockingStrategyInterface
    {
        try {
            $locker = $this->lockFactory->createLocker('analytics-site-data-' . $site->getIdentifier());
            // In blocking mode, some strategies return false instead of an exception.
            if ($locker->acquire()) {
                return $locker;
            }
            $previous = null;
        } catch (LockingException $e) {
            $previous = $e;
        }
        throw new SiteConfigurationWriteException('Unable to lock ' . $this->getLocation($site), 1794494911, $previous);
    }

    /**
     * Without this step, get() can read an old value after the extension wrote an empty value into the tree.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function moveLegacyValues(array $settings): array
    {
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            $identifier = self::SETTINGS_PATH . '.' . $key;
            if (!array_key_exists($identifier, $settings) && !ArrayUtility::isValidPath($settings, $identifier, '.')) {
                $settings = ArrayUtility::setValueByPath($settings, $identifier, $settings[$key], '.');
            }
            unset($settings[$key]);
        }
        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function mergeValues(array $settings, array $values): array
    {
        foreach ($values as $key => $value) {
            $identifier = self::SETTINGS_PATH . '.' . $key;
            // Remove a value that the settings editor of the core wrote as a map. Otherwise the site has two values.
            unset($settings[$identifier]);
            $settings = ArrayUtility::setValueByPath($settings, $identifier, $value, '.');
        }
        return $settings;
    }

    /**
     * @return array<string, mixed>|null null when the file exists, but cannot be read or parsed
     */
    private function loadFile(Site $site): ?array
    {
        $identifier = $site->getIdentifier();
        $fileName = $this->getFileName($site);
        if (!is_file($fileName)) {
            return [];
        }
        $content = is_readable($fileName) ? @file_get_contents($fileName) : false;
        if ($content === false) {
            $this->logger->error('Cannot read the site data file. Check file system permissions.', ['siteIdentifier' => $identifier]);
            return null;
        }
        try {
            $parsed = Yaml::parse($content);
        } catch (ParseException $e) {
            $this->logger->error('Cannot parse the site data file.', ['siteIdentifier' => $identifier, 'exception' => $e->getMessage()]);
            return null;
        }
        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param array<string, mixed> $data
     * @throws SiteConfigurationWriteException
     */
    private function writeFile(Site $site, array $data): void
    {
        $fileName = $this->getFileName($site);
        $content = '# Written by the TYPO3 Analytics extension. Do not edit and do not commit this file.' . "\n"
            . '# Import this file in ' . $this->getSettingsLocation($site) . '.' . "\n"
            . Yaml::dump($data, 99, 2);
        // Atomic write: a request never reads a half-written file.
        $temporaryFileName = $fileName . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporaryFileName, $content) === false) {
            @unlink($temporaryFileName);
            throw new SiteConfigurationWriteException('Unable to write the site data to ' . $this->getLocation($site), 1794494910);
        }
        GeneralUtility::fixPermissions($temporaryFileName);
        if (!@rename($temporaryFileName, $fileName)) {
            @unlink($temporaryFileName);
            throw new SiteConfigurationWriteException('Unable to write the site data to ' . $this->getLocation($site), 1794494913);
        }
    }

    /**
     * @throws SiteConfigurationWriteException
     */
    private function createDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        try {
            GeneralUtility::mkdir_deep($directory);
        } catch (\RuntimeException $e) {
            throw new SiteConfigurationWriteException('Unable to create the directory ' . $directory, 1794494909, $e);
        }
    }

    private function getFileName(Site $site): string
    {
        return $this->configuration->getDirectory() . '/' . basename($site->getIdentifier()) . '.yaml';
    }

    private function getSettingsFileName(Site $site): string
    {
        return Environment::getConfigPath() . '/sites/' . $site->getIdentifier() . '/settings.yaml';
    }

    private function getRelativePath(string $fileName): string
    {
        $projectPath = Environment::getProjectPath() . '/';
        return str_starts_with($fileName, $projectPath) ? substr($fileName, strlen($projectPath)) : $fileName;
    }
}
