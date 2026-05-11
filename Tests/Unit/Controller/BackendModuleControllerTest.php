<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Controller;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use T3G\Analytics\Controller\BackendModuleController;
use T3G\Analytics\Helper\BackendModuleHelper;
use T3G\Analytics\Service\AnalyticsStatusService;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\InstanceRegistrationService;
use T3G\Analytics\Service\SiteDataProvider;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\RequestFactory;
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
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for BackendModuleController.
 *
 * HTTP calls are intercepted via the Guzzle MockHandler injected through
 * TYPO3_CONF_VARS['HTTP']['handler']. All other internal services run as real
 * instances. SiteSettingsService and SiteSettingsFactory are mocked because
 * they perform file I/O.
 *
 * ModuleTemplateFactory is final in TYPO3 and cannot be mocked with PHPUnit.
 * Actions that successfully render a template (indexAction / dashboardAction
 * happy path) are therefore covered by functional tests instead.
 */
final class BackendModuleControllerTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private MockHandler $mockHandler;
    private array $httpHistory = [];
    private SiteSettingsService&MockObject $siteSettingsService;
    private SiteSettingsFactory&MockObject $siteSettingsFactory;
    private UriBuilder&MockObject $uriBuilder;
    private FlashMessageService&MockObject $flashMessageService;
    private FlashMessageQueue&MockObject $flashMessageQueue;
    private SiteFinder&MockObject $siteFinder;
    private LoggerInterface&MockObject $logger;

    private string $encryptedTestSecret;

    private BackendModuleController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);

        $this->mockHandler = new MockHandler();
        $this->httpHistory = [];
        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->httpHistory));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = ['verify' => false, 'handler' => $stack];

        /**
         * Pre-populate the LocalizationUtility runtime cache with a stub LanguageService.
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

        $this->siteSettingsService = $this->createMock(SiteSettingsService::class);
        $this->siteSettingsFactory = $this->createMock(SiteSettingsFactory::class);
        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->flashMessageService = $this->createMock(FlashMessageService::class);
        $this->flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $this->siteFinder = $this->createMock(SiteFinder::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->flashMessageService
            ->method('getMessageQueueByIdentifier')
            ->willReturn($this->flashMessageQueue);

        $cipherService = new CipherService();
        $this->encryptedTestSecret = $cipherService->encrypt('test-instance-secret');

        $requestFactory = new RequestFactory(new GuzzleClientFactory());
        $statusCache = new VariableFrontend('analytics_status', new TransientMemoryBackend('production'));

        $analyticsStatusService = new AnalyticsStatusService(
            $statusCache,
            $requestFactory,
            $cipherService,
            new NullLogger(),
            $this->siteSettingsService,
            $this->siteSettingsFactory,
        );

        $registrationService = new InstanceRegistrationService(
            $requestFactory,
            $cipherService,
            $this->siteSettingsService,
            $this->siteSettingsFactory,
            new NullLogger(),
        );

        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $siteDataProvider = new SiteDataProvider(
            $this->siteFinder,
            $analyticsStatusService,
            $connectionPool,
            $this->uriBuilder,
            new NullLogger(),
        );

        $moduleHelper = new BackendModuleHelper(
            $this->uriBuilder,
            $siteDataProvider,
        );

        $moduleTemplateFactory = (new \ReflectionClass(ModuleTemplateFactory::class))
            ->newInstanceWithoutConstructor();

        $this->subject = new BackendModuleController(
            $moduleTemplateFactory,
            $this->uriBuilder,
            $this->flashMessageService,
            $this->siteFinder,
            $registrationService,
            $analyticsStatusService,
            $moduleHelper,
            $siteDataProvider,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']);
        parent::tearDown();
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
        $this->mockHandler->append(new \RuntimeException('connection refused'));

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
        $this->mockHandler->append(new Response(200, [], '{"instanceId":"i-456","websiteId":"w-123","instanceSecret":"s3cr3t"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);
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

        $response = $this->subject->statusAction($this->buildRequest(['siteIdentifier' => '']));

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function statusActionRedirectsWithoutErrorWhenStatusFetchSucceeds(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com', [
            'websiteId' => 'w-123',
            'instanceId' => 'i-456',
            'instanceSecret' => $this->encryptedTestSecret,
        ]);
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);
        $this->mockHandler->append(new Response(200, [], '{"status":"active","consumption":{}}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);

        $this->flashMessageQueue->expects(self::never())->method('addMessage');

        $response = $this->subject->statusAction($this->buildRequest(['siteIdentifier' => 'main']));

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function statusActionAddsErrorFlashMessageWhenSiteHasNoCredentials(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);

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

        $response = $this->subject->invalidateStatusCacheAction($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function invalidateStatusCacheActionReturnsSuccessResponse(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);

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

        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['siteIdentifier' => 'unknown']);

        $response = $this->subject->managePlanAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    #[Test]
    public function managePlanActionRedirectsWithErrorWhenSiteHasNoCredentials(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);

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
    public function dashboardActionRedirectsWithErrorWhenSiteHasNoCredentials(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/site/analytics'));
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);

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

    private function buildSiteMock(string $identifier, string $baseUrl, array $siteSettings = []): Site
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
