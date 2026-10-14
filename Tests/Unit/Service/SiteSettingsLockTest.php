<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Exception\SiteSettingsLockedException;
use T3G\Analytics\Service\SiteSettingsLock;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SiteSettingsLockTest extends UnitTestCase
{
    #[Test]
    public function runReturnsResultOfCallbackAndReleasesLock(): void
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturn(true);
        $locker->expects(self::once())->method('release');

        self::assertSame('result', $this->buildSubject($locker)->run('main', static fn (): string => 'result'));
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
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturn(true);
        $locker->expects(self::once())->method('release');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('callback failed');

        $this->buildSubject($locker)->run('main', static function (): never {
            throw new \RuntimeException('callback failed', 1760521400);
        });
    }

    #[Test]
    public function runThrowsWithoutCallingCallbackWhenLockIsHeld(): void
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willThrowException(new LockAcquireWouldBlockException('Locked.', 1760521401));
        $locker->expects(self::never())->method('release');
        $called = false;

        try {
            $this->buildSubject($locker)->run('main', static function () use (&$called): void {
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
        $called = false;

        try {
            $this->buildSubject($locker)->run('main', static function () use (&$called): void {
                $called = true;
            });
            self::fail('SiteSettingsLockedException expected.');
        } catch (SiteSettingsLockedException) {
        }

        self::assertFalse($called);
    }

    private function buildSubject(LockingStrategyInterface $locker): SiteSettingsLock
    {
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturn($locker);
        return new SiteSettingsLock($lockFactory);
    }
}
