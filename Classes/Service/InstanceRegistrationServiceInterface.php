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
}
