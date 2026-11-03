<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;

interface SiteDataProviderInterface
{
    /**
     * @return list<array{identifier: string, title: string, pageName: string, domain: string, websiteId: string|null, registered: bool, attachedTo: string, attachable: bool, status: array<string, mixed>|null, panelClass: string, dashboardUri: string, managePlanUri: string}>
     * @throws RouteNotFoundException
     * @throws Exception
     */
    public function fetchSites(): array;

    /**
     * Registered sites with own analytics. Sites attached to an active site are not in the list.
     *
     * @return list<array{identifier: string, label: string}>
     * @throws Exception
     */
    public function registeredSiteOptions(): array;

    /**
     * Tells if the site can be attached to an active site. The base of the site must be a host
     * without a path, and no other site of the TYPO3 instance may use this host or a host of the
     * languages of the site. The check includes
     * all sites, also the sites that the user cannot access. Detach removes the host from the
     * whitelist, so a host must belong to exactly one site.
     */
    public function isAttachable(Site $site): bool;

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
