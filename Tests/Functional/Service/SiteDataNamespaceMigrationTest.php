<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use T3G\Analytics\Configuration\SiteDataConfiguration;
use T3G\Analytics\Service\SiteDataNamespaceMigration;
use T3G\Analytics\Service\SiteDataStorageInterface;
use T3G\Analytics\Tests\Fixtures\SiteDataSettings;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SiteDataNamespaceMigrationTest extends FunctionalTestCase
{
    private const SITE = 'main';
    private const DATA_PATH = 'analytics-data';

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
    public function theMigrationMovesTheValuesIntoTheTreeAndKeepsPlaceholdersAndOtherSettings(): void
    {
        $site = $this->site();
        file_put_contents(
            $this->settingsFileName,
            "websiteId: legacy-website-id\n"
            . "status: active\n"
            . "otherExtension:\n  host: '%env(OTHER_HOST)%'\n"
        );
        $subject = $this->buildSubject('');
        self::assertTrue($subject->isNecessary($site));

        $subject->migrate($site);

        self::assertSame([
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
        file_put_contents(
            $this->settingsFileName,
            "websiteId: legacy-website-id\nstatus: cancelled\n"
            . Yaml::dump(SiteDataSettings::tree(['status' => 'active'], ['otherExtension' => ['setting' => 'keep-me']]), 99, 2)
        );
        $subject = $this->buildSubject(self::DATA_PATH);
        self::assertTrue($subject->isNecessary($site));

        $subject->migrate($site);

        self::assertSame(['otherExtension' => ['setting' => 'keep-me']], Yaml::parseFile($this->settingsFileName));
        self::assertSame(SiteDataSettings::tree(['status' => 'active', 'websiteId' => 'legacy-website-id']), $this->readDataFile());
        self::assertFalse($subject->isNecessary($site));
    }

    #[Test]
    public function withSeparateFileTheMigrationKeepsAnExistingFileAndRemovesTheValuesFromTheSettingsYaml(): void
    {
        $site = $this->site();
        GeneralUtility::mkdir_deep(Environment::getProjectPath() . '/' . self::DATA_PATH);
        file_put_contents(Environment::getProjectPath() . '/' . self::DATA_PATH . '/main.yaml', Yaml::dump(SiteDataSettings::tree(['status' => 'active']), 99, 2));
        file_put_contents($this->settingsFileName, "status: cancelled\n" . Yaml::dump(SiteDataSettings::tree(['websiteId' => 'w-1']), 99, 2));

        $this->buildSubject(self::DATA_PATH)->migrate($site);

        self::assertSame(SiteDataSettings::tree(['status' => 'active']), $this->readDataFile());
        self::assertSame([], Yaml::parseFile($this->settingsFileName) ?? []);
    }

    #[Test]
    public function withSeparateFileTheMigrationIsNecessaryForValuesBelowTheNewPath(): void
    {
        $site = $this->site();
        $this->get(SiteWriter::class)->writeSettings(self::SITE, SiteDataSettings::tree(['websiteId' => 'w-1']));

        self::assertTrue($this->buildSubject(self::DATA_PATH)->isNecessary($site));
    }

    #[Test]
    public function theMigrationReplacesAnEmptyValueBelowTheNewPath(): void
    {
        $site = $this->site();
        file_put_contents($this->settingsFileName, "status: active\n" . Yaml::dump(SiteDataSettings::tree(['status' => '']), 99, 2));

        $this->buildSubject('')->migrate($site);

        self::assertSame(SiteDataSettings::tree(['status' => 'active']), Yaml::parseFile($this->settingsFileName));
    }

    #[Test]
    public function withSeparateFileTheMigrationDoesNotChangeTheSettingsYamlWhenTheFileIsInvalid(): void
    {
        $site = $this->site();
        GeneralUtility::mkdir_deep(Environment::getProjectPath() . '/' . self::DATA_PATH);
        file_put_contents(Environment::getProjectPath() . '/' . self::DATA_PATH . '/main.yaml', "status: [not closed\n");
        file_put_contents($this->settingsFileName, "websiteId: legacy-website-id\n");

        try {
            $this->buildSubject(self::DATA_PATH)->migrate($site);
            self::fail('The migration must fail');
        } catch (SiteConfigurationWriteException $e) {
            self::assertSame(1794494912, $e->getCode());
        }
        self::assertSame("websiteId: legacy-website-id\n", file_get_contents($this->settingsFileName));
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
        return new SiteDataNamespaceMigration(
            $this->get(SiteDataStorageInterface::class),
            $this->get(SiteDataConfiguration::class),
            $this->get(SiteWriter::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readDataFile(): array
    {
        $data = Yaml::parseFile(Environment::getProjectPath() . '/' . self::DATA_PATH . '/main.yaml');
        self::assertIsArray($data);
        return $data;
    }

    /**
     * Load the site before the test changes settings.yaml.
     */
    private function site(): Site
    {
        return $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE);
    }
}
