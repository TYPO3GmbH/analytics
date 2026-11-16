<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

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

    /**
     * Returns the hosts of the base of the site and of the bases of all its languages, in lower case.
     *
     * @return list<string>
     */
    public static function hosts(Site $site): array
    {
        $hosts = [];
        foreach ([$site->getBase(), ...array_map(static fn (SiteLanguage $language) => $language->getBase(), $site->getAllLanguages())] as $base) {
            $host = strtolower($base->getHost());
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }
        return array_keys($hosts);
    }
}
