<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Controller;

use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Controller\BackendModuleController;
use T3G\Analytics\Backend\ModuleConfigurator;
use T3G\Analytics\Service\AnalyticsApiClient;
use T3G\Analytics\Service\AnalyticsStatusService;
use T3G\Analytics\Service\ApiExceptionExtractor;
use T3G\Analytics\Service\ApiKeyService;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\HmacSigner;
use T3G\Analytics\Service\InstanceRegistrationService;
use T3G\Analytics\Service\SiteDataProvider;
use T3G\Analytics\Service\BackendPageAccessCheckerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;
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

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);
        $GLOBALS['LANG'] = $languageService;

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

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnMap([
            ['analytics', 'apiBaseUrl', ''],
            ['analytics', 'verifySsl', '0'],
        ]);
        $apiClient = new AnalyticsApiClient(
            new RequestFactory(new GuzzleClientFactory()),
            new ApiConfiguration($extensionConfiguration),
            new HmacSigner(),
            new ApiExceptionExtractor(),
        );

        $statusCache = new VariableFrontend('analytics_status', new TransientMemoryBackend('production'));
        $analyticsStatusService = new AnalyticsStatusService(
            $statusCache,
            $apiClient,
            $cipherService,
            new NullLogger(),
            $this->siteSettingsService,
            $this->siteSettingsFactory,
        );

        $registrationService = new InstanceRegistrationService(
            $apiClient,
            $cipherService,
            $this->siteSettingsService,
            $this->siteSettingsFactory,
            new NullLogger(),
        );

        $apiKeyService = new ApiKeyService(
            $apiClient,
            $cipherService,
            $this->siteSettingsService,
            $this->siteSettingsFactory,
            new NullLogger(),
        );

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('uid = :dcValue1');

        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $siteDataProvider = new SiteDataProvider(
            $this->siteFinder,
            $analyticsStatusService,
            $this->createMock(BackendPageAccessCheckerInterface::class),
            $connectionPool,
            $this->uriBuilder,
            new NullLogger(),
        );

        $moduleHelper = new ModuleConfigurator(
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
            $apiKeyService,
            $moduleHelper,
            $siteDataProvider,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']);
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    /** registerAction */

    #[Test]
    public function registerActionReturnsErrorJsonWhenBodyIsMissingSiteIdentifier(): void
    {
        $this->siteFinder->expects(self::never())->method('getSiteByIdentifier');

        $response = $this->subject->registerAction($this->buildRequest(['siteIdentifier' => '', 'email' => 'user@example.com']));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($this->decodeJson($response)['success']);
    }

    #[Test]
    public function registerActionReturnsErrorJsonWhenBodyIsMissingEmail(): void
    {
        $this->siteFinder->expects(self::never())->method('getSiteByIdentifier');

        $response = $this->subject->registerAction($this->buildRequest(['siteIdentifier' => 'main', 'email' => '']));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($this->decodeJson($response)['success']);
    }

    #[Test]
    public function registerActionReturnsErrorJsonWhenSiteNotFound(): void
    {
        $this->siteFinder->method('getSiteByIdentifier')->willThrowException(new SiteNotFoundException('not found', 1));

        $response = $this->subject->registerAction(
            $this->buildRequest(['siteIdentifier' => 'unknown', 'email' => 'user@example.com'])
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($this->decodeJson($response)['success']);
    }

    #[Test]
    public function registerActionReturnsErrorJsonWhenApiCallFails(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);
        $this->mockHandler->append(new \RuntimeException('connection refused'));

        $response = $this->subject->registerAction(
            $this->buildRequest(['siteIdentifier' => 'main', 'email' => 'user@example.com'])
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($this->decodeJson($response)['success']);
    }

    #[Test]
    public function registerActionReturnsSuccessJsonAfterSuccessfulRegistration(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);
        $this->mockHandler->append(new Response(200, [], '{"instanceId":"i-456","websiteId":"w-123","instanceSecret":"s3cr3t"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);

        $response = $this->subject->registerAction(
            $this->buildRequest(['siteIdentifier' => 'main', 'email' => 'user@example.com'])
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->decodeJson($response)['success']);
    }

    /** statusAction */

    #[Test]
    public function statusActionReturnsErrorJsonWhenSiteIdentifierMissing(): void
    {
        $response = $this->subject->statusAction($this->buildRequest(['siteIdentifier' => '']));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($this->decodeJson($response)['success']);
    }

    #[Test]
    public function statusActionReturnsSuccessJsonWhenStatusFetchSucceeds(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com', [
            'websiteId' => 'w-123',
            'instanceId' => 'i-456',
            'instanceSecret' => $this->encryptedTestSecret,
        ]);
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);
        $this->mockHandler->append(new Response(200, [], '{"status":"active","consumption":{}}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);

        $response = $this->subject->statusAction($this->buildRequest(['siteIdentifier' => 'main']));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->decodeJson($response)['success']);
    }

    #[Test]
    public function statusActionReturnsErrorJsonWhenSiteHasNoCredentials(): void
    {
        $site = $this->buildSiteMock('main', 'https://example.com');
        $this->siteFinder->method('getSiteByIdentifier')->willReturn($site);

        $response = $this->subject->statusAction($this->buildRequest(['siteIdentifier' => 'main']));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($this->decodeJson($response)['success']);
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

    /** @return array<string, mixed> */
    private function decodeJson(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
