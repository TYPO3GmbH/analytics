<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * A site attached to an active site tracks into the website of that site. It has the credentials
 * of the active site, but no own analytics, so the site lists offer only the active site.
 */
final class SiteAttachment
{
    /**
     * Returns the identifier of the active site, or an empty string when the site is not attached to one.
     */
    public static function attachedTo(Site $site): string
    {
        try {
            return (string)($site->getSettings()->get('attachedTo', '') ?: '');
        } catch (\Throwable) {
            return '';
        }
    }

    public static function isAttached(Site $site): bool
    {
        return self::attachedTo($site) !== '';
    }
}
