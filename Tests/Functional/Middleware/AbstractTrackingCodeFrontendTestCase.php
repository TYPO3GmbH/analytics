<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Middleware;

use Symfony\Component\Yaml\Yaml;
use T3G\Analytics\Tests\Fixtures\SiteDataSettings;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * A frontend request boots TYPO3 again, so set the configuration in $configurationToUseInTestInstance.
 */
abstract class AbstractTrackingCodeFrontendTestCase extends FunctionalTestCase
{
    protected const SITE = 'main';
    protected const DATA_PATH = 'analytics-data';
    protected const TRACKING_CODE = '<script>window.trackedBy = "analytics";</script>';

    protected array $coreExtensionsToLoad = [
        'core',
        'backend',
        'dashboard',
        'frontend',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->setUpFrontendRootPage(1, ['EXT:analytics/Tests/Functional/Middleware/Fixtures/page.typoscript']);
        $this->get(SiteWriter::class)->write(self::SITE, [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'dependencies' => ['t3g/analytics'],
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
            ],
        ]);
        // Remove data of the previous test.
        @unlink(Environment::getConfigPath() . '/sites/' . self::SITE . '/settings.yaml');
        GeneralUtility::rmdir($this->dataDirectory(), true);
        $this->flushSiteSettingsCache();
    }

    /**
     * @param array<string, string> $settings
     */
    protected function writeSettingsYaml(array $settings): void
    {
        $this->get(SiteWriter::class)->writeSettings(self::SITE, SiteDataSettings::tree($settings));
        $this->flushSiteSettingsCache();
    }

    /**
     * @param array<string, string> $settings
     */
    protected function writeSettingsYamlWithImport(array $settings = []): void
    {
        $yaml = "imports:\n  - { resource: '../../../" . self::DATA_PATH . '/' . self::SITE . ".yaml', glob: true }\n";
        if ($settings !== []) {
            $yaml .= Yaml::dump(SiteDataSettings::tree($settings), 99, 2);
        }
        file_put_contents(Environment::getConfigPath() . '/sites/' . self::SITE . '/settings.yaml', $yaml);
        $this->flushSiteSettingsCache();
    }

    /**
     * SiteWriter does not flush the cached site settings.
     */
    private function flushSiteSettingsCache(): void
    {
        $this->get(CacheManager::class)->getCache('core')->flush();
    }

    /**
     * @param array<string, string> $data
     */
    protected function writeSiteDataFile(array $data): void
    {
        $directory = $this->dataDirectory();
        GeneralUtility::mkdir_deep($directory);
        file_put_contents($directory . '/' . self::SITE . '.yaml', Yaml::dump(SiteDataSettings::tree($data), 99, 2));
    }

    protected function fetchPage(): string
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://example.com/'));
        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();
        self::assertStringContainsString('Hello', $html, 'The page must render');
        return $html;
    }

    protected function dataDirectory(): string
    {
        return Environment::getProjectPath() . '/' . self::DATA_PATH;
    }
}
