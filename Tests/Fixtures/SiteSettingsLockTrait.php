<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Fixtures;

use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Uses the real settings lock of the site "main" in unit tests. A test can hold
 * the lock like a parallel request, or check that the code under test holds it.
 * Call releaseSiteSettingsLockOfOtherRequest() in tearDown().
 */
trait SiteSettingsLockTrait
{
    private ?LockingStrategyInterface $otherRequestLock = null;

    private function lockSiteSettingsInOtherRequest(): void
    {
        $this->otherRequestLock = $this->createSiteSettingsLocker();
        self::assertTrue($this->otherRequestLock->acquire($this->siteSettingsLockMode()));
    }

    private function releaseSiteSettingsLockOfOtherRequest(): void
    {
        $this->otherRequestLock?->release();
        $this->otherRequestLock = null;
    }

    private function isSiteSettingsLocked(): bool
    {
        $locker = $this->createSiteSettingsLocker();
        try {
            if (!$locker->acquire($this->siteSettingsLockMode())) {
                return true;
            }
        } catch (LockAcquireWouldBlockException) {
            return true;
        }
        $locker->release();
        return false;
    }

    private function createSiteSettingsLocker(): LockingStrategyInterface
    {
        return (new LockFactory())->createLocker('tx_analytics_site_settings_main', $this->siteSettingsLockMode());
    }

    private function siteSettingsLockMode(): int
    {
        return LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;
    }
}
