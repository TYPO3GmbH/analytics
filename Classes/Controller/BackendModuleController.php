<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Backend\ModuleConfigurator;
use T3G\Analytics\Service\ApiKeyServiceInterface;
use T3G\Analytics\Service\SiteDataProviderInterface;
use T3G\Analytics\Service\AnalyticsStatusServiceInterface;
use T3G\Analytics\Service\InstanceRegistrationServiceInterface;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

final readonly class BackendModuleController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private UriBuilder $uriBuilder,
        private FlashMessageService $flashMessageService,
        private SiteFinder $siteFinder,
        private InstanceRegistrationServiceInterface $registrationService,
        private AnalyticsStatusServiceInterface $analyticsStatusService,
        private ApiKeyServiceInterface $apiKeyService,
        private ModuleConfigurator $moduleHelper,
        private SiteDataProviderInterface $siteDataProvider,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws RouteNotFoundException
     * @throws Exception
     */
    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $this->moduleHelper->configureModuleTemplate(
            $moduleTemplate,
            $this->translate('backend.headline'),
            moduleClass: 'module-layout-normal'
        );

        $moduleTemplate->assignMultiple([
            'sites' => $this->siteDataProvider->fetchSites(),
            'registerUri' => (string)$this->uriBuilder->buildUriFromRoute('site_analytics.register'),
            'statusUri' => (string)$this->uriBuilder->buildUriFromRoute('site_analytics.status'),
        ]);

        return $moduleTemplate->renderResponse('Backend/Index');
    }

    /**
     * @throws RouteNotFoundException
     */
    public function registerAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $this->logger->warning('Register action called with invalid request body.');
            return new JsonResponse($this->errorPayload('flash.invalidInput'), 400);
        }

        $siteIdentifier = (string)($body['siteIdentifier'] ?? '');
        $email = (string)($body['email'] ?? '');

        if ($siteIdentifier === '' || $email === '') {
            $this->logger->warning('Register action called with missing siteIdentifier or email.');
            return new JsonResponse($this->errorPayload('flash.invalidInput'), 400);
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (SiteNotFoundException) {
            $this->logger->error('Site not found.', ['siteIdentifier' => $siteIdentifier]);
            return new JsonResponse($this->errorPayload('flash.siteNotFound'), 404);
        }

        try {
            $this->registrationService->register($site, $email);
        } catch (AnalyticsApiException $e) {
            return new JsonResponse($this->errorPayload('flash.registrationFailed', [$e->getMessage()]), 500);
        }

        // Re-load the site so the freshly written credentials are visible, then
        // fetch and persist the initial status (including trackingCode) immediately.
        try {
            $registeredSite = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
            $status = $this->analyticsStatusService->getStatus($registeredSite, forceRefresh: true);
            if ($status !== null) {
                $this->analyticsStatusService->syncSiteSettingsFromStatus($registeredSite, $status);
            }
        } catch (SiteNotFoundException) {
            // Non-fatal: tracking code will be synced on the next manual refresh.
        }

        return new JsonResponse([
            'success' => true,
            'title' => $this->translate('flash.success.title'),
            'message' => $this->translate('flash.success.registered', [$site->getIdentifier()]),
            'dashboardUri' => (string)$this->uriBuilder->buildUriFromRoute('site_analytics.dashboard', ['siteIdentifier' => $siteIdentifier]),
        ]);
    }

    /**
     * @throws RouteNotFoundException
     * @throws Exception
     */
    public function dashboardAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $siteIdentifier = (string)($queryParams['siteIdentifier'] ?? '');

        if ($siteIdentifier === '') {
            $this->logger->warning('Dashboard action called without siteIdentifier.');
            $this->addFlashMessage('flash.invalidInput', 'flash.error.title', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->indexUri());
        }

        $dateParams = $this->buildDateParams((int)($queryParams['days'] ?? 0));

        return $this->renderIframeModule(
            request: $request,
            siteIdentifier: $siteIdentifier,
            urlResolver: function (Site $site) use ($dateParams): ?string {
                $url = $this->analyticsStatusService->getDashboardUrl($site);
                if ($url !== null && $dateParams !== '') {
                    $url .= (str_contains($url, '?') ? '&' : '?') . $dateParams;
                }
                return $url;
            },
            template: 'Dashboard',
            routeName: 'site_analytics.dashboard',
            actionLabelKey: 'button.dashboard',
            urlUnavailableKey: 'flash.dashboardUrlUnavailable',
            breadcrumbId: 'dashboard',
            breadcrumbIcon: 'actions-view',
            urlVar: 'dashboardUrl',
            extraTemplateVars: static function (Site $site, string $pn): array {
                return [
                    'site' => [
                        'identifier' => $site->getIdentifier(),
                        'title' => $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier(),
                        'pageName' => $pn,
                    ],
                ];
            },
        );
    }

    /**
     * @throws RouteNotFoundException
     * @throws Exception
     */
    public function managePlanAction(ServerRequestInterface $request): ResponseInterface
    {
        $siteIdentifier = (string)($request->getQueryParams()['siteIdentifier'] ?? '');

        if ($siteIdentifier === '') {
            $this->logger->warning('Manage plan action called without siteIdentifier.');
            $this->addFlashMessage('flash.invalidInput', 'flash.error.title', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->indexUri());
        }

        return $this->renderIframeModule(
            request: $request,
            siteIdentifier: $siteIdentifier,
            urlResolver: fn (Site $site): ?string => $this->analyticsStatusService->getManagePlanUrl($site),
            template: 'ManagePlan',
            routeName: 'site_analytics.manage_plan',
            actionLabelKey: 'button.managePlan',
            urlUnavailableKey: 'flash.managePlanUrlUnavailable',
            breadcrumbId: 'manage-plan',
            breadcrumbIcon: 'actions-credit-card',
            urlVar: 'managePlanUrl',
        );
    }

    public function statusAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $siteIdentifier = is_array($body) ? (string)($body['siteIdentifier'] ?? '') : '';

        if ($siteIdentifier === '') {
            $this->logger->warning('Status action called without siteIdentifier.');
            return new JsonResponse($this->errorPayload('flash.invalidInput'), 400);
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (SiteNotFoundException) {
            $this->logger->error('Site not found.', ['siteIdentifier' => $siteIdentifier]);
            return new JsonResponse($this->errorPayload('flash.siteNotFound'), 404);
        }

        $status = $this->analyticsStatusService->getStatus($site, forceRefresh: true);
        if ($status === null) {
            $this->logger->error('Status action: status fetch failed.', ['siteIdentifier' => $siteIdentifier]);
            return new JsonResponse($this->errorPayload('flash.statusFetchFailed'), 500);
        }

        $this->analyticsStatusService->syncSiteSettingsFromStatus($site, $status);
        $this->apiKeyService->provisionIfNeeded($site, $status);

        return new JsonResponse([
            'success' => true,
            'title' => $this->translate('notification.statusRefreshed.title'),
        ]);
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

    /**
     * @param callable(Site): ?string $urlResolver
     * @param callable(Site, string): array<string, mixed>|null $extraTemplateVars
     * @throws RouteNotFoundException
     * @throws Exception
     */
    private function renderIframeModule(
        ServerRequestInterface $request,
        string $siteIdentifier,
        callable $urlResolver,
        string $template,
        string $routeName,
        string $actionLabelKey,
        string $urlUnavailableKey,
        string $breadcrumbId,
        string $breadcrumbIcon,
        string $urlVar,
        ?callable $extraTemplateVars = null,
    ): ResponseInterface {
        $indexUri = $this->indexUri();

        $site = $this->resolveSite($siteIdentifier);
        if (!$site instanceof Site) {
            return new RedirectResponse($indexUri);
        }

        $pageName = $this->siteDataProvider->getRootPageTitle($site->getRootPageId());
        $siteLabel = $this->siteDataProvider->siteLabel($pageName, $site->getIdentifier());

        $url = $urlResolver($site);
        if ($url === null) {
            $this->logger->error($template . ' action: URL unavailable.', ['siteIdentifier' => $siteIdentifier]);
            $this->addFlashMessage($urlUnavailableKey, 'flash.error.title', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($indexUri);
        }

        $status = $this->analyticsStatusService->getStatus($site);
        if ($status !== null) {
            $this->analyticsStatusService->syncSiteSettingsFromStatus($site, $status);
            $this->apiKeyService->provisionIfNeeded($site, $status);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $headline = $this->translate('backend.headline');
        $actionLabel = $this->translate($actionLabelKey);

        $this->moduleHelper->configureModuleTemplate(
            $moduleTemplate,
            $headline,
            $actionLabel . ': ' . $siteLabel,
            'tx-analytics-iframe-module',
            $routeName,
            ['siteIdentifier' => $siteIdentifier],
            $this->shortcutLabel($headline, $actionLabel, $siteLabel),
            $siteIdentifier,
            $routeName
        );
        $this->moduleHelper->addBreadcrumbSuffix($moduleTemplate, $breadcrumbId, $actionLabel . ': ' . $siteLabel, $breadcrumbIcon);

        $vars = array_merge(
            [
                $urlVar => $url,
                'iframeOrigin' => $this->buildIframeOrigin($url),
                'invalidateStatusCacheUri' => (string)$this->uriBuilder->buildUriFromRoute(
                    'site_analytics.invalidate_status_cache',
                    ['siteIdentifier' => $siteIdentifier]
                ),
            ],
            $extraTemplateVars !== null ? $extraTemplateVars($site, $pageName) : []
        );
        $moduleTemplate->assignMultiple($vars);

        return $moduleTemplate->renderResponse('Backend/' . $template);
    }

    private function buildDateParams(int $days): string
    {
        if ($days <= 0) {
            return '';
        }
        $now = new \DateTimeImmutable('today');
        $from = $now->modify('-' . ($days - 1) . ' days');
        return 'startDate=' . $from->format('Y-m-d') . '&endDate=' . $now->format('Y-m-d');
    }

    private function buildIframeOrigin(string $url): string
    {
        $parsed = parse_url($url);
        $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        if (isset($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }
        return $origin;
    }

    private function resolveSite(string $siteIdentifier): ?Site
    {
        try {
            return $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (SiteNotFoundException) {
            $this->logger->error('Site not found.', ['siteIdentifier' => $siteIdentifier]);
            $this->addFlashMessage('flash.siteNotFound', 'flash.error.title', ContextualFeedbackSeverity::ERROR);
            return null;
        }
    }

    /**
     * @throws RouteNotFoundException
     */
    private function indexUri(): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('site_analytics');
    }

    private function shortcutLabel(string $headline, string $actionLabel, string $siteLabel): string
    {
        return $headline . ' - ' . $actionLabel . ': ' . $siteLabel;
    }

    /** @param list<mixed> $arguments */
    private function addFlashMessage(
        string $messageKey,
        string $titleKey = '',
        ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK,
        array $arguments = []
    ): void {
        $message = new FlashMessage(
            $this->translate($messageKey, $arguments),
            $titleKey !== '' ? $this->translate($titleKey) : '',
            $severity,
            true,
        );
        $this->flashMessageService->getMessageQueueByIdentifier()->addMessage($message);
    }

    /**
     * @param list<mixed> $arguments
     * @return array{success: false, title: string, message: string}
     */
    private function errorPayload(string $messageKey, array $arguments = []): array
    {
        return [
            'success' => false,
            'title' => $this->translate('flash.error.title'),
            'message' => $this->translate($messageKey, $arguments),
        ];
    }

    /** @param list<mixed> $args */
    private function translate(string $key, array $args = []): string
    {
        $lll = 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:' . $key;
        $value = (string)($GLOBALS['LANG']->sL($lll) ?? '');
        if ($value === '') {
            return $key;
        }
        return $args !== [] ? vsprintf($value, $args) : $value;
    }
}
