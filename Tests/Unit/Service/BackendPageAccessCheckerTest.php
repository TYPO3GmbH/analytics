<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Service\BackendPageAccessChecker;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class BackendPageAccessCheckerTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private BackendPageAccessChecker $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new BackendPageAccessChecker();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function userCanAccessPageReturnsFalseWhenNoBackendUserIsSet(): void
    {
        unset($GLOBALS['BE_USER']);

        // Fail closed: without an authenticated backend user, access must be denied.
        self::assertFalse($this->subject->userCanAccessPage(1));
    }

    #[Test]
    public function userCanAccessPageReturnsFalseWhenBackendUserHasNoPageAccess(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('getPagePermsClause')->willReturn('');
        $GLOBALS['BE_USER'] = $backendUser;

        // TYPO3 v14+ introduced TcaSchemaFactory as a required dependency of BackendUtility::readPageAccess().
        // In a unit test there is no DI container, so we inject a mock instance manually.
        if ((new Typo3Version())->getMajorVersion() >= 14) {
            GeneralUtility::addInstance(
                \TYPO3\CMS\Core\Schema\TcaSchemaFactory::class,
                $this->getMockBuilder(\TYPO3\CMS\Core\Schema\TcaSchemaFactory::class)
                    ->disableOriginalConstructor()
                    ->getMock()
            );
        }

        self::assertFalse($this->subject->userCanAccessPage(1));
    }

    #[Test]
    public function userCanAccessLanguageReturnsFalseWhenNoBackendUserIsSet(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertFalse($this->subject->userCanAccessLanguage(0));
    }

    #[Test]
    public function userCanAccessLanguageReturnsTrueWhenBackendUserAllowsLanguage(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('checkLanguageAccess')->with(1)->willReturn(true);
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertTrue($this->subject->userCanAccessLanguage(1));
    }

    #[Test]
    public function userCanAccessLanguageReturnsFalseWhenBackendUserDeniesLanguage(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('checkLanguageAccess')->with(2)->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertFalse($this->subject->userCanAccessLanguage(2));
    }
}
