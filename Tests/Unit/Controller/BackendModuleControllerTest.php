<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Controller\BackendModuleController;
use T3G\Analytics\Helper\BackendModuleHelper;
use T3G\Analytics\Service\SiteDataProvider;
use T3G\Analytics\Service\AnalyticsStatusService;
use T3G\Analytics\Service\InstanceRegistrationService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for BackendModuleController.
 *
 * ModuleTemplateFactory and ModuleTemplate are declared final in TYPO3 and
 * cannot be mocked with PHPUnit. Actions that successfully render a template
 * (indexAction / dashboardAction happy path) are therefore covered by
 * functional tests instead.
 */
final class BackendModuleControllerTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    /** @var UriBuilder&MockObject */
    private UriBuilder $uriBuilder;
    /** @var FlashMessageService&MockObject */
    private FlashMessageService $flashMessageService;
    /** @var FlashMessageQueue&MockObject */
    private FlashMessageQueue $flashMessageQueue;
    /** @var SiteFinder&MockObject */
    private SiteFinder $siteFinder;
    /** @var InstanceRegistrationService&MockObject */
    private InstanceRegistrationService $registrationService;
    /** @var AnalyticsStatusService&MockObject */
    private AnalyticsStatusService $analyticsStatusService;
    /** @var BackendModuleHelper&MockObject */
    private BackendModuleHelper $moduleHelper;
    /** @var SiteDataProvider&MockObject */
    private SiteDataProvider $siteDataProvider;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private BackendModuleController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        /**
         * Pre-populate the LocalizationUtility runtime cache with a stub LanguageService.
         * This avoids needing LanguageServiceFactory (and its DI dependencies) at all:
         * buildLanguageService() hits the cache immediately and never calls makeInstance().
         */
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);

        $locale = new Locale('en');
        $locales = $this->createMock(Locales::class);
        $locales->method('createLocale')->willReturn($locale);
        $locales->method('createLocaleFromRequest')->willReturn($locale);
        GeneralUtility::setSingletonInstance(Locales::class, $locales);

        $runtimeCache = new VariableFrontend('runtime', new TransientMemoryBackend('production'));
        $languageFilePath = 'EXT:analytics/Resources/Private/Language/locallang.xlf';
        $cacheKey = sha1(json_encode(array_merge([(string)$locale], $locale->getDependencies(), [$languageFilePath])));
        $runtimeCache->set($cacheKey, $languageService);

        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($runtimeCache);
        GeneralUtility::setSingletonInstance(CacheManager::class, $cacheManager);

        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->flashMessageService = $this->createMock(FlashMessageService::class);
        $this->flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $this->siteFinder = $this->createMock(SiteFinder::class);
        $this->registrationService = $this->createMock(InstanceRegistrationService::class);
        $this->analyticsStatusService = $this->createMock(AnalyticsStatusService::class);
        $this->moduleHelper = $this->createMock(BackendModuleHelper::class);
        $this->siteDataProvider = $this->createMock(SiteDataProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->flashMessageService
            ->method('getMessageQueueByIdentifier')
            ->willReturn($this->flashMessageQueue);

        /**
         * ModuleTemplateFactory is final – instantiate without constructor for
         * actions that accept it but never call it in the tested code paths.
         */
        $moduleTemplateFactory = (new \ReflectionClass(ModuleTemplateFactory::class))
            ->newInstanceWithoutConstructor();

        $this->subject = new BackendModuleController(
            $moduleTemplateFactory,
            $this->uriBuilder,
            $this->flashMessageService,
            $this->siteFinder,
            $this->registrationService,
            $this->analyticsStatusService,
            $this->moduleHelper,
            $this->siteDataProvider,
            $this->logger,
        );
    }

    /** registerAction */

    #[Test]
    public function registerActionRedirectsWithErrorWhenBodyIsMissingSiteIdentifier(): void
    {
        $indexUri = '/module/site/analytics';
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri($indexUri));

        $this->flashMessageQueue->expects(self::once())->method('addMessage');
        $this->siteFinder->expects(self::never())->method('getSiteByIdentifier');

        $response = $this->subject->registerAction($this->buildRequest(['siteIdentifier' => '', 'email' => 'user@example.com']));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString($indexUri, $response->getHeaderLine('location'));
    }

    #[Test]
    public function registerActionRedirectsWithErrorWhenBodyIsMissingEmail(): void
    {
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));

        $this->flashMessageQueue->expects(self::once())->method('addMessage');
        $this->siteFinder->expects(self::never())->method('getSiteByIdentifier');

        $response = $this->subject->registerAction($this->buildRequest(['siteIdentifier' => 'main', 'email' => '']));

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function registerActionRedirectsWithErrorWhenSiteNotFound(): void
    {
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willThrowException(new SiteNotFoundException('not found', 1));

        $this->flashMessageQueue->expects(self::once())->method('addMessage');
        $this->registrationService->expects(self::never())->method('register');

        $response = $this->subject->registerAction(
            $this->buildRequest(['siteIdentifier' => 'unknown', 'email' => 'user@example.com'])
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function registerActionRedirectsWithErrorWhenRegistrationServiceThrows(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);
        $this->registrationService->method('register')->willThrowException(new \RuntimeException('connection refused'));

        $this->flashMessageQueue->expects(self::once())->method('addMessage');

        $response = $this->subject->registerAction(
            $this->buildRequest(['siteIdentifier' => 'main', 'email' => 'user@example.com'])
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function registerActionRedirectsToIndexAfterSuccessfulRegistration(): void
    {
        $indexUri = '/module/site/analytics';
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri($indexUri));
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);
        $this->flashMessageQueue->expects(self::once())->method('addMessage');

        $response = $this->subject->registerAction(
            $this->buildRequest(['siteIdentifier' => 'main', 'email' => 'user@example.com'])
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString($indexUri, $response->getHeaderLine('location'));
    }

    /** statusAction */

    #[Test]
    public function statusActionRedirectsWithErrorWhenSiteIdentifierMissing(): void
    {
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));

        $this->flashMessageQueue->expects(self::once())->method('addMessage');
        $this->analyticsStatusService->expects(self::never())->method('getStatus');

        $response = $this->subject->statusAction($this->buildRequest(['siteIdentifier' => '']));

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function statusActionCallsGetStatusWithForceRefresh(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);

        $this->analyticsStatusService
            ->expects(self::once())
            ->method('getStatus')
            ->with($site, true)
            ->willReturn(['status' => 'active']);

        $this->flashMessageQueue->expects(self::never())->method('addMessage');

        $response = $this->subject->statusAction($this->buildRequest(['siteIdentifier' => 'main']));

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function statusActionAddsErrorFlashMessageWhenStatusIsNull(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);
        $this->analyticsStatusService->method('getStatus')->willReturn(null);

        $this->flashMessageQueue->expects(self::once())->method('addMessage');

        $response = $this->subject->statusAction($this->buildRequest(['siteIdentifier' => 'main']));

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    /** invalidateStatusCacheAction */

    #[Test]
    public function invalidateStatusCacheActionReturnsBadRequestWhenSiteIdentifierMissing(): void
    {
        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $this->analyticsStatusService->expects(self::never())->method('clearCache');

        $response = $this->subject->invalidateStatusCacheAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function invalidateStatusCacheActionReturnsNotFoundWhenSiteNotFound(): void
    {
        $this->siteFinder->method('getSiteByIdentifier')
            ->willThrowException(new SiteNotFoundException('not found', 1));

        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['siteIdentifier' => 'unknown']);

        $this->analyticsStatusService->expects(self::never())->method('clearCache');

        $response = $this->subject->invalidateStatusCacheAction($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function invalidateStatusCacheActionClearsCacheAndReturnsSuccess(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);

        $this->analyticsStatusService
            ->expects(self::once())
            ->method('clearCache')
            ->with($site);

        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['siteIdentifier' => 'main']);

        $response = $this->subject->invalidateStatusCacheAction($request);

        self::assertSame(200, $response->getStatusCode());
    }

    /** managePlanAction – error paths */

    #[Test]
    public function managePlanActionRedirectsWithErrorWhenSiteIdentifierMissing(): void
    {
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));

        $this->flashMessageQueue->expects(self::once())->method('addMessage');
        $this->analyticsStatusService->expects(self::never())->method('getManagePlanUrl');

        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->subject->managePlanAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function managePlanActionRedirectsWithErrorWhenSiteNotFound(): void
    {
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willThrowException(new SiteNotFoundException('not found', 1));

        $this->flashMessageQueue->expects(self::once())->method('addMessage');
        $this->analyticsStatusService->expects(self::never())->method('getManagePlanUrl');

        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['siteIdentifier' => 'unknown']);

        $response = $this->subject->managePlanAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function managePlanActionRedirectsWithErrorWhenManagePlanUrlIsNull(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);
        $this->analyticsStatusService->method('getManagePlanUrl')->willReturn(null);

        $this->flashMessageQueue->expects(self::once())->method('addMessage');

        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['siteIdentifier' => 'main']);

        $response = $this->subject->managePlanAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    /** dashboardAction – error paths */

    #[Test]
    public function dashboardActionRedirectsWithErrorWhenSiteIdentifierMissing(): void
    {
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));

        $this->flashMessageQueue->expects(self::once())->method('addMessage');
        $this->analyticsStatusService->expects(self::never())->method('getDashboardUrl');

        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->subject->dashboardAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function dashboardActionRedirectsWithErrorWhenSiteNotFound(): void
    {
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willThrowException(new SiteNotFoundException('not found', 1));

        $this->flashMessageQueue->expects(self::once())->method('addMessage');

        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['siteIdentifier' => 'unknown']);

        $response = $this->subject->dashboardAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function dashboardActionRedirectsWithErrorWhenDashboardUrlIsNull(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);
        $this->analyticsStatusService->method('getDashboardUrl')->willReturn(null);

        $this->flashMessageQueue->expects(self::once())->method('addMessage');

        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['siteIdentifier' => 'main']);

        $response = $this->subject->dashboardAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    /** Helpers */

    private function buildRequest(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);
        return $request;
    }

    private function buildSiteMock(string $identifier, string $baseUrl, array $siteSettings = []): Site&MockObject
    {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getBase')->willReturn(new Uri($baseUrl));
        $site->method('getConfiguration')->willReturn(['websiteTitle' => ucfirst($identifier)]);
        $site->method('getRootPageId')->willReturn(1);
        $site->method('getSettings')->willReturn(new SiteSettings(new Settings($siteSettings), [], []));
        return $site;
    }
}
