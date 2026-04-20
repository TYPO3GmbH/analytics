<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Analytics;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\AnalyticsStatusService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;
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
        private SiteSettingsService $siteSettingsService,
        private SiteSettingsFactory $siteSettingsFactory,
        private RequestFactory $requestFactory,
        private CipherService $cipherService,
        private AnalyticsStatusService $analyticsStatusService,
        private ConnectionPool $connectionPool,
        private LoggerInterface $logger,
    ) {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $buttonBar->addButton(
            $buttonBar->makeLinkButton()
                ->setTitle($this->translate('button.refresh'))
                ->setHref((string)$this->uriBuilder->buildUriFromRoute('site_analytics'))
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
        $indexUri = (string)$this->uriBuilder->buildUriFromRoute('site_analytics');

        if ($siteIdentifier === '' || $email === '') {
            $this->logger->warning('Register action called with missing siteIdentifier or email.');
            $this->addFlashMessage(
                $this->translate('flash.invalidInput'),
                $this->translate('flash.error.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse($indexUri);
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (SiteNotFoundException) {
            $this->logger->error('Register action: site not found.', ['siteIdentifier' => $siteIdentifier]);
            $this->addFlashMessage(
                $this->translate('flash.siteNotFound'),
                $this->translate('flash.error.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse($indexUri);
        }

        try {
            $response = $this->requestFactory->request(
                Analytics::getApiBaseUrl() . '/auth/register/instance',
                'POST',
                [
                    'json' => [
                        'intpId' => Analytics::INTP_ID,
                        'domain' => rtrim((string)$site->getBase(), '/'),
                        'email' => $email,
                    ],
                    'verify' => false,
                ]
            );

            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Register action: API request failed.',
                ['siteIdentifier' => $siteIdentifier, 'exception' => $e->getMessage()]
            );
            $this->addFlashMessage(
                $this->translate('flash.registrationFailed', [$e->getMessage()]),
                $this->translate('flash.error.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse($indexUri);
        }

        $instanceId = (string)($data['instanceId'] ?? '');
        $websiteId = (string)($data['websiteId'] ?? '');
        $instanceSecret = (string)($data['instanceSecret'] ?? '');
        $checkoutUrl = (string)($data['checkoutUrl'] ?? '');

        if ($websiteId === '' || $instanceId === '') {
            $this->logger->error(
                'Register action: API response incomplete.',
                ['siteIdentifier' => $siteIdentifier, 'data' => $data]
            );
            $this->addFlashMessage(
                $this->translate('flash.apiResponseIncomplete'),
                $this->translate('flash.error.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse($indexUri);
        }

        $encryptedSecret = $instanceSecret !== '' ? $this->cipherService->encrypt($instanceSecret) : '';

        $existing = $this->siteSettingsFactory->loadLocalSettings($siteIdentifier) ?? [];
        $this->siteSettingsService->writeSettings($site, array_merge($existing, [
            'websiteId' => $websiteId,
            'instanceId' => $instanceId,
            'instanceSecret' => $encryptedSecret,
        ]));

        $this->logger->info(
            'Site successfully registered.',
            ['siteIdentifier' => $siteIdentifier, 'websiteId' => $websiteId]
        );

        $this->addFlashMessage(
            $this->translate('flash.success.registered', [$site->getIdentifier()]),
            $this->translate('flash.success.title')
        );

        if ($checkoutUrl !== '') {
            $this->logger->info('Redirecting to checkout page.', ['siteIdentifier' => $siteIdentifier]);
            return new RedirectResponse(
                (string)$this->uriBuilder->buildUriFromRoute(
                    'site_analytics.checkout',
                    ['checkoutUrl' => $checkoutUrl]
                )
            );
        }

        return new RedirectResponse($indexUri);
    }

    public function dashboardAction(ServerRequestInterface $request): ResponseInterface
    {
        $siteIdentifier = (string)($request->getQueryParams()['siteIdentifier'] ?? '');
        $indexUri = (string)$this->uriBuilder->buildUriFromRoute('site_analytics');

        if ($siteIdentifier === '') {
            $this->logger->warning('Dashboard action called without siteIdentifier.');
            $this->addFlashMessage(
                $this->translate('flash.invalidInput'),
                $this->translate('flash.error.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse($indexUri);
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (SiteNotFoundException) {
            $this->logger->error('Dashboard action: site not found.', ['siteIdentifier' => $siteIdentifier]);
            $this->addFlashMessage(
                $this->translate('flash.siteNotFound'),
                $this->translate('flash.error.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse($indexUri);
        }

        $dashboardUrl = $this->analyticsStatusService->getDashboardUrl($site);

        if ($dashboardUrl === null) {
            $this->logger->error('Dashboard action: dashboard URL unavailable.', ['siteIdentifier' => $siteIdentifier]);
            $this->addFlashMessage(
                $this->translate('flash.dashboardUrlUnavailable'),
                $this->translate('flash.error.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse($indexUri);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $buttonBar->addButton(
            $buttonBar->makeLinkButton()
                ->setTitle($this->translate('button.backToOverview'))
                ->setHref($indexUri)
                ->setIcon($this->iconFactory->getIcon('actions-arrow-left', IconSize::SMALL))
        );

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
        $indexUri = (string)$this->uriBuilder->buildUriFromRoute('site_analytics');

        if ($checkoutUrl === '' || !str_starts_with($checkoutUrl, 'https://')) {
            $this->logger->warning('Checkout action called with missing or invalid checkoutUrl.');
            return new RedirectResponse($indexUri);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $buttonBar->addButton(
            $buttonBar->makeLinkButton()
                ->setTitle($this->translate('button.backToOverview'))
                ->setHref($indexUri)
                ->setIcon($this->iconFactory->getIcon('actions-arrow-left', IconSize::SMALL))
        );

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
        $indexUri = (string)$this->uriBuilder->buildUriFromRoute('site_analytics');

        if ($siteIdentifier === '') {
            $this->logger->warning('Status action called without siteIdentifier.');
            $this->addFlashMessage(
                $this->translate('flash.invalidInput'),
                $this->translate('flash.error.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse($indexUri);
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (SiteNotFoundException) {
            $this->logger->error('Status action: site not found.', ['siteIdentifier' => $siteIdentifier]);
            $this->addFlashMessage(
                $this->translate('flash.siteNotFound'),
                $this->translate('flash.error.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse($indexUri);
        }

        $status = $this->analyticsStatusService->getStatus($site, forceRefresh: true);

        if ($status === null) {
            $this->logger->error('Status action: status fetch failed.', ['siteIdentifier' => $siteIdentifier]);
            $this->addFlashMessage(
                $this->translate('flash.statusFetchFailed'),
                $this->translate('flash.error.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse($indexUri);
        }

        $trackingCode = (string)($status['trackingId'] ?? '');
        $newStatus = (string)($status['status'] ?? '');
        $settings = $site->getSettings();

        if (
            ($trackingCode !== '' && $trackingCode !== $settings->get('trackingCode', ''))
            || ($newStatus !== '' && $newStatus !== $settings->get('status', ''))
        ) {
            $existing = $this->siteSettingsFactory->loadLocalSettings($siteIdentifier) ?? [];
            $update = [];
            if ($trackingCode !== '') {
                $update['trackingCode'] = $trackingCode;
            }
            if ($newStatus !== '') {
                $update['status'] = $newStatus;
            }
            $this->siteSettingsService->writeSettings($site, array_merge($existing, $update));
            $this->logger->info(
                'Site settings updated from status response.',
                ['siteIdentifier' => $siteIdentifier, 'status' => $newStatus, 'trackingCode' => $trackingCode]
            );
        }

        return new RedirectResponse($indexUri);
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
                'domain' => rtrim((string)$site->getBase(), '/'),
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
