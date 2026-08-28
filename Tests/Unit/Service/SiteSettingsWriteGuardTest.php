<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Service\SiteSettingsWriteGuard;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SiteSettingsWriteGuardTest extends UnitTestCase
{
    private SiteSettingsFactory&MockObject $siteSettingsFactory;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteSettingsFactory = $this->createMock(SiteSettingsFactory::class);
        $this->tempDir = sys_get_temp_dir() . '/analytics-guard-test-' . uniqid();
        mkdir($this->tempDir . '/main', 0755, recursive: true);
    }

    protected function tearDown(): void
    {
        chmod($this->tempDir . '/main', 0755);
        rmdir($this->tempDir . '/main');
        rmdir($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function assertDirectoryWritableDoesNotThrowWhenDirectoryIsWritable(): void
    {
        $this->expectNotToPerformAssertions();

        $guard = new SiteSettingsWriteGuard($this->siteSettingsFactory, $this->tempDir);
        $guard->assertDirectoryWritable($this->buildSite());
    }

    #[Test]
    public function assertDirectoryWritableThrowsWhenDirectoryIsNotWritable(): void
    {
        if (posix_getuid() === 0) {
            self::markTestSkipped('chmod-based permission tests do not apply when running as root.');
        }

        chmod($this->tempDir . '/main', 0555);

        $guard = new SiteSettingsWriteGuard($this->siteSettingsFactory, $this->tempDir);

        $this->expectException(AnalyticsApiException::class);
        $this->expectExceptionMessageMatches('/config\/sites\/main/');

        $guard->assertDirectoryWritable($this->buildSite());
    }

    #[Test]
    public function assertDirectoryWritableThrowsWhenDirectoryDoesNotExist(): void
    {
        $guard = new SiteSettingsWriteGuard($this->siteSettingsFactory, '/nonexistent/path');

        $this->expectException(AnalyticsApiException::class);

        $guard->assertDirectoryWritable($this->buildSite());
    }

    #[Test]
    public function assertSettingsPersistedDoesNotThrowWhenAllValuesMatch(): void
    {
        $this->expectNotToPerformAssertions();

        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([
            'websiteId' => 'w-123',
            'instanceId' => 'i-456',
        ]);
        $guard = new SiteSettingsWriteGuard($this->siteSettingsFactory, $this->tempDir);

        $guard->assertSettingsPersisted($this->buildSite(), ['websiteId' => 'w-123', 'instanceId' => 'i-456']);
    }

    #[Test]
    public function assertSettingsPersistedThrowsWhenKeyIsAbsent(): void
    {
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);
        $guard = new SiteSettingsWriteGuard($this->siteSettingsFactory, $this->tempDir);

        $this->expectException(AnalyticsApiException::class);
        $this->expectExceptionMessageMatches('/config\/sites\/main\/settings\.yaml/');

        $guard->assertSettingsPersisted($this->buildSite(), ['websiteId' => 'w-123']);
    }

    #[Test]
    public function assertSettingsPersistedThrowsWhenValueDoesNotMatch(): void
    {
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn(['websiteId' => 'other-id']);
        $guard = new SiteSettingsWriteGuard($this->siteSettingsFactory, $this->tempDir);

        $this->expectException(AnalyticsApiException::class);

        $guard->assertSettingsPersisted($this->buildSite(), ['websiteId' => 'w-123']);
    }

    #[Test]
    public function assertSettingsPersistedThrowsOnFirstFailingKey(): void
    {
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn(['websiteId' => 'w-123']);
        $guard = new SiteSettingsWriteGuard($this->siteSettingsFactory, $this->tempDir);

        $this->expectException(AnalyticsApiException::class);

        $guard->assertSettingsPersisted($this->buildSite(), ['websiteId' => 'w-123', 'instanceId' => 'i-456']);
    }

    #[Test]
    public function assertSettingsPersistedReadsSettingsOnlyOnce(): void
    {
        $this->siteSettingsFactory
            ->expects(self::once())
            ->method('loadLocalSettings')
            ->willReturn(['websiteId' => 'w-123', 'instanceId' => 'i-456']);

        $guard = new SiteSettingsWriteGuard($this->siteSettingsFactory, $this->tempDir);
        $guard->assertSettingsPersisted($this->buildSite(), ['websiteId' => 'w-123', 'instanceId' => 'i-456']);
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
