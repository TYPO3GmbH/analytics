<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use T3G\Analytics\Configuration\SiteDataConfiguration;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Service\SiteDataStorage;
use T3G\Analytics\Service\SiteSettingsWriteVerifier;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;

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
        $verifier = new SiteSettingsWriteVerifier(new SiteDataStorage(new SiteDataConfiguration(), $this->createMock(SiteSettingsService::class), $this->siteSettingsFactory, new NullLogger()));

        $verifier->assertSettingsPersisted($this->buildSite(), ['websiteId' => 'w-123', 'instanceId' => 'i-456']);
    }

    #[Test]
    public function assertSettingsPersistedThrowsWhenKeyIsAbsent(): void
    {
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);
        $verifier = new SiteSettingsWriteVerifier(new SiteDataStorage(new SiteDataConfiguration(), $this->createMock(SiteSettingsService::class), $this->siteSettingsFactory, new NullLogger()));

        $this->expectException(AnalyticsApiException::class);
        $this->expectExceptionMessageMatches('/"main"/');

        $verifier->assertSettingsPersisted($this->buildSite(), ['websiteId' => 'w-123']);
    }

    #[Test]
    public function assertSettingsPersistedThrowsWhenValueDoesNotMatch(): void
    {
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn(['websiteId' => 'other-id']);
        $verifier = new SiteSettingsWriteVerifier(new SiteDataStorage(new SiteDataConfiguration(), $this->createMock(SiteSettingsService::class), $this->siteSettingsFactory, new NullLogger()));

        $this->expectException(AnalyticsApiException::class);

        $verifier->assertSettingsPersisted($this->buildSite(), ['websiteId' => 'w-123']);
    }

    #[Test]
    public function assertSettingsPersistedThrowsOnFirstFailingKey(): void
    {
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn(['websiteId' => 'w-123']);
        $verifier = new SiteSettingsWriteVerifier(new SiteDataStorage(new SiteDataConfiguration(), $this->createMock(SiteSettingsService::class), $this->siteSettingsFactory, new NullLogger()));

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

        $verifier = new SiteSettingsWriteVerifier(new SiteDataStorage(new SiteDataConfiguration(), $this->createMock(SiteSettingsService::class), $this->siteSettingsFactory, new NullLogger()));
        $verifier->assertSettingsPersisted($this->buildSite(), ['websiteId' => 'w-123', 'instanceId' => 'i-456']);
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
