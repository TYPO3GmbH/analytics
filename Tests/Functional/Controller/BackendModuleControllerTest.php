<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Controller;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use T3G\Analytics\Controller\BackendModuleController;
use T3G\Analytics\Helper\BackendModuleHelper;
use T3G\Analytics\Service\AnalyticsStatusService;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\InstanceRegistrationService;
use T3G\Analytics\Service\SiteDataProvider;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;

/**
 * Functional tests for BackendModuleController.
 *
 * These tests boot a real TYPO3 instance and verify actions that produce
 * rendered HTML responses (the rendering paths that unit tests cannot reach
 * due to ModuleTemplate being final).
 *
 * HTTP calls are intercepted via the Guzzle MockHandler injected through
 * TYPO3_CONF_VARS['HTTP']['handler']. SiteSettingsService and SiteSettingsFactory
 * are mocked because they perform file I/O.
 */
final class BackendModuleControllerTest extends FunctionalTestCase
{
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        ],
    ];

    private MockHandler $mockHandler;
    private array $httpHistory = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockHandler = new MockHandler();
        $this->httpHistory = [];
        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->httpHistory));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = $stack;

        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');

        $backendUser = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->getMock();
        $backendUser->method('getModuleData')->willReturn([]);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']);
        parent::tearDown();
    }

    /** indexAction */

    #[Test]
    public function indexActionRendersHtmlResponseWithNoSites(): void
    {
        $controller = $this->buildController();
        $request = $this->buildModuleRequest('GET', '/module/site/analytics');

        $response = $controller->indexAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }

    /** dashboardAction – happy path */

    #[Test]
    public function dashboardActionRendersResponseContainingDashboardUrl(): void
    {
        $dashboardUrl = 'https://app-3as.visitor-analytics.io?intpc_token=jwt&externalWebsiteId=w-123';
        $this->mockHandler->append(new Response(200, [], json_encode(['dashboardUrl' => $dashboardUrl])));

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willReturn($this->buildRegisteredSiteMock('main', 'w-123', 'i-456'));

        $controller = $this->buildController(siteFinder: $siteFinder);

        $request = $this->buildModuleRequest('GET', '/module/site/analytics/dashboard')
            ->withQueryParams(['siteIdentifier' => 'main']);

        $response = $controller->dashboardAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        self::assertStringContainsString(htmlspecialchars($dashboardUrl, ENT_QUOTES), $body);
        self::assertStringContainsString('SUBSCRIPTION_UPGRADED', $body);
    }

    /** managePlanAction */

    #[Test]
    public function managePlanActionRendersResponseContainingManagePlanUrl(): void
    {
        $managePlanUrl = 'https://checkout.visitor-analytics.io/plan?token=abc123';
        $this->mockHandler->append(new Response(200, [], json_encode(['checkoutUrl' => $managePlanUrl])));

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willReturn($this->buildRegisteredSiteMock('main', 'w-123', 'i-456'));

        $controller = $this->buildController(siteFinder: $siteFinder);

        $request = $this->buildModuleRequest('GET', '/module/site/analytics/manage-plan')
            ->withQueryParams(['siteIdentifier' => 'main']);

        $response = $controller->managePlanAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        self::assertStringContainsString(htmlspecialchars($managePlanUrl, ENT_QUOTES), $body);
    }

    #[Test]
    public function managePlanActionRedirectsWhenSiteIdentifierMissing(): void
    {
        $controller = $this->buildController();
        $request = $this->buildModuleRequest('GET', '/module/site/analytics/manage-plan')
            ->withQueryParams([]);

        $response = $controller->managePlanAction($request);

        self::assertSame(302, $response->getStatusCode());
    }

    #[Test]
    public function managePlanActionRedirectsWhenManagePlanUrlUnavailable(): void
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willReturn($this->buildSiteMock('main'));

        $controller = $this->buildController(siteFinder: $siteFinder);

        $request = $this->buildModuleRequest('GET', '/module/site/analytics/manage-plan')
            ->withQueryParams(['siteIdentifier' => 'main']);

        $response = $controller->managePlanAction($request);

        self::assertSame(302, $response->getStatusCode());
    }

    /** invalidateStatusCacheAction */

    #[Test]
    public function invalidateStatusCacheActionReturnsJsonSuccess(): void
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willReturn($this->buildSiteMock('main'));

        $controller = $this->buildController(siteFinder: $siteFinder);

        $request = (new ServerRequest(
            new Uri('https://example.com/module/site/analytics/invalidate-status-cache'),
            'POST',
        ))->withQueryParams(['siteIdentifier' => 'main']);

        $response = $controller->invalidateStatusCacheAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string)$response->getBody(), true);
        self::assertTrue($data['success']);
    }

    /** statusAction */

    #[Test]
    public function statusActionReturnsSuccessJsonAfterSuccessfulRefresh(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"status":"active","consumption":{}}'));

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willReturn($this->buildRegisteredSiteMock('main', 'w-123', 'i-456'));

        $controller = $this->buildController(siteFinder: $siteFinder);

        $request = (new ServerRequest(new Uri('https://example.com/module/site/analytics'), 'POST'))
            ->withParsedBody(['siteIdentifier' => 'main']);

        $response = $controller->statusAction($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        self::assertTrue($data['success']);
    }

    /** Helpers */

    private function buildController(
        ?SiteFinder $siteFinder = null,
    ): BackendModuleController {
        $resolvedSiteFinder = $siteFinder ?? $this->get(SiteFinder::class);
        $requestFactory = new RequestFactory(new GuzzleClientFactory());

        $cipherService = new CipherService();
        $statusService = new AnalyticsStatusService(
            $this->get(CacheManager::class)->getCache('tx_analytics_status'),
            $requestFactory,
            $cipherService,
            new NullLogger(),
            $this->createMock(SiteSettingsService::class),
            $this->createMock(SiteSettingsFactory::class),
        );

        $siteDataProvider = new SiteDataProvider(
            $resolvedSiteFinder,
            $statusService,
            $this->get(ConnectionPool::class),
            $this->get(UriBuilder::class),
            new NullLogger(),
        );

        $moduleHelper = new BackendModuleHelper(
            $this->get(UriBuilder::class),
            $siteDataProvider,
        );

        $registrationService = new InstanceRegistrationService(
            $requestFactory,
            $cipherService,
            $this->createMock(SiteSettingsService::class),
            $this->createMock(SiteSettingsFactory::class),
            new NullLogger(),
        );

        return new BackendModuleController(
            $this->get(ModuleTemplateFactory::class),
            $this->get(UriBuilder::class),
            $this->get(FlashMessageService::class),
            $resolvedSiteFinder,
            $registrationService,
            $statusService,
            $moduleHelper,
            $siteDataProvider,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function buildModuleRequest(string $method, string $path): ServerRequest
    {
        $route = new Route($path, ['packageName' => 't3g/analytics']);

        return (new ServerRequest(new Uri('https://example.com' . $path), $method))
            ->withAttribute('route', $route)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    private function buildRegisteredSiteMock(string $identifier, string $websiteId, string $instanceId): Site
    {
        $encryptedSecret = (new CipherService())->encrypt('test-instance-secret');

        $siteSettings = new SiteSettings(
            new Settings([]),
            ['websiteId' => $websiteId, 'instanceId' => $instanceId, 'instanceSecret' => $encryptedSecret],
            []
        );

        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getBase')->willReturn(new Uri('https://example.com'));
        $site->method('getConfiguration')->willReturn(['websiteTitle' => 'Example']);
        $site->method('getRootPageId')->willReturn(1);
        $site->method('getSettings')->willReturn($siteSettings);
        return $site;
    }

    private function buildSiteMock(string $identifier): Site
    {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getBase')->willReturn(new Uri('https://example.com'));
        $site->method('getConfiguration')->willReturn(['websiteTitle' => 'Example']);
        $site->method('getRootPageId')->willReturn(1);
        $site->method('getSettings')->willReturn(new SiteSettings(new Settings([]), [], []));
        return $site;
    }
}
