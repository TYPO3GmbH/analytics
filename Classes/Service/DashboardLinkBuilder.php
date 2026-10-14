<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

readonly class DashboardLinkBuilder implements DashboardLinkBuilderInterface
{
    public function __construct(
        private SiteFinder $siteFinder,
        private BackendPageAccessCheckerInterface $pageAccessChecker,
        private UriBuilder $uriBuilder,
    ) {
    }

    /**
     * A site attached to an active site has no dashboard of its own. The dashboard can only filter
     * by an exact page URL, not by the domain of the site, so there is no link.
     */
    public function build(string $siteIdentifier, array $parameters = []): string
    {
        $site = $this->findSite($siteIdentifier);
        if ($site === null || SiteAttachment::isAttached($site)) {
            return '';
        }
        return $this->buildDashboardUri($site, $parameters);
    }

    /**
     * For a page of a site attached to an active site, the link opens the dashboard of the active
     * site with the page filter. The dashboard shows all data of the active site, so the backend
     * user must have access to the root page of that site.
     */
    public function buildForPage(string $siteIdentifier, string $pageUrl, array $parameters = []): string
    {
        $site = $this->findSite($siteIdentifier);
        if ($site === null) {
            return '';
        }
        $attachedTo = SiteAttachment::attachedTo($site);
        $dashboardSite = $attachedTo !== '' ? $this->findSite($attachedTo) : $site;
        if ($dashboardSite === null) {
            return '';
        }
        if ($pageUrl !== '') {
            $parameters['pageUrl'] = $pageUrl;
        }
        return $this->buildDashboardUri($dashboardSite, $parameters);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function buildDashboardUri(Site $site, array $parameters): string
    {
        if (!$this->pageAccessChecker->userCanAccessPage($site->getRootPageId())) {
            return '';
        }
        try {
            return (string)$this->uriBuilder->buildUriFromRoute(
                'site_analytics.dashboard',
                ['siteIdentifier' => $site->getIdentifier(), ...$parameters]
            );
        } catch (\Throwable) {
            return '';
        }
    }

    private function findSite(string $siteIdentifier): ?Site
    {
        if ($siteIdentifier === '') {
            return null;
        }
        try {
            return $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\Throwable) {
            return null;
        }
    }
}
