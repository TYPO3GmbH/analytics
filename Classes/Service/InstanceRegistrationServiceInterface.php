<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Exception\SiteAlreadyRegisteredException;
use T3G\Analytics\Exception\SiteSettingsLockedException;
use TYPO3\CMS\Core\Site\Entity\Site;

interface InstanceRegistrationServiceInterface
{
    /**
     * @throws AnalyticsApiException
     * @throws SiteSettingsLockedException when another request changes the settings of the site
     * @throws SiteAlreadyRegisteredException when the settings already contain a websiteId
     */
    public function register(Site $site, string $email): void;
}
