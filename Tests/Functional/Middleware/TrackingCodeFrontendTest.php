<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\Test;

final class TrackingCodeFrontendTest extends AbstractTrackingCodeFrontendTestCase
{
    #[Test]
    public function thePageContainsTheTrackingCodeFromTheSettingsYaml(): void
    {
        $this->writeSettingsYaml(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']);

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
