<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface DashboardLinkBuilderInterface
{
    /**
     * Returns the link to the analytics dashboard of a site, or an empty string when the
     * backend user cannot open it or the site has no dashboard of its own.
     *
     * @param array<string, int|string> $parameters Additional route parameters, for example days
     */
    public function build(string $siteIdentifier, array $parameters = []): string;

    /**
     * Returns the link to the analytics dashboard filtered to one page, or an empty string
     * when the backend user cannot open it.
     *
     * @param array<string, int|string> $parameters Additional route parameters, for example days
     */
    public function buildForPage(string $siteIdentifier, string $pageUrl, array $parameters = []): string;
}
