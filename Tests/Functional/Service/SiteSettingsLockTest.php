<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Exception\SiteSettingsLockedException;
use T3G\Analytics\Service\SiteSettingsLock;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Uses the real locking strategy of the test instance.
 */
final class SiteSettingsLockTest extends FunctionalTestCase
{
    #[Test]
    public function runThrowsWhileAnotherLockerHoldsTheLock(): void
    {
        $mode = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;
        $otherRequest = $this->get(LockFactory::class)->createLocker('tx_analytics_site_settings_main', $mode);
        self::assertTrue($otherRequest->acquire($mode));

        try {
            $this->get(SiteSettingsLock::class)->run('main', static fn (): null => null);
            self::fail('SiteSettingsLockedException expected.');
        } catch (SiteSettingsLockedException) {
        } finally {
            $otherRequest->release();
        }

        self::assertSame('done', $this->get(SiteSettingsLock::class)->run('main', static fn (): string => 'done'));
    }
}
