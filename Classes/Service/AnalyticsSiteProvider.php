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
     * @return array{site: Site, websiteId: string, apiKey: string}|null
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
     * @return array{site: Site, websiteId: string, apiKey: string}|null
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
            return ['site' => $site, 'websiteId' => $websiteId, 'apiKey' => $apiKey];
        } catch (\Throwable) {
            return null;
        }
    }
}
