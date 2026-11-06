<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;

final class TrackingCodeFrontendTest extends AbstractTrackingCodeFrontendTestCase
{
    #[Test]
    public function thePageContainsTheTrackingCodeFromTheSettingsYaml(): void
    {
        $this->writeSettingsYaml(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']);

        self::assertStringContainsString(self::TRACKING_CODE . "\n</body>", $this->fetchPage());
    }

    #[Test]
    public function thePageContainsTheTrackingCodeOfVersion11UntilTheUpgradeWizardRan(): void
    {
        $this->get(SiteWriter::class)->writeSettings(self::SITE, ['trackingCode' => self::TRACKING_CODE, 'status' => 'active']);
        $this->get(CacheManager::class)->getCache('core')->flush();

        self::assertStringContainsString(self::TRACKING_CODE . "\n</body>", $this->fetchPage());
    }

    #[Test]
    public function thePageDoesNotContainTheTrackingCodeWhenTheSiteIsNotActive(): void
    {
        $this->writeSettingsYaml(['trackingCode' => self::TRACKING_CODE, 'status' => 'cancelled']);

        self::assertStringNotContainsString(self::TRACKING_CODE, $this->fetchPage());
    }

    #[Test]
    public function thePageIgnoresASiteDataFileWhenSiteDataPathIsNotSet(): void
    {
        $this->writeSiteDataFile(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']);

        self::assertStringNotContainsString(self::TRACKING_CODE, $this->fetchPage());
    }
}
