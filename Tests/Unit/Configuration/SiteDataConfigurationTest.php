<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Configuration\SiteDataConfiguration;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SiteDataConfigurationTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']);
        parent::tearDown();
    }

    #[Test]
    public function anEmptyPathSelectsTheSiteSettings(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = '';
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
        self::assertSame('/var/shared/analytics', $subject->getDirectory());
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = '';

        self::assertSame('/var/shared/analytics', $subject->getDirectory());
    }

    #[Test]
    public function theConstructorDoesNotReadTheConfiguration(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->expects(self::never())->method('get');

        new SiteDataConfiguration($extensionConfiguration);
    }

    public static function missingConfigurationDataProvider(): \Generator
    {
        yield 'extension not configured' => [new ExtensionConfigurationExtensionNotConfiguredException('', 1509654728)];
        yield 'path does not exist' => [new ExtensionConfigurationPathDoesNotExistException('', 1509977699)];
    }

    #[Test]
    #[DataProvider('missingConfigurationDataProvider')]
    public function aMissingConfigurationSelectsTheSiteSettings(\Exception $exception): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException($exception);

        self::assertTrue((new SiteDataConfiguration($extensionConfiguration))->usesSiteSettings());
    }
}
