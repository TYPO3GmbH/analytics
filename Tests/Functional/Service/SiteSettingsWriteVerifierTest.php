<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use T3G\Analytics\Configuration\SiteDataConfiguration;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Service\SiteDataStorage;
use T3G\Analytics\Service\SiteDataStorageInterface;
use T3G\Analytics\Service\SiteSettingsWriteVerifier;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Cache\Frontend\NullFrontend;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SiteSettingsWriteVerifierTest extends FunctionalTestCase
{
    private SiteSettingsFactory&MockObject $siteSettingsFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteSettingsFactory = $this->createMock(SiteSettingsFactory::class);
    }

    #[Test]
    public function assertSettingsPersistedDoesNotThrowWhenAllValuesMatch(): void
    {
        $this->expectNotToPerformAssertions();

        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([
            'websiteId' => 'w-123',
            'instanceId' => 'i-456',
        ]);
        $verifier = new SiteSettingsWriteVerifier(new SiteDataStorage(new SiteDataConfiguration(), $this->createMock(SiteSettingsService::class), $this->siteSettingsFactory, new NullLogger(), new NullFrontend('core')), new NullLogger());

        $verifier->assertSettingsPersisted($this->buildSite(), ['websiteId' => 'w-123', 'instanceId' => 'i-456']);
    }

    #[Test]
    public function assertSettingsPersistedThrowsWhenKeyIsAbsent(): void
    {
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);
        $verifier = new SiteSettingsWriteVerifier(new SiteDataStorage(new SiteDataConfiguration(), $this->createMock(SiteSettingsService::class), $this->siteSettingsFactory, new NullLogger(), new NullFrontend('core')), new NullLogger());

        $this->expectException(AnalyticsApiException::class);
        $this->expectExceptionMessageMatches('/"main"/');

        $verifier->assertSettingsPersisted($this->buildSite(), ['websiteId' => 'w-123']);
    }

    #[Test]
    public function assertSettingsPersistedThrowsWhenValueDoesNotMatch(): void
    {
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn(['websiteId' => 'other-id']);
        $verifier = new SiteSettingsWriteVerifier(new SiteDataStorage(new SiteDataConfiguration(), $this->createMock(SiteSettingsService::class), $this->siteSettingsFactory, new NullLogger(), new NullFrontend('core')), new NullLogger());

        $this->expectException(AnalyticsApiException::class);

        $verifier->assertSettingsPersisted($this->buildSite(), ['websiteId' => 'w-123']);
    }

    #[Test]
    public function assertSettingsPersistedThrowsOnFirstFailingKey(): void
    {
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn(['websiteId' => 'w-123']);
        $verifier = new SiteSettingsWriteVerifier(new SiteDataStorage(new SiteDataConfiguration(), $this->createMock(SiteSettingsService::class), $this->siteSettingsFactory, new NullLogger(), new NullFrontend('core')), new NullLogger());

        $this->expectException(AnalyticsApiException::class);

        $verifier->assertSettingsPersisted($this->buildSite(), ['websiteId' => 'w-123', 'instanceId' => 'i-456']);
    }

    #[Test]
    public function assertSettingsPersistedReadsSettingsOnlyOnce(): void
    {
        $this->siteSettingsFactory
            ->expects(self::once())
            ->method('loadLocalSettings')
            ->willReturn(['websiteId' => 'w-123', 'instanceId' => 'i-456']);

        $verifier = new SiteSettingsWriteVerifier(new SiteDataStorage(new SiteDataConfiguration(), $this->createMock(SiteSettingsService::class), $this->siteSettingsFactory, new NullLogger(), new NullFrontend('core')), new NullLogger());
        $verifier->assertSettingsPersisted($this->buildSite(), ['websiteId' => 'w-123', 'instanceId' => 'i-456']);
    }

    #[Test]
    public function assertDirectoryWritableWritesThePathsOnlyIntoTheLog(): void
    {
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'dependencies' => ['t3g/analytics'],
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
            ],
        ]);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = 'analytics-data';
        $logger = new class () extends AbstractLogger {
            /** @var list<array{message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string)$message, 'context' => $context];
            }
        };
        $verifier = new SiteSettingsWriteVerifier($this->get(SiteDataStorageInterface::class), $logger);

        try {
            $verifier->assertDirectoryWritable($this->get(SiteFinder::class)->getSiteByIdentifier('main'));
            self::fail('The check must fail');
        } catch (AnalyticsApiException $e) {
            self::assertSame('The site settings of site "main" do not import the site data file correctly. See the TYPO3 log for details.', $e->getMessage());
        } finally {
            unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath']);
            GeneralUtility::rmdir(Environment::getProjectPath() . '/analytics-data', true);
        }

        self::assertCount(1, $logger->records);
        self::assertSame('analytics-data/main.yaml', $logger->records[0]['context']['location']);
        self::assertSame('typo3conf/sites/main/settings.yaml', $logger->records[0]['context']['settingsLocation']);
    }

    private function buildSite(): Site
    {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn('main');
        $site->method('getBase')->willReturn(new Uri('https://example.com'));
        $site->method('getSettings')->willReturn(new SiteSettings(new Settings([]), [], []));
        return $site;
    }
}
