<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use T3G\Analytics\Helper\BackendModuleHelper;
use T3G\Analytics\Service\SiteDataProvider;
use T3G\Analytics\Service\AnalyticsStatusService;
use T3G\Analytics\Service\InstanceRegistrationService;
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
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

final readonly class BackendModuleController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private UriBuilder $uriBuilder,
        private FlashMessageService $flashMessageService,
        private SiteFinder $siteFinder,
        private InstanceRegistrationService $registrationService,
        private AnalyticsStatusService $analyticsStatusService,
        private BackendModuleHelper $moduleHelper,
        private SiteDataProvider $siteDataProvider,
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
            LocalizationUtility::translate('backend.headline', 'analytics') ?? 'backend.headline',
            moduleClass: 'module-layout-normal'
        );

        $moduleTemplate->assignMultiple([
            'sites' => $this->siteDataProvider->fetchSites(),
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
        } catch (RuntimeException $e) {
            return new JsonResponse($this->errorPayload('flash.registrationFailed', [$e->getMessage()]), 500);
        }

        return new JsonResponse([
            'success' => true,
            'title' => LocalizationUtility::translate('flash.success.title', 'analytics') ?? '',
            'message' => LocalizationUtility::translate('flash.success.registered', 'analytics', [$site->getIdentifier()]) ?? '',
        ]);
    }

    /**
     * @throws RouteNotFoundException
     * @throws Exception
     */
    public function dashboardAction(ServerRequestInterface $request): ResponseInterface
    {
        $siteIdentifier = (string)($request->getQueryParams()['siteIdentifier'] ?? '');
        $indexUri = $this->indexUri();

        if ($siteIdentifier === '') {
            $this->logger->warning('Dashboard action called without siteIdentifier.');
            $this->addFlashMessage('flash.invalidInput', 'flash.error.title', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($indexUri);
        }

        $site = $this->resolveSite($siteIdentifier);
        if (!$site instanceof Site) {
            return new RedirectResponse($indexUri);
        }
        $pageName = $this->siteDataProvider->getRootPageTitle($site->getRootPageId());
        $siteLabel = $this->siteDataProvider->siteLabel($pageName, $site->getIdentifier());

        $dashboardUrl = $this->analyticsStatusService->getDashboardUrl($site);
        if ($dashboardUrl === null) {
            $this->logger->error('Dashboard action: dashboard URL unavailable.', ['siteIdentifier' => $siteIdentifier]);
            $this->addFlashMessage('flash.dashboardUrlUnavailable', 'flash.error.title', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($indexUri);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $headline = LocalizationUtility::translate('backend.headline', 'analytics') ?? 'backend.headline';
        $dashboardLabel = LocalizationUtility::translate('button.dashboard', 'analytics') ?? 'button.dashboard';
        $this->moduleHelper->configureModuleTemplate(
            $moduleTemplate,
            $headline,
            $dashboardLabel . ': ' . $siteLabel,
            'tx-analytics-iframe-module',
            'site_analytics.dashboard',
            ['siteIdentifier' => $siteIdentifier],
            $this->shortcutLabel($headline, $dashboardLabel, $siteLabel),
            $siteIdentifier,
            'site_analytics.dashboard'
        );
        $this->moduleHelper->addBreadcrumbSuffix($moduleTemplate, 'dashboard', $dashboardLabel . ': ' . $siteLabel, 'actions-view');

        $parsedUrl = parse_url($dashboardUrl);
        $iframeOrigin = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');
        if (isset($parsedUrl['port'])) {
            $iframeOrigin .= ':' . $parsedUrl['port'];
        }

        $moduleTemplate->assignMultiple([
            'site' => [
                'identifier' => $site->getIdentifier(),
                'title' => $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier(),
                'pageName' => $pageName,
            ],
            'dashboardUrl' => $dashboardUrl,
            'iframeOrigin' => $iframeOrigin,
            'invalidateStatusCacheUri' => (string)$this->uriBuilder->buildUriFromRoute(
                'site_analytics.invalidate_status_cache',
                ['siteIdentifier' => $siteIdentifier]
            ),
        ]);

        return $moduleTemplate->renderResponse('Backend/Dashboard');
    }

    /**
     * @throws RouteNotFoundException
     * @throws Exception
     */
    public function managePlanAction(ServerRequestInterface $request): ResponseInterface
    {
        $siteIdentifier = (string)($request->getQueryParams()['siteIdentifier'] ?? '');
        $indexUri = $this->indexUri();

        if ($siteIdentifier === '') {
            $this->logger->warning('Manage plan action called without siteIdentifier.');
            $this->addFlashMessage('flash.invalidInput', 'flash.error.title', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($indexUri);
        }

        $site = $this->resolveSite($siteIdentifier);
        if (!$site instanceof Site) {
            return new RedirectResponse($indexUri);
        }
        $pageName = $this->siteDataProvider->getRootPageTitle($site->getRootPageId());
        $siteLabel = $this->siteDataProvider->siteLabel($pageName, $site->getIdentifier());

        $managePlanUrl = $this->analyticsStatusService->getManagePlanUrl($site);
        if ($managePlanUrl === null) {
            $this->logger->error('Manage plan action: URL unavailable.', ['siteIdentifier' => $siteIdentifier]);
            $this->addFlashMessage('flash.managePlanUrlUnavailable', 'flash.error.title', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($indexUri);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $headline = LocalizationUtility::translate('backend.headline', 'analytics') ?? 'backend.headline';
        $managePlanLabel = LocalizationUtility::translate('button.managePlan', 'analytics') ?? 'button.managePlan';
        $this->moduleHelper->configureModuleTemplate(
            $moduleTemplate,
            $headline,
            $managePlanLabel . ': ' . $siteLabel,
            'tx-analytics-iframe-module',
            'site_analytics.manage_plan',
            ['siteIdentifier' => $siteIdentifier],
            $this->shortcutLabel($headline, $managePlanLabel, $siteLabel),
            $siteIdentifier,
            'site_analytics.manage_plan'
        );
        $this->moduleHelper->addBreadcrumbSuffix($moduleTemplate, 'manage-plan', $managePlanLabel . ': ' . $siteLabel, 'actions-credit-card');
        $parsedUrl = parse_url($managePlanUrl);
        $iframeOrigin = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');
        if (isset($parsedUrl['port'])) {
            $iframeOrigin .= ':' . $parsedUrl['port'];
        }

        $moduleTemplate->assignMultiple([
            'managePlanUrl' => $managePlanUrl,
            'iframeOrigin' => $iframeOrigin,
            'invalidateStatusCacheUri' => (string)$this->uriBuilder->buildUriFromRoute(
                'site_analytics.invalidate_status_cache',
                ['siteIdentifier' => $siteIdentifier]
            ),
        ]);

        return $moduleTemplate->renderResponse('Backend/ManagePlan');
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

        if ($this->analyticsStatusService->getStatus($site, forceRefresh: true) === null) {
            $this->logger->error('Status action: status fetch failed.', ['siteIdentifier' => $siteIdentifier]);
            return new JsonResponse($this->errorPayload('flash.statusFetchFailed'), 500);
        }

        return new JsonResponse([
            'success' => true,
            'title' => LocalizationUtility::translate('notification.statusRefreshed.title', 'analytics') ?? '',
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
        $message = GeneralUtility::makeInstance(
            FlashMessage::class,
            LocalizationUtility::translate($messageKey, 'analytics', $arguments ?: null) ?? $messageKey,
            $titleKey !== '' ? (LocalizationUtility::translate($titleKey, 'analytics') ?? $titleKey) : '',
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
            'title' => LocalizationUtility::translate('flash.error.title', 'analytics') ?? 'Error',
            'message' => LocalizationUtility::translate($messageKey, 'analytics', $arguments ?: null) ?? $messageKey,
        ];
    }
}
