<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Yaml;
use T3G\Analytics\Configuration\SiteDataConfiguration;
use T3G\Analytics\Service\SiteDataStorage;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SiteDataStorageTest extends FunctionalTestCase
{
    private const SITE = 'main';
    private const DATA_PATH = 'analytics-data';

    private const EXISTING_SETTINGS = [
        'websiteId' => 'f2dddfbb-a7c5-4503-b461-5a6022d91522',
        'instanceId' => 'ae518ea5-9ca5-4270-84d3-23fc87c241d3',
        'instanceSecret' => 'encrypted-instance-secret',
        'trackingCode' => '<script>track()</script>',
        'status' => 'active',
        'otherExtension' => ['setting' => 'keep-me'],
    ];

    private string $settingsFileName;

    /** @var list<string> */
    private array $readOnlyPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write(self::SITE, [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'dependencies' => ['t3g/analytics'],
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
            ],
        ]);
        $siteWriter->writeSettings(self::SITE, self::EXISTING_SETTINGS);
        $this->settingsFileName = Environment::getConfigPath() . '/sites/' . self::SITE . '/settings.yaml';
    }

    protected function tearDown(): void
    {
        foreach ($this->readOnlyPaths as $path) {
            chmod($path, is_dir($path) ? 0775 : 0664);
        }
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath']);
        $dataPath = $this->dataDirectory();
        is_link($dataPath) ? unlink($dataPath) : GeneralUtility::rmdir($dataPath, true);
        parent::tearDown();
    }

    #[Test]
    public function withoutConfigurationTheStorageReadsTheSiteSettings(): void
    {
        $subject = $this->buildSubject('');

        self::assertSame('active', $subject->get($this->site(), 'status'));
        self::assertSame('<script>track()</script>', $subject->get($this->site(), 'trackingCode'));
        self::assertSame('', $subject->get($this->site(), 'apiKey'));
    }

    #[Test]
    public function withoutConfigurationTheStorageWritesIntoTheSettingsYamlAndKeepsAllOtherValues(): void
    {
        $subject = $this->buildSubject('');

        $subject->write($this->site(), ['status' => 'inactive', 'apiKeyId' => 'key-1']);

        $persisted = Yaml::parseFile($this->settingsFileName);
        self::assertSame('inactive', $persisted['status']);
        self::assertSame('key-1', $persisted['apiKeyId']);
        self::assertSame(self::EXISTING_SETTINGS['websiteId'], $persisted['websiteId']);
        self::assertSame(['setting' => 'keep-me'], $persisted['otherExtension']);
        self::assertSame($persisted, $subject->loadPersisted($this->site()));
        self::assertDirectoryDoesNotExist($this->dataDirectory());
        self::assertSame('typo3conf/sites/main/settings.yaml', $subject->getLocation($this->site()));
    }

    #[Test]
    public function withSeparateFileTheStorageReadsTheSiteSettingsAsLongAsTheFileDoesNotExist(): void
    {
        $subject = $this->buildSubject(self::DATA_PATH);

        self::assertSame('active', $subject->get($this->site(), 'status'));
        self::assertSame(self::EXISTING_SETTINGS['instanceSecret'], $subject->get($this->site(), 'instanceSecret'));
    }

    #[Test]
    public function withSeparateFileTheFirstWriteCopiesAllValuesIntoTheFileAndDoesNotChangeTheSettingsYaml(): void
    {
        $settingsYaml = (string)file_get_contents($this->settingsFileName);
        $subject = $this->buildSubject(self::DATA_PATH);

        $subject->write($this->site(), ['apiKeyId' => 'key-1', 'apiKey' => 'encrypted-api-key']);

        self::assertSame($settingsYaml, file_get_contents($this->settingsFileName));
        $fileName = $this->dataDirectory() . '/main.yaml';
        self::assertSame([
            'websiteId' => self::EXISTING_SETTINGS['websiteId'],
            'instanceId' => self::EXISTING_SETTINGS['instanceId'],
            'instanceSecret' => self::EXISTING_SETTINGS['instanceSecret'],
            'trackingCode' => self::EXISTING_SETTINGS['trackingCode'],
            'status' => 'active',
            'apiKeyId' => 'key-1',
            'apiKey' => 'encrypted-api-key',
            'externalWebsiteId' => '',
        ], Yaml::parseFile($fileName));
        self::assertSame(Yaml::parseFile($fileName), $subject->loadPersisted($this->site()));
        self::assertSame($this->dataLocation('main.yaml'), $subject->getLocation($this->site()));
    }

    #[Test]
    public function withSeparateFileTheValuesOfTheFileHavePriorityOverTheSiteSettings(): void
    {
        $subject = $this->buildSubject(self::DATA_PATH);

        $subject->write($this->site(), ['status' => 'cancelled', 'trackingCode' => '']);

        self::assertSame('cancelled', $subject->get($this->site(), 'status'));
        self::assertSame('', $subject->get($this->site(), 'trackingCode'));
        self::assertSame('cancelled', $this->buildSubject(self::DATA_PATH)->get($this->site(), 'status'), 'A new instance must read the file');
    }

    #[Test]
    public function withSeparateFileTheStorageCreatesTheDirectory(): void
    {
        $subject = $this->buildSubject(self::DATA_PATH . '/nested');

        self::assertTrue($subject->isWritable($this->site()));
        $subject->write($this->site(), ['status' => 'active']);

        self::assertFileExists($this->dataDirectory() . '/nested/main.yaml');
        self::assertTrue($subject->isWritable($this->site()));
    }

    #[Test]
    public function withSeparateFileTheStorageIsNotWritableWhenTheDirectoryIsReadOnly(): void
    {
        $this->skipAsRoot();
        $subject = $this->buildSubject(self::DATA_PATH);
        $subject->write($this->site(), ['status' => 'active']);
        $directory = $this->dataDirectory();
        chmod($directory, 0555);
        $this->readOnlyPaths[] = $directory;

        self::assertFalse($subject->isWritable($this->site()));
        $this->expectException(SiteConfigurationWriteException::class);
        $subject->write($this->site(), ['status' => 'inactive']);
    }

    #[Test]
    public function withoutConfigurationTheStorageIsNotWritableWhenTheSettingsYamlIsReadOnly(): void
    {
        $this->skipAsRoot();
        $subject = $this->buildSubject('');
        self::assertTrue($subject->isWritable($this->site()));

        chmod($this->settingsFileName, 0444);
        $this->readOnlyPaths[] = $this->settingsFileName;

        self::assertFalse($subject->isWritable($this->site()));
    }

    #[Test]
    public function withSeparateFileAnInvalidFileIsNotReplacedWithOldValuesOfTheSiteSettings(): void
    {
        $fileName = $this->writeDataFile("status: [not closed\n");
        $subject = $this->buildSubject(self::DATA_PATH);

        self::assertSame('', $subject->get($this->site(), 'status'));
        self::assertFalse($subject->isWritable($this->site()));
        try {
            $subject->write($this->site(), ['status' => 'active']);
            self::fail('The write must fail');
        } catch (SiteConfigurationWriteException) {
        }
        self::assertSame("status: [not closed\n", file_get_contents($fileName));
    }

    #[Test]
    public function withSeparateFileAnUnreadableFileIsNotReplacedWithOldValuesOfTheSiteSettings(): void
    {
        $this->skipAsRoot();
        $fileName = $this->writeDataFile("status: cancelled\n");
        chmod($fileName, 0000);
        $this->readOnlyPaths[] = $fileName;
        $subject = $this->buildSubject(self::DATA_PATH);

        self::assertSame('', $subject->get($this->site(), 'status'));
        self::assertFalse($subject->isWritable($this->site()));
        chmod($fileName, 0664);
        self::assertSame("status: cancelled\n", file_get_contents($fileName));
    }

    #[Test]
    public function withSeparateFileAWriteKeepsTheValuesThatAnotherRequestWroteInTheMeantime(): void
    {
        $requestA = $this->buildSubject(self::DATA_PATH);
        $requestB = $this->buildSubject(self::DATA_PATH);
        $requestA->write($this->site(), ['status' => 'active']);
        self::assertSame('', $requestA->get($this->site(), 'apiKeyId'));

        $requestB->write($this->site(), ['apiKeyId' => 'key-1']);
        $requestA->write($this->site(), ['status' => 'inactive']);

        $persisted = $requestA->loadPersisted($this->site());
        self::assertSame('key-1', $persisted['apiKeyId']);
        self::assertSame('inactive', $persisted['status']);
    }

    #[Test]
    public function withSeparateFileAWriteKeepsUnknownValues(): void
    {
        $this->writeDataFile("status: active\naddedByANewerVersion: keep-me\n");

        $this->buildSubject(self::DATA_PATH)->write($this->site(), ['status' => 'inactive']);

        self::assertSame('keep-me', $this->buildSubject(self::DATA_PATH)->loadPersisted($this->site())['addedByANewerVersion']);
    }

    #[Test]
    public function withSeparateFileTheWritableCheckWritesTheFile(): void
    {
        $subject = $this->buildSubject(self::DATA_PATH);

        self::assertTrue($subject->isWritable($this->site()));

        self::assertSame(self::EXISTING_SETTINGS['websiteId'], $subject->loadPersisted($this->site())['websiteId']);
    }

    #[Test]
    public function withSeparateFileTheStorageIsNotWritableWhenTheDirectoryIsABrokenSymlink(): void
    {
        symlink(Environment::getProjectPath() . '/missing-shared-directory', $this->dataDirectory());

        self::assertFalse($this->buildSubject(self::DATA_PATH)->isWritable($this->site()));
    }

    #[Test]
    public function anAbsolutePathIsUsedAsItIs(): void
    {
        $directory = $this->dataDirectory();
        $subject = $this->buildSubject($directory);

        $subject->write($this->site(), ['status' => 'active']);

        self::assertFileExists($directory . '/main.yaml');
    }

    private function writeDataFile(string $content): string
    {
        $directory = $this->dataDirectory();
        GeneralUtility::mkdir_deep($directory);
        file_put_contents($directory . '/main.yaml', $content);
        return $directory . '/main.yaml';
    }

    private function skipAsRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignores file permissions');
        }
    }

    private function buildSubject(string $siteDataPath): SiteDataStorage
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = $siteDataPath;
        return new SiteDataStorage(
            new SiteDataConfiguration(),
            $this->get(SiteSettingsService::class),
            $this->get(SiteSettingsFactory::class),
            new NullLogger(),
        );
    }

    private function site(): Site
    {
        return $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE);
    }

    private function dataDirectory(): string
    {
        return Environment::getProjectPath() . '/' . self::DATA_PATH;
    }

    private function dataLocation(string $file): string
    {
        return substr($this->dataDirectory(), strlen(Environment::getProjectPath()) + 1) . '/' . $file;
    }
}
