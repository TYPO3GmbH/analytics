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
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Without siteDataPath, the extension reads and writes the values in the site settings.
 * With siteDataPath, the extension reads and writes the values in a separate file. The site settings do not contain the values.
 */
final readonly class SiteDataStorage implements SiteDataStorageInterface
{
    private const KEYS = [
        'websiteId',
        'instanceId',
        'instanceSecret',
        'trackingCode',
        'status',
        'apiKeyId',
        'apiKey',
        'externalWebsiteId',
    ];

    public function __construct(
        private SiteDataConfiguration $configuration,
        private SiteSettingsService $siteSettingsService,
        private SiteSettingsFactory $siteSettingsFactory,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
        #[Autowire(service: 'cache.runtime')]
        private FrontendInterface $runtimeCache,
    ) {
    }

    public function get(Site $site, string $key): mixed
    {
        if (!$this->configuration->usesSiteSettings()) {
            $data = $this->loadFileCached($site);
            if ($data !== null) {
                return $data[$key] ?? null;
            }
            // Fallback until the first write creates the file, e.g. after an installation sets siteDataPath.
        }
        try {
            return $site->getSettings()->get($key);
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
            $this->createDirectory(dirname($this->getFileName($site)));
        }
        $locker = $this->acquireLock($site);
        try {
            // Read in the lock: another request can have changed the data.
            if ($this->configuration->usesSiteSettings()) {
                $existing = $this->siteSettingsFactory->loadLocalSettings($site->getIdentifier()) ?? [];
                $this->siteSettingsService->writeSettings($site, array_merge($existing, $values));
                return;
            }
            $data = $this->loadFile($site);
            if ($data === null) {
                throw new SiteConfigurationWriteException('Unable to read ' . $this->getLocation($site), 1794494912);
            }
            if (!$this->containsValues($data)) {
                $data = array_merge($data, $this->loadValuesFromSiteSettings($site));
            }
            $this->writeFile($site, array_merge($data, $values));
        } finally {
            $this->runtimeCache->remove($this->getCacheIdentifier($site));
            $locker->release();
        }
    }

    public function loadPersisted(Site $site): array
    {
        if ($this->configuration->usesSiteSettings()) {
            return $this->siteSettingsFactory->loadLocalSettings($site->getIdentifier()) ?? [];
        }
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
        return $this->getRelativePath(
            $this->configuration->usesSiteSettings() ? $this->getSettingsFileName($site) : $this->getFileName($site)
        );
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
     * The middleware reads several values in each frontend request: parse the file only once.
     *
     * @return array<string, mixed>|null null when the file does not exist or contains no values of the extension
     */
    private function loadFileCached(Site $site): ?array
    {
        $cacheIdentifier = $this->getCacheIdentifier($site);
        $data = $this->runtimeCache->get($cacheIdentifier);
        if (is_array($data)) {
            return $data;
        }
        if (!is_file($this->getFileName($site))) {
            return null;
        }
        $data = $this->loadFile($site);
        // For example, a deployment tool created an empty shared file.
        if ($data !== null && !$this->containsValues($data)) {
            return null;
        }
        // An invalid file must not activate the fallback to old values. loadFile() logs the error.
        $data ??= [];
        $this->runtimeCache->set($cacheIdentifier, $data);
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function containsValues(array $data): bool
    {
        return array_intersect_key($data, array_flip(self::KEYS)) !== [];
    }

    /**
     * Values of an installation that used the site settings before siteDataPath was set.
     *
     * @return array<string, mixed>
     * @throws SiteConfigurationWriteException
     */
    private function loadValuesFromSiteSettings(Site $site): array
    {
        try {
            $settings = $this->siteSettingsFactory->loadLocalSettings($site->getIdentifier()) ?? [];
        } catch (\RuntimeException $e) {
            throw new SiteConfigurationWriteException('Unable to read the site settings of site ' . $site->getIdentifier(), 1795704081, $e);
        }
        return array_intersect_key($settings, array_flip(self::KEYS));
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

    private function getCacheIdentifier(Site $site): string
    {
        return 'analytics_site_data_' . md5($site->getIdentifier());
    }

    private function getRelativePath(string $fileName): string
    {
        $projectPath = Environment::getProjectPath() . '/';
        return str_starts_with($fileName, $projectPath) ? substr($fileName, strlen($projectPath)) : $fileName;
    }
}
