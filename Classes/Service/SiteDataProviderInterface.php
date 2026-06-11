<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;

interface SiteDataProviderInterface
{
    /**
     * @return list<array{identifier: string, title: string, pageName: string, domain: string, websiteId: string|null, registered: bool, status: array<string, mixed>|null, panelClass: string, dashboardUri: string, managePlanUri: string}>
     * @throws RouteNotFoundException
     * @throws Exception
     */
    public function fetchSites(): array;

    /**
     * @return list<array{identifier: string, label: string}>
     * @throws Exception
     */
    public function registeredSiteOptions(): array;

    /**
     * @throws Exception
     */
    public function getRootPageTitle(int $pageUid): string;

    public function siteLabel(string $pageName, string $siteIdentifier): string;

    /**
     * @param array<string, mixed>|null $status
     */
    public function resolvePanelClass(?array $status): string;
}
