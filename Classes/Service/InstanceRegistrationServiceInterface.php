<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Site\Entity\Site;

interface InstanceRegistrationServiceInterface
{
    /**
     * @throws AnalyticsApiException
     */
    public function register(Site $site, string $email): void;

    /**
     * Lets an unregistered site use the analytics of an active site: whitelists the domain of the
     * site for the website of the target and copies the credentials of the target to the site.
     *
     * @throws AnalyticsApiException when the target has no credentials or the API call fails
     */
    public function attach(Site $site, Site $target): void;

    /**
     * Stops an attached site from using the analytics of the active site: removes the domain
     * of the site from the whitelist and removes the copied credentials from the site.
     *
     * @throws AnalyticsApiException when the site is not attached or the API call fails
     */
    public function detach(Site $site): void;
}
