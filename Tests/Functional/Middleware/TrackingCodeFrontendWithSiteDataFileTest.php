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
    public function thePageContainsTheTrackingCodeFromTheImportedFile(): void
    {
        $this->writeSettingsYamlWithImport();
        $this->writeSiteDataFile(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']);

        self::assertStringContainsString(self::TRACKING_CODE . "\n</body>", $this->fetchPage());
    }

    #[Test]
    public function thePageDoesNotContainTheTrackingCodeWhenTheSettingsYamlDoesNotImportTheFile(): void
    {
        $this->writeSiteDataFile(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']);

        self::assertStringNotContainsString(self::TRACKING_CODE, $this->fetchPage());
    }

    #[Test]
    public function theValuesInTheSettingsYamlHavePriorityOverTheImportedFile(): void
    {
        $this->writeSettingsYamlWithImport(['status' => 'cancelled']);
        $this->writeSiteDataFile(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']);

        self::assertStringNotContainsString(self::TRACKING_CODE, $this->fetchPage());
    }
}
