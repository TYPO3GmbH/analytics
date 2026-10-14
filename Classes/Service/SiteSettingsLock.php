<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use T3G\Analytics\Exception\SiteSettingsLockedException;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireException;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\Exception\LockCreateException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Serializes the "load, merge, write" sequence on the settings.yaml of a site.
 * Without the lock, parallel requests can overwrite values that another
 * request wrote a moment before, or provision a second API key.
 *
 * The lock does not wait. When another request holds it, the callback does
 * not run and the caller decides what to do.
 */
final readonly class SiteSettingsLock
{
    private const MODE = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;

    public function __construct(
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * @template CallbackResult
     * @param callable(): CallbackResult $callback
     * @return CallbackResult
     * @throws SiteSettingsLockedException when the lock is held by another request or cannot be acquired
     */
    public function run(string $siteIdentifier, callable $callback): mixed
    {
        try {
            $locker = $this->lockFactory->createLocker('tx_analytics_site_settings_' . $siteIdentifier, self::MODE);
            $acquired = $locker->acquire(self::MODE);
        } catch (LockAcquireWouldBlockException|LockAcquireException|LockCreateException $e) {
            throw new SiteSettingsLockedException('The settings of site "' . $siteIdentifier . '" are locked.', 1760521200, $e);
        }
        if (!$acquired) {
            throw new SiteSettingsLockedException('The settings of site "' . $siteIdentifier . '" are locked.', 1760521201);
        }

        try {
            return $callback();
        } finally {
            $locker->release();
        }
    }
}
