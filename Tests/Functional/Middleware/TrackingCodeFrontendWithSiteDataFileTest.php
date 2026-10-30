<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\Test;

final class TrackingCodeFrontendWithSiteDataFileTest extends AbstractTrackingCodeFrontendTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'analytics' => [
                'siteDataPath' => self::DATA_PATH,
            ],
        ],
    ];

    #[Test]
    public function thePageContainsTheTrackingCodeFromTheFile(): void
    {
        $this->writeSiteDataFile(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']);

        self::assertStringContainsString(self::TRACKING_CODE . "\n</body>", $this->fetchPage());
    }

    #[Test]
    public function thePageContainsTheTrackingCodeFromTheSettingsYamlAsLongAsTheFileDoesNotExist(): void
    {
        $this->writeSettingsYaml(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']);

        self::assertStringContainsString(self::TRACKING_CODE . "\n</body>", $this->fetchPage());
    }

    #[Test]
    public function theStatusInTheFileHasPriorityOverTheSettingsYaml(): void
    {
        $this->writeSettingsYaml(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']);
        $this->writeSiteDataFile(['status' => 'cancelled']);

        self::assertStringNotContainsString(self::TRACKING_CODE, $this->fetchPage());
    }
}
