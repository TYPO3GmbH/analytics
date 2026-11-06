<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use T3G\Analytics\Service\SiteDataNamespaceMigration;
use T3G\Analytics\Service\SiteDataStorageInterface;
use T3G\Analytics\Tests\Fixtures\SiteDataSettings;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SiteDataNamespaceMigrationTest extends FunctionalTestCase
{
    private const SITE = 'main';
    private const DATA_PATH = 'analytics-data';
    private const IMPORT = "imports:\n  - { resource: '../../../" . self::DATA_PATH . "/main.yaml', glob: true }\n";

    private string $settingsFileName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->get(SiteWriter::class)->write(self::SITE, [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'dependencies' => ['t3g/analytics'],
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
            ],
        ]);
        $this->settingsFileName = Environment::getConfigPath() . '/sites/' . self::SITE . '/settings.yaml';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath']);
        GeneralUtility::rmdir(Environment::getProjectPath() . '/' . self::DATA_PATH, true);
        parent::tearDown();
    }

    #[Test]
    public function theMigrationMovesTheValuesIntoTheTreeAndKeepsImportsPlaceholdersAndOtherSettings(): void
    {
        $site = $this->site();
        file_put_contents(
            $this->settingsFileName,
            self::IMPORT
            . "websiteId: legacy-website-id\n"
            . "status: active\n"
            . "otherExtension:\n  host: '%env(OTHER_HOST)%'\n"
        );
        $subject = $this->buildSubject('');
        self::assertTrue($subject->isNecessary($site));

        $subject->migrate($site);

        self::assertSame([
            'imports' => [['resource' => '../../../' . self::DATA_PATH . '/main.yaml', 'glob' => true]],
            'otherExtension' => ['host' => '%env(OTHER_HOST)%'],
            'plugin' => ['tx_analytics' => ['settings' => ['websiteId' => 'legacy-website-id', 'status' => 'active']]],
        ], Yaml::parseFile($this->settingsFileName));
        self::assertFalse($subject->isNecessary($site));
    }

    #[Test]
    public function theMigrationKeepsAValueBelowTheNewPath(): void
    {
        $site = $this->site();
        file_put_contents(
            $this->settingsFileName,
            "status: cancelled\n" . Yaml::dump(SiteDataSettings::tree(['status' => 'active']), 99, 2)
        );

        $this->buildSubject('')->migrate($site);

        self::assertSame(SiteDataSettings::tree(['status' => 'active']), Yaml::parseFile($this->settingsFileName));
    }

    #[Test]
    public function withSeparateFileTheMigrationMovesTheValuesIntoTheFile(): void
    {
        $site = $this->site();
        file_put_contents($this->settingsFileName, self::IMPORT . "websiteId: legacy-website-id\nstatus: active\n");

        $this->buildSubject(self::DATA_PATH)->migrate($site);

        self::assertSame(
            ['imports' => [['resource' => '../../../' . self::DATA_PATH . '/main.yaml', 'glob' => true]]],
            Yaml::parseFile($this->settingsFileName)
        );
        self::assertSame(
            SiteDataSettings::tree(['websiteId' => 'legacy-website-id', 'status' => 'active']),
            Yaml::parseFile(Environment::getProjectPath() . '/' . self::DATA_PATH . '/main.yaml')
        );
    }

    #[Test]
    public function withSeparateFileTheMigrationKeepsAValueOfTheFile(): void
    {
        $site = $this->site();
        GeneralUtility::mkdir_deep(Environment::getProjectPath() . '/' . self::DATA_PATH);
        file_put_contents(
            Environment::getProjectPath() . '/' . self::DATA_PATH . '/main.yaml',
            Yaml::dump(SiteDataSettings::tree(['status' => 'active']), 99, 2)
        );
        file_put_contents($this->settingsFileName, self::IMPORT . "status: cancelled\nwebsiteId: legacy-website-id\n");

        $this->buildSubject(self::DATA_PATH)->migrate($site);

        self::assertSame(
            SiteDataSettings::tree(['status' => 'active', 'websiteId' => 'legacy-website-id']),
            Yaml::parseFile(Environment::getProjectPath() . '/' . self::DATA_PATH . '/main.yaml')
        );
    }

    #[Test]
    public function theMigrationIsNotNecessaryWithoutValuesOnTheTopLevel(): void
    {
        $site = $this->site();
        $subject = $this->buildSubject('');
        self::assertFalse($subject->isNecessary($site), 'No settings.yaml');

        $this->get(SiteWriter::class)->writeSettings(self::SITE, SiteDataSettings::tree(['websiteId' => 'w-1'], ['other' => 'value']));

        self::assertFalse($subject->isNecessary($site));
    }

    private function buildSubject(string $siteDataPath): SiteDataNamespaceMigration
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = $siteDataPath;
        return new SiteDataNamespaceMigration($this->get(SiteDataStorageInterface::class), $this->get(SiteWriter::class));
    }

    /**
     * Load the site before the test changes settings.yaml.
     */
    private function site(): Site
    {
        return $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE);
    }
}
