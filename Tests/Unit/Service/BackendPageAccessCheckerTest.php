<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Service\BackendPageAccessChecker;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
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
    public function userCanAccessPageReturnsTrueWhenNoBackendUserIsSet(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertTrue($this->subject->userCanAccessPage(1));
    }

    #[Test]
    public function userCanAccessPageReturnsFalseWhenBackendUserHasNoPageAccess(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('getPagePermsClause')->willReturn('');
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertFalse($this->subject->userCanAccessPage(1));
    }
}
