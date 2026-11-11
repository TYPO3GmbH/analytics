<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Configuration\SiteDataConfiguration;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SiteDataConfigurationTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = '';
        parent::tearDown();
    }

    #[Test]
    public function withoutConfigurationTheSiteSettingsAreUsed(): void
    {
        $subject = new SiteDataConfiguration(new ExtensionConfiguration());

        self::assertTrue($subject->usesSiteSettings());
        self::assertSame('', $subject->getDirectory());
    }

    #[Test]
    public function aRelativePathStartsAtTheProjectRoot(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = ' config/analytics/ ';
        $subject = new SiteDataConfiguration(new ExtensionConfiguration());

        self::assertFalse($subject->usesSiteSettings());
        self::assertSame(Environment::getProjectPath() . '/config/analytics', $subject->getDirectory());
    }

    #[Test]
    public function anAbsolutePathIsUsedAsItIs(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = '/var/shared/analytics';

        self::assertSame('/var/shared/analytics', (new SiteDataConfiguration(new ExtensionConfiguration()))->getDirectory());
    }

    #[Test]
    public function theConfigurationReadsThePathOnlyOnce(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = '/var/shared/analytics';
        $subject = new SiteDataConfiguration(new ExtensionConfiguration());
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = '';

        self::assertSame('/var/shared/analytics', $subject->getDirectory());
    }
}
