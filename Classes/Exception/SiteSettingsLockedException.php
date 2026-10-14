<?php

declare(strict_types=1);

namespace T3G\Analytics\Exception;

/**
 * Another request holds the lock on the settings of the site.
 */
final class SiteSettingsLockedException extends \RuntimeException
{
}
