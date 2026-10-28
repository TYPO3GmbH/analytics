<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Exception\SiteSettingsLockedException;
use T3G\Analytics\Service\SiteSettingsLock;
use T3G\Analytics\Tests\Fixtures\SiteSettingsLockTrait;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SiteSettingsLockTest extends UnitTestCase
{
    use SiteSettingsLockTrait;

    protected function tearDown(): void
    {
        $this->releaseSiteSettingsLockOfOtherRequest();
        parent::tearDown();
    }

    #[Test]
    public function runReturnsResultOfCallbackAndReleasesLock(): void
    {
        $lockedDuringCallback = null;

        $result = (new SiteSettingsLock(new LockFactory()))->run('main', function () use (&$lockedDuringCallback): string {
            $lockedDuringCallback = $this->isSiteSettingsLocked();
            return 'result';
        });

        self::assertSame('result', $result);
        self::assertTrue($lockedDuringCallback);
        self::assertFalse($this->isSiteSettingsLocked());
    }

    #[Test]
    public function runDoesNotWaitForLock(): void
    {
        $mode = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->expects(self::once())->method('acquire')->with($mode)->willReturn(true);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::once())->method('createLocker')->with('tx_analytics_site_settings_main', $mode)->willReturn($locker);

        (new SiteSettingsLock($lockFactory))->run('main', static fn (): null => null);
    }

    #[Test]
    public function runReleasesLockWhenCallbackThrows(): void
    {
        try {
            (new SiteSettingsLock(new LockFactory()))->run('main', static function (): never {
                throw new \RuntimeException('callback failed', 1760521400);
            });
            self::fail('RuntimeException expected.');
        } catch (\RuntimeException $e) {
            self::assertSame('callback failed', $e->getMessage());
        }

        self::assertFalse($this->isSiteSettingsLocked());
    }

    #[Test]
    public function runThrowsWithoutCallingCallbackWhenLockIsHeld(): void
    {
        $this->lockSiteSettingsInOtherRequest();
        $called = false;

        try {
            (new SiteSettingsLock(new LockFactory()))->run('main', static function () use (&$called): void {
                $called = true;
            });
            self::fail('SiteSettingsLockedException expected.');
        } catch (SiteSettingsLockedException) {
        }

        self::assertFalse($called);
    }

    #[Test]
    public function runThrowsWithoutCallingCallbackWhenLockIsNotAcquired(): void
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturn(false);
        $locker->expects(self::never())->method('release');
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturn($locker);
        $called = false;

        try {
            (new SiteSettingsLock($lockFactory))->run('main', static function () use (&$called): void {
                $called = true;
            });
            self::fail('SiteSettingsLockedException expected.');
        } catch (SiteSettingsLockedException) {
        }

        self::assertFalse($called);
    }
}
