<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Configuration\SiteDataConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SiteDataConfigurationTest extends UnitTestCase
{
    private string|false $environmentVariable = false;

    protected function setUp(): void
    {
        parent::setUp();
        // The CI environment must not change the result.
        $this->environmentVariable = getenv('TYPO3_ANALYTICS_SITE_DATA_PATH');
        putenv('TYPO3_ANALYTICS_SITE_DATA_PATH');
    }

    protected function tearDown(): void
    {
        putenv($this->environmentVariable === false ? 'TYPO3_ANALYTICS_SITE_DATA_PATH' : 'TYPO3_ANALYTICS_SITE_DATA_PATH=' . $this->environmentVariable);
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath']);
        parent::tearDown();
    }

    #[Test]
    public function withoutConfigurationTheSiteSettingsAreUsed(): void
    {
        $subject = new SiteDataConfiguration();

        self::assertTrue($subject->usesSiteSettings());
        self::assertSame('', $subject->getDirectory());
    }

    #[Test]
    public function aRelativePathStartsAtTheProjectRoot(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = ' config/analytics/ ';
        $subject = new SiteDataConfiguration();

        self::assertFalse($subject->usesSiteSettings());
        self::assertSame(Environment::getProjectPath() . '/config/analytics', $subject->getDirectory());
    }

    #[Test]
    public function anAbsolutePathIsUsedAsItIs(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = '/var/shared/analytics';

        self::assertSame('/var/shared/analytics', (new SiteDataConfiguration())->getDirectory());
    }

    #[Test]
    public function theEnvironmentVariableHasPriorityOverTheExtensionConfiguration(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = 'config/analytics';
        putenv('TYPO3_ANALYTICS_SITE_DATA_PATH=/var/shared/analytics');

        self::assertSame('/var/shared/analytics', (new SiteDataConfiguration())->getDirectory());
    }
}
