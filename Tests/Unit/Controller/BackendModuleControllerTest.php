<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Controller\BackendModuleController;
use T3G\Analytics\Service\AnalyticsStatusService;
use T3G\Analytics\Service\CipherService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;
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
    /** @var RequestFactory&MockObject */
    private RequestFactory $requestFactory;
    /** @var FlashMessageService&MockObject */
    private FlashMessageService $flashMessageService;
    /** @var FlashMessageQueue&MockObject */
    private FlashMessageQueue $flashMessageQueue;
    /** @var SiteFinder&MockObject */
    private SiteFinder $siteFinder;
    /** @var SiteSettingsService&MockObject */
    private SiteSettingsService $siteSettingsService;
    /** @var SiteSettingsFactory&MockObject */
    private SiteSettingsFactory $siteSettingsFactory;
    /** @var CipherService&MockObject */
    private CipherService $cipherService;
    /** @var AnalyticsStatusService&MockObject */
    private AnalyticsStatusService $analyticsStatusService;
    /** @var ConnectionPool&MockObject */
    private ConnectionPool $connectionPool;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private BackendModuleController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        // Pre-populate the LocalizationUtility runtime cache with a stub LanguageService.
        // This avoids needing LanguageServiceFactory (and its DI dependencies) at all:
        // buildLanguageService() hits the cache immediately and never calls makeInstance().
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
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->flashMessageService = $this->createMock(FlashMessageService::class);
        $this->flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $this->siteFinder = $this->createMock(SiteFinder::class);
        $this->siteSettingsService = $this->createMock(SiteSettingsService::class);
        $this->siteSettingsFactory = $this->createMock(SiteSettingsFactory::class);
        $this->cipherService = $this->createMock(CipherService::class);
        $this->analyticsStatusService = $this->createMock(AnalyticsStatusService::class);
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->flashMessageService
            ->method('getMessageQueueByIdentifier')
            ->willReturn($this->flashMessageQueue);

        // ModuleTemplateFactory is final – instantiate without constructor for
        // actions that accept it but never call it in the tested code paths.
        $moduleTemplateFactory = (new \ReflectionClass(ModuleTemplateFactory::class))
            ->newInstanceWithoutConstructor();

        $this->subject = new BackendModuleController(
            $moduleTemplateFactory,
            $this->uriBuilder,
            $this->createMock(IconFactory::class),
            $this->flashMessageService,
            $this->siteFinder,
            $this->siteSettingsService,
            $this->siteSettingsFactory,
            $this->requestFactory,
            $this->cipherService,
            $this->analyticsStatusService,
            $this->connectionPool,
            $this->logger,
        );
    }

    // -------------------------------------------------------------------------
    // registerAction
    // -------------------------------------------------------------------------

    #[Test]
    public function registerActionRedirectsWithErrorWhenBodyIsMissingSiteIdentifier(): void
    {
        $indexUri = '/module/site/analytics';
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri($indexUri));

        $request = $this->buildRequest(['siteIdentifier' => '', 'email' => 'user@example.com']);

        $this->flashMessageQueue->expects(self::once())->method('addMessage');
        $this->siteFinder->expects(self::never())->method('getSiteByIdentifier');

        $response = $this->subject->registerAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString($indexUri, $response->getHeaderLine('location'));
    }

    #[Test]
    public function registerActionRedirectsWithErrorWhenBodyIsMissingEmail(): void
    {
        $indexUri = '/module/site/analytics';
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri($indexUri));

        $request = $this->buildRequest(['siteIdentifier' => 'main', 'email' => '']);

        $this->flashMessageQueue->expects(self::once())->method('addMessage');
        $this->siteFinder->expects(self::never())->method('getSiteByIdentifier');

        $response = $this->subject->registerAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function registerActionRedirectsWithErrorWhenSiteNotFound(): void
    {
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));

        $this->siteFinder
            ->method('getSiteByIdentifier')
            ->willThrowException(new SiteNotFoundException('not found', 1));

        $this->flashMessageQueue->expects(self::once())->method('addMessage');
        $this->requestFactory->expects(self::never())->method('request');

        $response = $this->subject->registerAction(
            $this->buildRequest(['siteIdentifier' => 'unknown', 'email' => 'user@example.com'])
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function registerActionRedirectsWithErrorWhenApiCallFails(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);
        $this->requestFactory->method('request')->willThrowException(new \RuntimeException('connection refused'));

        $this->flashMessageQueue->expects(self::once())->method('addMessage');
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $response = $this->subject->registerAction(
            $this->buildRequest(['siteIdentifier' => 'main', 'email' => 'user@example.com'])
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function registerActionRedirectsWithErrorWhenApiResponseMissesWebsiteId(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);
        $this->requestFactory->method('request')
            ->willReturn($this->buildApiResponse('{"instanceId":"xyz"}'));

        $this->flashMessageQueue->expects(self::once())->method('addMessage');
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $response = $this->subject->registerAction(
            $this->buildRequest(['siteIdentifier' => 'main', 'email' => 'user@example.com'])
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function registerActionWritesSettingsAndRedirectsToIndexWhenNoCheckoutUrl(): void
    {
        $indexUri = '/module/site/analytics';
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri($indexUri));
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);
        $this->requestFactory->method('request')
            ->willReturn($this->buildApiResponse(
                '{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"s3cr3t"}'
            ));
        $this->cipherService->method('encrypt')->willReturn('enc-secret');
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);
        $this->siteSettingsService->expects(self::once())->method('writeSettings');
        $this->flashMessageQueue->expects(self::once())->method('addMessage');

        $response = $this->subject->registerAction(
            $this->buildRequest(['siteIdentifier' => 'main', 'email' => 'user@example.com'])
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString($indexUri, $response->getHeaderLine('location'));
    }

    #[Test]
    public function registerActionRedirectsToCheckoutRouteWhenCheckoutUrlPresent(): void
    {
        $checkoutUrl = 'https://checkout.visitor-analytics.io/plan?token=abc123';
        $checkoutRouteUri = '/module/site/analytics/checkout?checkoutUrl=' . urlencode($checkoutUrl);

        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->uriBuilder->method('buildUriFromRoute')
            ->willReturnCallback(static function (string $route, array $params = []) use ($checkoutRouteUri): Uri {
                if ($route === 'site_analytics.checkout') {
                    return new Uri($checkoutRouteUri);
                }
                return new Uri('/module/site/analytics');
            });
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);
        $this->requestFactory->method('request')
            ->willReturn($this->buildApiResponse(
                '{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"s3cr3t","checkoutUrl":"' . $checkoutUrl . '"}'
            ));
        $this->cipherService->method('encrypt')->willReturn('enc-secret');
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);
        $this->siteSettingsService->expects(self::once())->method('writeSettings');
        $this->flashMessageQueue->expects(self::once())->method('addMessage');

        $response = $this->subject->registerAction(
            $this->buildRequest(['siteIdentifier' => 'main', 'email' => 'user@example.com'])
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame($checkoutRouteUri, $response->getHeaderLine('location'));
    }

    #[Test]
    public function registerActionWritesAllSettingsIncludingExistingKeys(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('/auth/register/instance'),
                'POST',
                self::callback(static function (array $options): bool {
                    return ($options['json']['domain'] ?? '') === 'https://example.com'
                        && ($options['json']['email'] ?? '') === 'user@example.com';
                })
            )
            ->willReturn($this->buildApiResponse(
                '{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"s3cr3t"}'
            ));

        $this->cipherService->method('encrypt')->with('s3cr3t')->willReturn('enc-secret');
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn(['existingKey' => 'val']);

        $this->siteSettingsService
            ->expects(self::once())
            ->method('writeSettings')
            ->with(
                $site,
                self::callback(static function (array $settings): bool {
                    return $settings['websiteId'] === 'w-123'
                        && $settings['instanceId'] === 'i-456'
                        && $settings['instanceSecret'] === 'enc-secret'
                        && $settings['existingKey'] === 'val';
                })
            );

        $this->flashMessageQueue->expects(self::once())->method('addMessage');

        $this->subject->registerAction(
            $this->buildRequest(['siteIdentifier' => 'main', 'email' => 'user@example.com'])
        );
    }

    // -------------------------------------------------------------------------
    // statusAction
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // dashboardAction – error paths
    // -------------------------------------------------------------------------

    #[Test]
    public function dashboardActionRedirectsWithErrorWhenSiteIdentifierMissing(): void
    {
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));

        $this->flashMessageQueue->expects(self::once())->method('addMessage');
        $this->analyticsStatusService->expects(self::never())->method('getDashboardUrl');

        $request = $this->createMock(ServerRequestInterface::class);
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

        $request = $this->createMock(ServerRequestInterface::class);
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

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['siteIdentifier' => 'main']);

        $response = $this->subject->dashboardAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildRequest(array $body): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);
        return $request;
    }

    private function buildSiteMock(string $identifier, string $baseUrl): Site&MockObject
    {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getBase')->willReturn(new Uri($baseUrl));
        $site->method('getConfiguration')->willReturn(['websiteTitle' => ucfirst($identifier)]);
        $site->method('getRootPageId')->willReturn(1);
        return $site;
    }

    private function buildApiResponse(string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        return $response;
    }
}
