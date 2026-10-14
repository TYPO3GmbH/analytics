<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Fixtures;

use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * LockFactory mock for unit tests. It records the lock calls in $lockEvents.
 * Set $lockAvailable to false to simulate a lock that another request holds.
 */
trait RecordingLockFactoryTrait
{
    /** @var list<string> */
    private array $lockEvents = [];

    private bool $lockAvailable = true;

    private function buildRecordingLockFactory(): LockFactory
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturnCallback(function (): bool {
            if (!$this->lockAvailable) {
                throw new LockAcquireWouldBlockException('Locked by another request.', 1760521300);
            }
            $this->lockEvents[] = 'acquire';
            return true;
        });
        $locker->method('release')->willReturnCallback(function (): bool {
            $this->lockEvents[] = 'release';
            return true;
        });
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturnCallback(function (string $id) use ($locker): LockingStrategyInterface {
            $this->lockEvents[] = 'create ' . $id;
            return $locker;
        });
        return $lockFactory;
    }
}
