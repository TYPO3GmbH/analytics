<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Service\AnalyticsStatusService;
use T3G\Analytics\Service\InstanceRegistrationService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

final readonly class BackendModuleController
{
    private const float CREDIT_WARNING_REMAINING_RATIO = 0.25;

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private UriBuilder $uriBuilder,
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
        $this->configureModuleTemplate(
            $moduleTemplate,
            $this->translate('backend.headline'),
            moduleClass: 'module-layout-normal'
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
            $this->registrationService->register($site, $email);
        } catch (\RuntimeException $e) {
            $this->addErrorFlashMessage('flash.registrationFailed', [$e->getMessage()]);
            return new RedirectResponse($indexUri);
        }

        $this->addFlashMessage(
            $this->translate('flash.success.registered', [$site->getIdentifier()]),
            $this->translate('flash.success.title')
        );

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
        $pageName = $this->getRootPageTitle($site->getRootPageId());
        $siteLabel = $this->siteLabel($pageName, $site->getIdentifier());

        $dashboardUrl = $this->analyticsStatusService->getDashboardUrl($site);
        if ($dashboardUrl === null) {
            $this->logger->error('Dashboard action: dashboard URL unavailable.', ['siteIdentifier' => $siteIdentifier]);
            $this->addErrorFlashMessage('flash.dashboardUrlUnavailable');
            return new RedirectResponse($indexUri);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $this->configureModuleTemplate(
            $moduleTemplate,
            $this->translate('backend.headline'),
            $this->translate('button.dashboard') . ': ' . $siteLabel,
            'tx-analytics-iframe-module',
            'site_analytics.dashboard',
            ['siteIdentifier' => $siteIdentifier],
            $this->shortcutLabel($this->translate('button.dashboard'), $siteLabel)
        );
        $this->addBreadcrumbSuffix($moduleTemplate, 'dashboard', $this->translate('button.dashboard') . ': ' . $siteLabel, 'actions-view');

        $parsedUrl = parse_url($dashboardUrl);
        $dashboardOrigin = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');
        if (isset($parsedUrl['port'])) {
            $dashboardOrigin .= ':' . $parsedUrl['port'];
        }

        $moduleTemplate->assignMultiple([
            'site' => [
                'identifier' => $site->getIdentifier(),
                'title' => $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier(),
                'pageName' => $pageName,
            ],
            'dashboardUrl' => $dashboardUrl,
            'dashboardOrigin' => $dashboardOrigin,
            'invalidateStatusCacheUri' => (string)$this->uriBuilder->buildUriFromRoute(
                'site_analytics.invalidate_status_cache',
                ['siteIdentifier' => $siteIdentifier]
            ),
        ]);

        return $moduleTemplate->renderResponse('Backend/Dashboard');
    }

    public function managePlanAction(ServerRequestInterface $request): ResponseInterface
    {
        $siteIdentifier = (string)($request->getQueryParams()['siteIdentifier'] ?? '');
        $indexUri = $this->indexUri();

        if ($siteIdentifier === '') {
            $this->logger->warning('Manage plan action called without siteIdentifier.');
            $this->addErrorFlashMessage('flash.invalidInput');
            return new RedirectResponse($indexUri);
        }

        $site = $this->resolveSite($siteIdentifier);
        if (!$site instanceof Site) {
            return new RedirectResponse($indexUri);
        }
        $pageName = $this->getRootPageTitle($site->getRootPageId());
        $siteLabel = $this->siteLabel($pageName, $site->getIdentifier());

        $managePlanUrl = $this->analyticsStatusService->getManagePlanUrl($site);
        if ($managePlanUrl === null) {
            $this->logger->error('Manage plan action: URL unavailable.', ['siteIdentifier' => $siteIdentifier]);
            $this->addErrorFlashMessage('flash.managePlanUrlUnavailable');
            return new RedirectResponse($indexUri);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $this->configureModuleTemplate(
            $moduleTemplate,
            $this->translate('backend.headline'),
            $this->translate('button.managePlan') . ': ' . $siteLabel,
            'tx-analytics-iframe-module',
            'site_analytics.manage_plan',
            ['siteIdentifier' => $siteIdentifier],
            $this->shortcutLabel($this->translate('button.managePlan'), $siteLabel)
        );
        $this->addBreadcrumbSuffix($moduleTemplate, 'manage-plan', $this->translate('button.managePlan') . ': ' . $siteLabel, 'actions-credit-card');
        $moduleTemplate->assignMultiple([
            'managePlanUrl' => $managePlanUrl,
        ]);

        return $moduleTemplate->renderResponse('Backend/ManagePlan');
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

    public function invalidateStatusCacheAction(ServerRequestInterface $request): ResponseInterface
    {
        $siteIdentifier = (string)($request->getQueryParams()['siteIdentifier'] ?? '');

        if ($siteIdentifier === '') {
            return new JsonResponse(['success' => false], 400);
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (SiteNotFoundException) {
            return new JsonResponse(['success' => false], 404);
        }

        $this->analyticsStatusService->clearCache($site);
        return new JsonResponse(['success' => true]);
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

    private function configureModuleTemplate(
        ModuleTemplate $moduleTemplate,
        string $title,
        string $context = '',
        string $moduleClass = '',
        string $shortcutRouteIdentifier = 'site_analytics',
        array $shortcutArguments = [],
        string $shortcutDisplayName = ''
    ): void {
        $moduleTemplate->setTitle($title, $context);
        if ($moduleClass !== '') {
            $moduleTemplate->setModuleClass($moduleClass);
        }

        $moduleTemplate->getDocHeaderComponent()->enable();
        $this->addShortcutButton(
            $moduleTemplate,
            $shortcutRouteIdentifier,
            $shortcutArguments,
            $shortcutDisplayName !== '' ? $shortcutDisplayName : $title
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function addShortcutButton(
        ModuleTemplate $moduleTemplate,
        string $routeIdentifier,
        array $arguments,
        string $displayName
    ): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $shortcutButton = $buttonBar->makeShortcutButton()
            ->setRouteIdentifier($routeIdentifier)
            ->setArguments($arguments)
            ->setDisplayName($displayName);
        $buttonBar->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT);
    }

    private function addBreadcrumbSuffix(
        ModuleTemplate $moduleTemplate,
        string $identifier,
        string $label,
        string $iconIdentifier
    ): void {
        $docHeader = $moduleTemplate->getDocHeaderComponent();
        $methodName = 'addBreadcrumbSuffixNode';
        $breadcrumbNodeClass = 'TYPO3\\CMS\\Backend\\Dto\\Breadcrumb\\BreadcrumbNode';

        if (!method_exists($docHeader, $methodName) || !class_exists($breadcrumbNodeClass)) {
            return;
        }

        $docHeader->{$methodName}(...)(
            new $breadcrumbNodeClass(
                identifier: $identifier,
                label: $label,
                icon: $iconIdentifier,
                iconOverlay: null,
                url: null,
            )
        );
    }

    /**
     * @return list<array{identifier: string, title: string, pageName: string, domain: string, websiteId: string|null, registered: bool, status: array<string, mixed>|null, panelClass: string, dashboardUri: string, managePlanUri: string}>
     */
    private function fetchSites(): array
    {
        $sites = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            $websiteId = $site->getSettings()->get('websiteId', '') ?: null;
            $registered = $websiteId !== null;

            $status = $this->prepareStatus($registered ? $this->analyticsStatusService->getStatus($site) : null);

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
     * @param array<string, mixed>|null $status
     * @return array<string, mixed>|null
     */
    private function prepareStatus(?array $status): ?array
    {
        if (!is_array($status['consumption'] ?? null)) {
            return $status;
        }

        $consumption = $status['consumption'];
        $hasLimit = array_key_exists('stpLimit', $consumption);
        $limit = (int)($consumption['stpLimit'] ?? 0);
        $remaining = array_key_exists('stpRemaining', $consumption)
            ? (int)$consumption['stpRemaining']
            : max(0, $limit - (int)($consumption['stpConsumed'] ?? 0));
        $exhausted = (bool)($consumption['exhausted'] ?? false);

        $consumption['limited'] = $hasLimit && $limit !== -1;
        $consumption['warning'] = $limit > 0
            && !$exhausted
            && ($remaining / $limit) <= self::CREDIT_WARNING_REMAINING_RATIO;

        $status['consumption'] = $consumption;
        return $status;
    }

    /** @param array<string, mixed>|null $status */
    private function resolvePanelClass(?array $status): string
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

    private function siteLabel(string $pageName, string $siteIdentifier): string
    {
        $pageName = trim($pageName);
        if ($pageName === '') {
            return $siteIdentifier;
        }

        return $pageName . ' (' . $siteIdentifier . ')';
    }

    private function shortcutLabel(string $actionLabel, string $siteLabel): string
    {
        return $this->translate('backend.headline') . ' - ' . $actionLabel . ': ' . $siteLabel;
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
