<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Site\Entity\Site;

interface ApiKeyServiceInterface
{
    /**
     * @param array<string, mixed> $currentStatus
     */
    public function provisionIfNeeded(Site $site, array $currentStatus): void;
}
