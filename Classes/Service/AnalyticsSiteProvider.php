<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

readonly class AnalyticsSiteProvider implements AnalyticsSiteProviderInterface
{
    public function __construct(
        private SiteFinder $siteFinder,
        private CipherServiceInterface $cipherService,
        private BackendPageAccessCheckerInterface $pageAccessChecker,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function siteOptions(): array
    {
        $options = [];

        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                if (!$this->isAnalyticsSite($site)) {
                    continue;
                }
                if (!$this->pageAccessChecker->userCanAccessPage($site->getRootPageId())) {
                    continue;
                }
                $siteTitle = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));
                $options[$site->getIdentifier()] = $siteTitle === ''
                    ? $site->getIdentifier()
                    : $siteTitle . ' (' . $site->getIdentifier() . ')';
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load analytics sites: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        return $options;
    }

    /**
     * @return array{site: Site, websiteId: string, apiKey: string, pageUrlFilter: list<string>}|null
     */
    public function resolveAnalyticsSite(string $siteIdentifier): ?array
    {
        try {
            $sites = $siteIdentifier !== ''
                ? [$this->siteFinder->getSiteByIdentifier($siteIdentifier)]
                : $this->siteFinder->getAllSites();
        } catch (\Throwable) {
            return null;
        }

        foreach ($sites as $site) {
            if (!$this->pageAccessChecker->userCanAccessPage($site->getRootPageId())) {
                continue;
            }
            $credentials = $this->extractSiteCredentials($site);
            if ($credentials !== null) {
                return $credentials;
            }
        }

        return null;
    }

    private function isAnalyticsSite(Site $site): bool
    {
        return $this->extractSiteCredentials($site) !== null;
    }

    /**
     * A site attached to an active site has the copied credentials of that site.
     *
     * @return array{site: Site, websiteId: string, apiKey: string, pageUrlFilter: list<string>}|null
     */
    private function extractSiteCredentials(Site $site): ?array
    {
        try {
            $settings = $site->getSettings();
            $websiteId = (string)($settings->get('externalWebsiteId', '') ?: '');
            $encryptedApiKey = (string)($settings->get('apiKey', '') ?: '');
            if ($websiteId === '' || $encryptedApiKey === '') {
                return null;
            }
            $apiKey = $this->cipherService->decrypt($encryptedApiKey);
            return ['site' => $site, 'websiteId' => $websiteId, 'apiKey' => $apiKey, 'pageUrlFilter' => $this->buildPageUrlFilter($site)];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Several sites can track into one website (see SiteAttachment), so every query is limited
     * to the bases of the site and its languages. A value starts with "://", so it matches
     * http and https, but no other host that only ends with the same name. A value with a
     * path that the value of its host already covers is left out.
     *
     * @return list<string>
     */
    private function buildPageUrlFilter(Site $site): array
    {
        $values = [];
        foreach ([$site->getBase(), ...array_map(static fn ($language) => $language->getBase(), $site->getAllLanguages())] as $base) {
            if ($base->getHost() === '') {
                continue;
            }
            $port = $base->getPort();
            $values[] = '://' . $base->getHost() . ($port !== null ? ':' . $port : '') . rtrim($base->getPath(), '/');
        }
        $values = array_unique($values);
        sort($values);

        $filter = [];
        foreach ($values as $value) {
            foreach ($filter as $shorter) {
                if (str_starts_with($value, $shorter . '/')) {
                    continue 2;
                }
            }
            $filter[] = $value;
        }
        return $filter;
    }
}
