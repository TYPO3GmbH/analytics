<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use T3G\Analytics\Configuration\SiteDataConfiguration;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Values missing in the file come from the site settings. The first write copies all values into the file.
 */
final class SiteDataStorage implements SiteDataStorageInterface
{
    /** @var array<string, array<string, mixed>|null> null: the file exists, but cannot be read */
    private array $fileData = [];

    public function __construct(
        private readonly SiteDataConfiguration $configuration,
        private readonly SiteSettingsService $siteSettingsService,
        private readonly SiteSettingsFactory $siteSettingsFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function get(Site $site, string $key): string
    {
        if (!$this->configuration->usesSiteSettings()) {
            $data = $this->loadFile($site);
            // Do not use old values of the site settings when the file is broken.
            if ($data === null) {
                return '';
            }
            if (array_key_exists($key, $data)) {
                return is_scalar($data[$key]) ? (string)$data[$key] : '';
            }
        }
        return $this->getFromSiteSettings($site, $key);
    }

    public function write(Site $site, array $values): void
    {
        if ($this->configuration->usesSiteSettings()) {
            $existing = $this->siteSettingsFactory->loadLocalSettings($site->getIdentifier()) ?? [];
            $this->siteSettingsService->writeSettings($site, array_merge($existing, $values));
            return;
        }

        $fileName = $this->getFileName($site);
        $this->createDirectory(dirname($fileName));
        // Open the lock file read-only: CLI and web server may run as different users.
        $lockFileName = $fileName . '.lock';
        if (!is_file($lockFileName) && @touch($lockFileName)) {
            GeneralUtility::fixPermissions($lockFileName);
        }
        $lock = @fopen($lockFileName, 'r');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new SiteConfigurationWriteException('Unable to lock ' . $this->getLocation($site), 1761724802);
        }
        try {
            // Read again: another request can have changed the file.
            unset($this->fileData[$site->getIdentifier()]);
            $data = $this->loadFile($site);
            if ($data === null) {
                throw new SiteConfigurationWriteException('Unable to read ' . $this->getLocation($site), 1761724803);
            }
            $fallback = [];
            foreach (array_diff(self::KEYS, array_keys($data)) as $key) {
                $fallback[$key] = $this->getFromSiteSettings($site, $key);
            }
            $this->writeFile($site, array_merge($fallback, $data, $values));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function loadPersisted(Site $site): array
    {
        if ($this->configuration->usesSiteSettings()) {
            return $this->siteSettingsFactory->loadLocalSettings($site->getIdentifier()) ?? [];
        }
        unset($this->fileData[$site->getIdentifier()]);
        return $this->loadFile($site) ?? [];
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
        $fileName = $this->configuration->usesSiteSettings()
            ? Environment::getConfigPath() . '/sites/' . $site->getIdentifier() . '/settings.yaml'
            : $this->getFileName($site);
        $projectPath = Environment::getProjectPath() . '/';
        return str_starts_with($fileName, $projectPath) ? substr($fileName, strlen($projectPath)) : $fileName;
    }

    private function getFromSiteSettings(Site $site, string $key): string
    {
        try {
            $value = $site->getSettings()->get($key, '');
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to read site settings.', ['siteIdentifier' => $site->getIdentifier(), 'key' => $key, 'exception' => $e]);
            return '';
        }
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @return array<string, mixed>|null null when the file exists, but cannot be read or parsed
     */
    private function loadFile(Site $site): ?array
    {
        $identifier = $site->getIdentifier();
        if (array_key_exists($identifier, $this->fileData)) {
            return $this->fileData[$identifier];
        }

        $fileName = $this->getFileName($site);
        if (!is_file($fileName)) {
            return $this->fileData[$identifier] = [];
        }
        $content = is_readable($fileName) ? @file_get_contents($fileName) : false;
        if ($content === false) {
            $this->logger->error('Cannot read the site data file. Check file system permissions.', ['siteIdentifier' => $identifier]);
            return $this->fileData[$identifier] = null;
        }
        try {
            $parsed = Yaml::parse($content);
        } catch (ParseException $e) {
            $this->logger->error('Cannot parse the site data file.', ['siteIdentifier' => $identifier, 'exception' => $e->getMessage()]);
            return $this->fileData[$identifier] = null;
        }
        return $this->fileData[$identifier] = is_array($parsed) ? $parsed : [];
    }

    /**
     * @param array<string, mixed> $data
     * @throws SiteConfigurationWriteException
     */
    private function writeFile(Site $site, array $data): void
    {
        $fileName = $this->getFileName($site);
        unset($this->fileData[$site->getIdentifier()]);

        $content = '# Written by the TYPO3 Analytics extension. Do not edit and do not commit this file.' . "\n"
            . Yaml::dump($data, 99, 2);
        // Atomic write: a request never reads a half-written file.
        $temporaryFileName = $fileName . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporaryFileName, $content) === false) {
            @unlink($temporaryFileName);
            throw new SiteConfigurationWriteException('Unable to write the site data to ' . $this->getLocation($site), 1761724801);
        }
        GeneralUtility::fixPermissions($temporaryFileName);
        if (!@rename($temporaryFileName, $fileName)) {
            @unlink($temporaryFileName);
            throw new SiteConfigurationWriteException('Unable to write the site data to ' . $this->getLocation($site), 1761724801);
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
            throw new SiteConfigurationWriteException('Unable to create the directory ' . $directory, 1761724800, $e);
        }
    }

    private function getFileName(Site $site): string
    {
        return $this->configuration->getDirectory() . '/' . basename($site->getIdentifier()) . '.yaml';
    }
}
