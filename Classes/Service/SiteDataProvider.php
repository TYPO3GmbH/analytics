<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Doctrine\DBAL\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class SiteDataProvider
{
    public function __construct(
        private SiteFinder $siteFinder,
        private AnalyticsStatusService $analyticsStatusService,
        private ConnectionPool $connectionPool,
        private UriBuilder $uriBuilder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{identifier: string, title: string, pageName: string, domain: string, websiteId: string|null, registered: bool, status: array<string, mixed>|null, panelClass: string, dashboardUri: string, managePlanUri: string}>
     * @throws RouteNotFoundException
     * @throws Exception
     */
    public function fetchSites(): array
    {
        $sites = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                $websiteId = $site->getSettings()->get('websiteId', '') ?: null;
            } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
                $this->logger->warning('Failed to read site settings.', ['siteIdentifier' => $site->getIdentifier(), 'exception' => $e]);
                $websiteId = null;
            }
            $registered = $websiteId !== null;

            $status = $registered ? $this->analyticsStatusService->getStatus($site) : null;

            $sites[] = [
                'identifier' => $site->getIdentifier(),
                'title' => $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier(),
                'pageName' => $this->getRootPageTitle($site->getRootPageId()),
                'domain' => rtrim($site->getBase()->__toString(), '/'),
                'websiteId' => $websiteId,
                'registered' => $registered,
                'status' => $status,
                'panelClass' => $this->resolvePanelClass($status),
                'dashboardUri' => $registered ? (string)$this->uriBuilder->buildUriFromRoute(
                    'site_analytics.dashboard',
                    ['siteIdentifier' => $site->getIdentifier()]
                ) : '',
                'managePlanUri' => $registered ? (string)$this->uriBuilder->buildUriFromRoute(
                    'site_analytics.manage_plan',
                    ['siteIdentifier' => $site->getIdentifier()]
                ) : '',
            ];
        }

        return $sites;
    }

    /**
     * @return list<array{identifier: string, label: string}>
     * @throws Exception
     */
    public function registeredSiteOptions(): array
    {
        $sites = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                $websiteId = $site->getSettings()->get('websiteId', '') ?: null;
            } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
                $this->logger->warning('Failed to read site settings.', ['siteIdentifier' => $site->getIdentifier(), 'exception' => $e]);
                $websiteId = null;
            }
            if ($websiteId === null) {
                continue;
            }

            $sites[] = [
                'identifier' => $site->getIdentifier(),
                'label' => $this->siteLabel(
                    $this->getRootPageTitle($site->getRootPageId()),
                    $site->getIdentifier()
                ),
            ];
        }

        return $sites;
    }

    /**
     * @throws Exception
     */
    public function getRootPageTitle(int $pageUid): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $row = $queryBuilder
            ->select('title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? (string)$row['title'] : '';
    }

    public function siteLabel(string $pageName, string $siteIdentifier): string
    {
        $pageName = trim($pageName);
        if ($pageName === '') {
            return $siteIdentifier;
        }

        return $pageName . ' (' . $siteIdentifier . ')';
    }

    /** @param array<string, mixed>|null $status */
    public function resolvePanelClass(?array $status): string
    {
        if (!is_array($status['consumption'] ?? null)) {
            return 'panel-default';
        }

        $consumption = $status['consumption'];
        if (($consumption['limited'] ?? false) && ($consumption['exhausted'] ?? false)) {
            return 'panel-danger';
        }

        if ($consumption['warning'] ?? false) {
            return 'panel-warning';
        }

        return 'panel-default';
    }
}
