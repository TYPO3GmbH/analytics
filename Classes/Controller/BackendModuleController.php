<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Service\AnalyticsStatusService;
use T3G\Analytics\Service\InstanceRegistrationService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

final readonly class BackendModuleController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private UriBuilder $uriBuilder,
        private IconFactory $iconFactory,
        private FlashMessageService $flashMessageService,
        private SiteFinder $siteFinder,
        private InstanceRegistrationService $registrationService,
        private AnalyticsStatusService $analyticsStatusService,
        private ConnectionPool $connectionPool,
        private LoggerInterface $logger,
    ) {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $indexUri = $this->indexUri();

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $buttonBar->addButton(
            $buttonBar->makeLinkButton()
                ->setTitle($this->translate('button.refresh'))
                ->setHref($indexUri)
                ->setIcon($this->iconFactory->getIcon('actions-refresh', IconSize::SMALL))
        );

        $moduleTemplate->assignMultiple([
            'sites' => $this->fetchSites(),
            'registerUri' => (string)$this->uriBuilder->buildUriFromRoute('site_analytics.register'),
            'statusUri' => (string)$this->uriBuilder->buildUriFromRoute('site_analytics.status'),
        ]);

        return $moduleTemplate->renderResponse('Backend/Index');
    }

    public function registerAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $siteIdentifier = is_array($body) ? (string)($body['siteIdentifier'] ?? '') : '';
        $email = is_array($body) ? (string)($body['email'] ?? '') : '';
        $indexUri = $this->indexUri();

        if ($siteIdentifier === '' || $email === '') {
            $this->logger->warning('Register action called with missing siteIdentifier or email.');
            $this->addErrorFlashMessage('flash.invalidInput');
            return new RedirectResponse($indexUri);
        }

        $site = $this->resolveSite($siteIdentifier);
        if (!$site instanceof Site) {
            return new RedirectResponse($indexUri);
        }

        try {
            $checkoutUrl = $this->registrationService->register($site, $email);
        } catch (\RuntimeException $e) {
            $this->addErrorFlashMessage('flash.registrationFailed', [$e->getMessage()]);
            return new RedirectResponse($indexUri);
        }

        $this->addFlashMessage(
            $this->translate('flash.success.registered', [$site->getIdentifier()]),
            $this->translate('flash.success.title')
        );

        if ($checkoutUrl !== '') {
            $this->logger->info('Redirecting to checkout page.', ['siteIdentifier' => $siteIdentifier]);
            return new RedirectResponse(
                (string)$this->uriBuilder->buildUriFromRoute(
                    'site_analytics.checkout',
                    ['checkoutUrl' => rtrim($checkoutUrl, '/')]
                )
            );
        }

        return new RedirectResponse($indexUri);
    }

    public function dashboardAction(ServerRequestInterface $request): ResponseInterface
    {
        $siteIdentifier = (string)($request->getQueryParams()['siteIdentifier'] ?? '');
        $indexUri = $this->indexUri();

        if ($siteIdentifier === '') {
            $this->logger->warning('Dashboard action called without siteIdentifier.');
            $this->addErrorFlashMessage('flash.invalidInput');
            return new RedirectResponse($indexUri);
        }

        $site = $this->resolveSite($siteIdentifier);
        if (!$site instanceof Site) {
            return new RedirectResponse($indexUri);
        }

        $dashboardUrl = $this->analyticsStatusService->getDashboardUrl($site);
        if ($dashboardUrl === null) {
            $this->logger->error('Dashboard action: dashboard URL unavailable.', ['siteIdentifier' => $siteIdentifier]);
            $this->addErrorFlashMessage('flash.dashboardUrlUnavailable');
            return new RedirectResponse($indexUri);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $moduleTemplate->assignMultiple([
            'site' => [
                'identifier' => $site->getIdentifier(),
                'title' => $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier(),
                'pageName' => $this->getRootPageTitle($site->getRootPageId()),
            ],
            'dashboardUrl' => $dashboardUrl,
        ]);

        return $moduleTemplate->renderResponse('Backend/Dashboard');
    }

    public function checkoutAction(ServerRequestInterface $request): ResponseInterface
    {
        $checkoutUrl = (string)($request->getQueryParams()['checkoutUrl'] ?? '');
        $indexUri = $this->indexUri();

        if ($checkoutUrl === '' || !str_starts_with($checkoutUrl, 'https://')) {
            $this->logger->warning('Checkout action called with missing or invalid checkoutUrl.');
            return new RedirectResponse($indexUri);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $moduleTemplate->assignMultiple([
            'checkoutUrl' => $checkoutUrl,
            'indexUri' => $indexUri,
        ]);

        return $moduleTemplate->renderResponse('Backend/Checkout');
    }

    public function statusAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $siteIdentifier = is_array($body) ? (string)($body['siteIdentifier'] ?? '') : '';
        $indexUri = $this->indexUri();

        if ($siteIdentifier === '') {
            $this->logger->warning('Status action called without siteIdentifier.');
            $this->addErrorFlashMessage('flash.invalidInput');
            return new RedirectResponse($indexUri);
        }

        $site = $this->resolveSite($siteIdentifier);
        if (!$site instanceof Site) {
            return new RedirectResponse($indexUri);
        }

        if ($this->analyticsStatusService->getStatus($site, forceRefresh: true) === null) {
            $this->logger->error('Status action: status fetch failed.', ['siteIdentifier' => $siteIdentifier]);
            $this->addErrorFlashMessage('flash.statusFetchFailed');
            return new RedirectResponse($indexUri);
        }

        return new RedirectResponse($indexUri);
    }

    private function resolveSite(string $siteIdentifier): ?Site
    {
        try {
            return $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (SiteNotFoundException) {
            $this->logger->error('Site not found.', ['siteIdentifier' => $siteIdentifier]);
            $this->addErrorFlashMessage('flash.siteNotFound');
            return null;
        }
    }

    private function indexUri(): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('site_analytics');
    }

    /**
     * @return list<array{identifier: string, title: string, pageName: string, domain: string, websiteId: string|null, registered: bool, status: array<string, mixed>|null, dashboardUri: string}>
     */
    private function fetchSites(): array
    {
        $sites = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            $websiteId = $site->getSettings()->get('websiteId', '') ?: null;
            $registered = $websiteId !== null;

            $sites[] = [
                'identifier' => $site->getIdentifier(),
                'title' => $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier(),
                'pageName' => $this->getRootPageTitle($site->getRootPageId()),
                'domain' => rtrim($site->getBase()->__toString(), '/'),
                'websiteId' => $websiteId,
                'registered' => $registered,
                'status' => $registered ? $this->analyticsStatusService->getStatus($site) : null,
                'dashboardUri' => $registered ? (string)$this->uriBuilder->buildUriFromRoute(
                    'site_analytics.dashboard',
                    ['siteIdentifier' => $site->getIdentifier()]
                ) : '',
            ];
        }

        return $sites;
    }

    private function getRootPageTitle(int $pageUid): string
    {
        $row = $this->connectionPool
            ->getQueryBuilderForTable('pages')
            ->select('title')
            ->from('pages')
            ->where('uid = ' . $pageUid)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? (string)$row['title'] : '';
    }

    /** @param list<mixed>|null $arguments */
    private function translate(string $key, ?array $arguments = null): string
    {
        return LocalizationUtility::translate($key, 'analytics', $arguments) ?? $key;
    }

    /** @param list<mixed> $arguments */
    private function addErrorFlashMessage(string $key, array $arguments = []): void
    {
        $this->addFlashMessage(
            $this->translate($key, $arguments ?: null),
            $this->translate('flash.error.title'),
            ContextualFeedbackSeverity::ERROR
        );
    }

    private function addFlashMessage(
        string $messageBody,
        string $messageTitle = '',
        ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK,
        bool $storeInSession = true
    ): void {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $messageBody,
            $messageTitle,
            $severity,
            $storeInSession
        );

        $this->flashMessageService->getMessageQueueByIdentifier()->addMessage($flashMessage);
    }
}
