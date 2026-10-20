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
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Controller\BackendModuleController;
use T3G\Analytics\Backend\ModuleConfigurator;
use T3G\Analytics\Service\AnalyticsApiClient;
use T3G\Analytics\Service\AnalyticsStatusService;
use T3G\Analytics\Service\ApiExceptionExtractor;
use T3G\Analytics\Service\ApiKeyService;
use T3G\Analytics\Service\BackendPageAccessChecker;
use T3G\Analytics\Service\BackendPageAccessCheckerInterface;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\HmacSigner;
use T3G\Analytics\Service\InstanceRegistrationService;
use T3G\Analytics\Service\SiteDataProvider;
use T3G\Analytics\Service\SiteSettingsWriteVerifierInterface;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use T3G\Analytics\Tests\Fixtures\AnalyticsIds;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\NormalizedParams;
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
 * Multi-site access control tests for BackendModuleController.
 *
 * Verifies that a backend user who has access to site A but NOT to site B
 * cannot act on site B through any controller action.
 *
 * Scenario:
 *   - site-a: root page 1, registered with Analytics
 *   - site-b: root page 2, registered with Analytics
 *
 * The tests use two setups:
 *
 *   1. Mocked access check: the backend users are mocks and the
 *      BackendPageAccessCheckerInterface mock grants page 1 and denies page 2.
 *      These tests cover the controller logic for every action.
 *   2. Real access check: the backend users come from the CSV fixture and the
 *      real BackendPageAccessChecker evaluates their page mounts.
 *        - editor  (uid 1): non-admin, page mount on page 1 only
 *        - manager (uid 2): non-admin, tx_analytics:manager via group, page mount on page 1 only
 */
final class MultiSiteAccessTest extends FunctionalTestCase
{
    private const SITE_B_WEBSITE_ID = '5e8feceb-34b9-405d-9590-14935d93e977';
    private const SITE_B_INSTANCE_ID = '06c74211-043d-4ac1-8929-be4e214cc57e';

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        ],
    ];

    private MockHandler $mockHandler;
    /** @var array<int, array<string, mixed>> */
    private array $httpHistory = [];
    private AnalyticsStatusService $statusService;
    private SiteFinder $siteFinder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockHandler = new MockHandler();
        $this->httpHistory = [];
        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->httpHistory));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = $stack;

        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');

        $this->importCSVDataSet(__DIR__ . '/Fixtures/multi_site_access.csv');
        $this->siteFinder = $this->buildSiteFinder();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']);
        parent::tearDown();
    }

    #[Test]
    public function editorWithoutAccessToSiteBCannotOpenTheDashboardOfSiteB(): void
    {
        $GLOBALS['BE_USER'] = $this->buildEditorUser();

        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'dashboardUrl' => 'https://example.com/dashboard?token=test',
            ], JSON_THROW_ON_ERROR)),
        );

        $controller = $this->buildController();
        $request = $this->buildModuleRequest()
            ->withQueryParams(['siteIdentifier' => 'site-b']);

        $response = $controller->dashboardAction($request);

        self::assertSame(302, $response->getStatusCode(), 'Expected redirect for inaccessible site');
        self::assertCount(0, $this->httpHistory, 'No API request must be made for an inaccessible site');
        self::assertStringNotContainsString('site-b', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function analyticsManagerWithoutAccessToSiteBCannotOpenThePlanManagementOfSiteB(): void
    {
        $GLOBALS['BE_USER'] = $this->buildManagerUser();

        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'checkoutUrl' => 'https://example.com/checkout?token=test',
            ], JSON_THROW_ON_ERROR)),
        );

        $controller = $this->buildController();
        $request = $this->buildModuleRequest()
            ->withQueryParams(['siteIdentifier' => 'site-b']);

        $response = $controller->managePlanAction($request);

        self::assertSame(302, $response->getStatusCode(), 'Expected redirect for inaccessible site');
        self::assertCount(0, $this->httpHistory, 'No API request must be made for an inaccessible site');
    }

    #[Test]
    public function analyticsManagerWithoutAccessToSiteBCannotRefreshTheStatusOfSiteB(): void
    {
        $GLOBALS['BE_USER'] = $this->buildManagerUser();

        $this->mockHandler->append(
            new Response(200, [], '{"status":"active","consumption":{}}'),
        );

        $controller = $this->buildController();
        $request = $this->buildPostRequest(['siteIdentifier' => 'site-b']);

        $response = $controller->statusAction($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, $this->httpHistory, 'No API request must be made for an inaccessible site');
    }

    #[Test]
    public function analyticsManagerWithoutAccessToSiteBCannotRegisterSiteB(): void
    {
        $GLOBALS['BE_USER'] = $this->buildManagerUser();

        $this->mockHandler->append(
            new Response(200, [], json_encode(['instanceId' => AnalyticsIds::INSTANCE_ID, 'websiteId' => AnalyticsIds::WEBSITE_ID, 'instanceSecret' => 's3cr3t'], JSON_THROW_ON_ERROR)),
        );

        $controller = $this->buildController();
        $request = $this->buildPostRequest(['siteIdentifier' => 'site-b', 'email' => 'attacker@example.com']);

        $response = $controller->registerAction($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, $this->httpHistory, 'No registration request must be made for an inaccessible site');
    }

    #[Test]
    public function analyticsManagerWithoutAccessToSiteBCannotInvalidateTheStatusCacheOfSiteB(): void
    {
        $GLOBALS['BE_USER'] = $this->buildManagerUser();

        $this->mockHandler->append(
            new Response(200, [], '{"status":"active","consumption":{}}'),
        );

        $controller = $this->buildController();
        // Fill the status cache of site B. This uses the one queued API response.
        self::assertNotNull($this->statusService->getStatus($this->siteFinder->getSiteByIdentifier('site-b')));
        self::assertCount(1, $this->httpHistory);

        $request = (new ServerRequest(new Uri('https://example.com/'), 'POST'))->withQueryParams(['siteIdentifier' => 'site-b']);

        $response = $controller->invalidateStatusCacheAction($request);

        self::assertSame(404, $response->getStatusCode());
        // The cache entry of site B must still exist: no new API request is made.
        self::assertNotNull($this->statusService->getStatus($this->siteFinder->getSiteByIdentifier('site-b')));
        self::assertCount(1, $this->httpHistory, 'The status cache of an inaccessible site must not be cleared');
    }

    #[Test]
    public function editorCanAccessOwnSiteADashboard(): void
    {
        $GLOBALS['BE_USER'] = $this->buildEditorUser();

        $dashboardUrl = 'https://example.com/dashboard?token=test&externalWebsiteId=' . AnalyticsIds::EXTERNAL_WEBSITE_ID;
        $this->mockHandler->append(
            new Response(200, [], json_encode(['dashboardUrl' => $dashboardUrl], JSON_THROW_ON_ERROR)),
        );

        $controller = $this->buildController();
        $request = $this->buildModuleRequest()
            ->withQueryParams(['siteIdentifier' => 'site-a']);

        $response = $controller->dashboardAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        self::assertStringContainsString(htmlspecialchars($dashboardUrl, ENT_QUOTES), $body);
    }

    #[Test]
    public function editorWithPageMountOnSiteAOnlyCannotOpenTheDashboardOfSiteB(): void
    {
        $this->setUpBackendUser(1);

        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'dashboardUrl' => 'https://example.com/dashboard?token=test',
            ], JSON_THROW_ON_ERROR)),
        );

        $controller = $this->buildController(new BackendPageAccessChecker());
        $request = $this->buildModuleRequest()
            ->withQueryParams(['siteIdentifier' => 'site-b']);

        $response = $controller->dashboardAction($request);

        self::assertSame(302, $response->getStatusCode(), 'Expected redirect for inaccessible site');
        self::assertCount(0, $this->httpHistory, 'No API request must be made for an inaccessible site');
    }

    #[Test]
    public function editorWithPageMountOnSiteACanOpenTheDashboardOfSiteA(): void
    {
        $this->setUpBackendUser(1);

        $dashboardUrl = 'https://example.com/dashboard?token=test&externalWebsiteId=' . AnalyticsIds::EXTERNAL_WEBSITE_ID;
        $this->mockHandler->append(
            new Response(200, [], json_encode(['dashboardUrl' => $dashboardUrl], JSON_THROW_ON_ERROR)),
        );

        $controller = $this->buildController(new BackendPageAccessChecker());
        $request = $this->buildModuleRequest()
            ->withQueryParams(['siteIdentifier' => 'site-a']);

        $response = $controller->dashboardAction($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function analyticsManagerWithPageMountOnSiteAOnlyCannotRegisterSiteB(): void
    {
        $this->setUpBackendUser(2);

        $controller = $this->buildController(new BackendPageAccessChecker());
        $request = $this->buildPostRequest(['siteIdentifier' => 'site-b', 'email' => 'attacker@example.com']);

        $response = $controller->registerAction($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, $this->httpHistory, 'No registration request must be made for an inaccessible site');
    }

    #[Test]
    public function analyticsManagerWithPageMountOnSiteAOnlyCannotRefreshTheStatusOfSiteB(): void
    {
        $this->setUpBackendUser(2);

        $controller = $this->buildController(new BackendPageAccessChecker());
        $request = $this->buildPostRequest(['siteIdentifier' => 'site-b']);

        $response = $controller->statusAction($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, $this->httpHistory, 'No API request must be made for an inaccessible site');
    }

    #[Test]
    public function analyticsManagerWithPageMountOnSiteACanRefreshTheStatusOfSiteA(): void
    {
        $this->setUpBackendUser(2);

        $this->mockHandler->append(
            new Response(200, [], '{"status":"active","consumption":{}}'),
        );

        $controller = $this->buildController(new BackendPageAccessChecker());
        $request = $this->buildPostRequest(['siteIdentifier' => 'site-a']);

        $response = $controller->statusAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->httpHistory);
    }

    #[Test]
    public function analyticsManagerWithPageMountOnSiteAOnlyCannotOpenThePlanManagementOfSiteB(): void
    {
        $this->setUpBackendUser(2);

        // A valid API response: without the access check, the action would use it.
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'checkoutUrl' => 'https://example.com/checkout?token=test',
            ], JSON_THROW_ON_ERROR)),
        );

        $controller = $this->buildController(new BackendPageAccessChecker());
        $request = $this->buildModuleRequest()
            ->withQueryParams(['siteIdentifier' => 'site-b']);

        $response = $controller->managePlanAction($request);

        self::assertSame(302, $response->getStatusCode(), 'Expected redirect for inaccessible site');
        self::assertCount(0, $this->httpHistory, 'No API request must be made for an inaccessible site');
    }

    #[Test]
    public function analyticsManagerWithPageMountOnSiteAOnlyCannotInvalidateTheStatusCacheOfSiteB(): void
    {
        $this->setUpBackendUser(2);

        $this->mockHandler->append(
            new Response(200, [], '{"status":"active","consumption":{}}'),
        );

        $controller = $this->buildController(new BackendPageAccessChecker());
        // Fill the status cache of site B. This uses the one queued API response.
        self::assertNotNull($this->statusService->getStatus($this->siteFinder->getSiteByIdentifier('site-b')));
        self::assertCount(1, $this->httpHistory);

        $request = (new ServerRequest(new Uri('https://example.com/'), 'POST'))->withQueryParams(['siteIdentifier' => 'site-b']);

        $response = $controller->invalidateStatusCacheAction($request);

        self::assertSame(404, $response->getStatusCode());
        // The cache entry of site B must still exist: no new API request is made.
        self::assertNotNull($this->statusService->getStatus($this->siteFinder->getSiteByIdentifier('site-b')));
        self::assertCount(1, $this->httpHistory, 'The status cache of an inaccessible site must not be cleared');
    }


    private function buildController(?BackendPageAccessCheckerInterface $pageAccessChecker = null): BackendModuleController
    {
        if ($pageAccessChecker === null) {
            $pageAccessChecker = $this->createMock(BackendPageAccessCheckerInterface::class);
            $pageAccessChecker->method('userCanAccessPage')->willReturnCallback(
                static fn (int $pageId): bool => $pageId === 1,
            );
        }

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['verifySsl'] = '0';
        $apiClient = new AnalyticsApiClient(
            new RequestFactory(new GuzzleClientFactory()),
            new ApiConfiguration(),
            new HmacSigner(),
            new ApiExceptionExtractor(),
        );

        $cipherService = new CipherService();
        $statusService = new AnalyticsStatusService(
            $this->get(CacheManager::class)->getCache('tx_analytics_status'),
            $apiClient,
            $cipherService,
            new NullLogger(),
            $this->createMock(SiteSettingsService::class),
            $this->createMock(SiteSettingsFactory::class),
            $this->createMock(SiteSettingsWriteVerifierInterface::class),
        );
        $this->statusService = $statusService;

        $siteDataProvider = new SiteDataProvider(
            $this->siteFinder,
            $statusService,
            $pageAccessChecker,
            $this->get(ConnectionPool::class),
            $this->get(UriBuilder::class),
            new NullLogger(),
        );

        $moduleHelper = new ModuleConfigurator(
            $this->get(UriBuilder::class),
            $siteDataProvider,
        );

        $registrationService = new InstanceRegistrationService(
            $apiClient,
            $cipherService,
            $this->createMock(SiteSettingsService::class),
            $this->createMock(SiteSettingsFactory::class),
            $this->createMock(SiteSettingsWriteVerifierInterface::class),
            new NullLogger(),
        );

        $apiKeyService = new ApiKeyService(
            $apiClient,
            $cipherService,
            $this->createMock(SiteSettingsService::class),
            $this->createMock(SiteSettingsFactory::class),
            $this->createMock(SiteSettingsWriteVerifierInterface::class),
            new NullLogger(),
        );

        return new BackendModuleController(
            $this->get(ModuleTemplateFactory::class),
            $this->get(UriBuilder::class),
            $this->get(FlashMessageService::class),
            $this->siteFinder,
            $registrationService,
            $statusService,
            $apiKeyService,
            $moduleHelper,
            $siteDataProvider,
            $this->createMock(LoggerInterface::class),
            $pageAccessChecker,
        );
    }

    private function buildSiteFinder(): SiteFinder
    {
        $siteA = $this->buildRegisteredSite('site-a', 1, AnalyticsIds::WEBSITE_ID, AnalyticsIds::INSTANCE_ID);
        $siteB = $this->buildRegisteredSite('site-b', 2, self::SITE_B_WEBSITE_ID, self::SITE_B_INSTANCE_ID);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willReturnCallback(
            static fn (string $id): Site => match ($id) {
                'site-a' => $siteA,
                'site-b' => $siteB,
                default => throw new SiteNotFoundException('not found', 1),
            },
        );
        return $siteFinder;
    }

    private function buildRegisteredSite(string $identifier, int $rootPageId, string $websiteId, string $instanceId): Site
    {
        $encryptedSecret = (new CipherService())->encrypt('test-instance-secret');
        $siteSettings = new SiteSettings(
            new Settings([]),
            ['websiteId' => $websiteId, 'instanceId' => $instanceId, 'instanceSecret' => $encryptedSecret],
            [],
        );

        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getBase')->willReturn(new Uri('https://' . $identifier . '.example.com'));
        $site->method('getConfiguration')->willReturn(['websiteTitle' => 'Site ' . strtoupper(substr($identifier, -1))]);
        $site->method('getRootPageId')->willReturn($rootPageId);
        $site->method('getSettings')->willReturn($siteSettings);
        return $site;
    }

    private function buildModuleRequest(): ServerRequest
    {
        $request = (new ServerRequest(new Uri('https://example.com/'), 'GET'))
            ->withAttribute('route', new Route('', ['packageName' => 't3g/analytics']))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    /**
     * @param array<string, string> $body
     */
    private function buildPostRequest(array $body): ServerRequest
    {
        return (new ServerRequest(new Uri('https://example.com/'), 'POST'))->withParsedBody($body);
    }

    private function buildEditorUser(): BackendUserAuthentication
    {
        $user = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->getMock();
        $user->method('getModuleData')->willReturn([]);
        $user->method('isAdmin')->willReturn(false);
        $user->method('check')->willReturn(false);
        return $user;
    }

    private function buildManagerUser(): BackendUserAuthentication
    {
        $user = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->getMock();
        $user->method('getModuleData')->willReturn([]);
        $user->method('isAdmin')->willReturn(false);
        $user->method('check')
            ->with('custom_options', 'tx_analytics:manager')
            ->willReturn(true);
        return $user;
    }
}
