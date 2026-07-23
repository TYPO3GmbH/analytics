<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Service\AnalyticsApiClient;
use T3G\Analytics\Service\AnalyticsStatusService;
use T3G\Analytics\Service\ApiExceptionExtractor;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\HmacSigner;
use T3G\Analytics\Service\BackendPageAccessCheckerInterface;
use T3G\Analytics\Service\SiteDataProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SiteDataProviderTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private MockHandler $mockHandler;
    private array $httpHistory = [];
    private SiteFinder&MockObject $siteFinder;
    private UriBuilder&MockObject $uriBuilder;
    private \Doctrine\DBAL\Result&MockObject $queryResult;
    private BackendPageAccessCheckerInterface&MockObject $pageAccessChecker;
    private bool $userCanAccessPage = true;

    private string $encryptedTestSecret;
    private SiteDataProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);

        $this->mockHandler = new MockHandler();
        $this->httpHistory = [];
        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->httpHistory));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = ['verify' => false, 'handler' => $stack];

        $this->siteFinder = $this->createMock(SiteFinder::class);
        $this->uriBuilder = $this->createMock(UriBuilder::class);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('uid = :dcValue1');

        $this->queryResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($this->queryResult);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $cipherService = new CipherService();
        $this->encryptedTestSecret = $cipherService->encrypt('test-secret');

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['verifySsl'] = '0';
        $apiClient = new AnalyticsApiClient(
            new RequestFactory(new GuzzleClientFactory()),
            new ApiConfiguration(),
            new HmacSigner(),
            new ApiExceptionExtractor(),
        );
        $statusService = new AnalyticsStatusService(
            new VariableFrontend('analytics_status', new TransientMemoryBackend('production')),
            $apiClient,
            $cipherService,
            new NullLogger(),
            $this->createMock(SiteSettingsService::class),
            $this->createMock(SiteSettingsFactory::class),
        );

        $this->pageAccessChecker = $this->createMock(BackendPageAccessCheckerInterface::class);
        $this->pageAccessChecker->method('userCanAccessPage')
            ->willReturnCallback(fn () => $this->userCanAccessPage);

        $this->subject = new SiteDataProvider(
            $this->siteFinder,
            $statusService,
            $this->pageAccessChecker,
            $connectionPool,
            $this->uriBuilder,
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']);
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /** getRootPageTitle */

    #[Test]
    public function getRootPageTitleReturnsTitleFromDatabaseRow(): void
    {
        $this->queryResult->method('fetchAssociative')->willReturn(['title' => 'Home']);

        self::assertSame('Home', $this->subject->getRootPageTitle(1));
    }

    #[Test]
    public function getRootPageTitleReturnsEmptyStringWhenPageNotFound(): void
    {
        $this->queryResult->method('fetchAssociative')->willReturn(false);

        self::assertSame('', $this->subject->getRootPageTitle(99));
    }

    /** fetchSites */

    #[Test]
    public function fetchSitesReturnsEmptyArrayWhenNoSitesExist(): void
    {
        $this->siteFinder->method('getAllSites')->willReturn([]);

        self::assertSame([], $this->subject->fetchSites());
    }

    #[Test]
    public function fetchSitesMarksUnregisteredSiteCorrectly(): void
    {
        $this->siteFinder->method('getAllSites')->willReturn([
            $this->buildSiteMock('main', 'https://example.com', []),
        ]);
        $this->queryResult->method('fetchAssociative')->willReturn(false);

        $result = $this->subject->fetchSites();

        self::assertCount(1, $result);
        self::assertFalse($result[0]['registered']);
        self::assertNull($result[0]['websiteId']);
        self::assertNull($result[0]['status']);
        self::assertSame('', $result[0]['dashboardUri']);
        self::assertSame('', $result[0]['managePlanUri']);
    }

    #[Test]
    public function fetchSitesMarksRegisteredSiteCorrectly(): void
    {
        $this->siteFinder->method('getAllSites')->willReturn([
            $this->buildSiteMock('main', 'https://example.com', [
                'websiteId' => 'w-123',
                'instanceId' => 'i-456',
                'instanceSecret' => $this->encryptedTestSecret,
            ]),
        ]);
        $this->mockHandler->append(new Response(200, [], '{"status":"active","consumption":{}}'));
        $this->queryResult->method('fetchAssociative')->willReturn(['title' => 'Home']);
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/module/analytics'));

        $result = $this->subject->fetchSites();

        self::assertCount(1, $result);
        self::assertTrue($result[0]['registered']);
        self::assertSame('w-123', $result[0]['websiteId']);
        self::assertIsArray($result[0]['status']);
        self::assertNotEmpty($result[0]['dashboardUri']);
        self::assertNotEmpty($result[0]['managePlanUri']);
    }

    #[Test]
    public function fetchSitesPopulatesPageNameFromDatabase(): void
    {
        $this->siteFinder->method('getAllSites')->willReturn([
            $this->buildSiteMock('main', 'https://example.com', []),
        ]);
        $this->queryResult->method('fetchAssociative')->willReturn(['title' => 'Start']);

        $result = $this->subject->fetchSites();

        self::assertSame('Start', $result[0]['pageName']);
    }

    #[Test]
    public function fetchSitesUsesWebsiteTitleFromConfiguration(): void
    {
        $this->siteFinder->method('getAllSites')->willReturn([
            $this->buildSiteMock('main', 'https://example.com', [], 'My Site'),
        ]);
        $this->queryResult->method('fetchAssociative')->willReturn(false);

        $result = $this->subject->fetchSites();

        self::assertSame('My Site', $result[0]['title']);
    }

    #[Test]
    public function fetchSitesExcludesSiteWhenBackendUserHasNoPageAccess(): void
    {
        $this->userCanAccessPage = false;

        $this->siteFinder->method('getAllSites')->willReturn([
            $this->buildSiteMock('main', 'https://example.com', []),
        ]);

        self::assertSame([], $this->subject->fetchSites());
    }

    /** registeredSiteOptions */

    #[Test]
    public function registeredSiteOptionsReturnsEmptyArrayWhenNoSitesRegistered(): void
    {
        $this->siteFinder->method('getAllSites')->willReturn([
            $this->buildSiteMock('main', 'https://example.com', []),
        ]);

        self::assertSame([], $this->subject->registeredSiteOptions());
    }

    #[Test]
    public function registeredSiteOptionsFiltersOutUnregisteredSites(): void
    {
        $this->siteFinder->method('getAllSites')->willReturn([
            $this->buildSiteMock('registered', 'https://example.com', ['websiteId' => 'w-123']),
            $this->buildSiteMock('unregistered', 'https://other.com', []),
        ]);
        $this->queryResult->method('fetchAssociative')->willReturn(false);

        $result = $this->subject->registeredSiteOptions();

        self::assertCount(1, $result);
        self::assertSame('registered', $result[0]['identifier']);
    }

    #[Test]
    public function registeredSiteOptionsBuildsLabelFromPageTitle(): void
    {
        $this->siteFinder->method('getAllSites')->willReturn([
            $this->buildSiteMock('main', 'https://example.com', ['websiteId' => 'w-123']),
        ]);
        $this->queryResult->method('fetchAssociative')->willReturn(['title' => 'Home']);

        $result = $this->subject->registeredSiteOptions();

        self::assertCount(1, $result);
        self::assertSame('Home (main)', $result[0]['label']);
    }

    #[Test]
    public function registeredSiteOptionsUsesIdentifierAsLabelWhenPageTitleIsEmpty(): void
    {
        $this->siteFinder->method('getAllSites')->willReturn([
            $this->buildSiteMock('main', 'https://example.com', ['websiteId' => 'w-123']),
        ]);
        $this->queryResult->method('fetchAssociative')->willReturn(false);

        $result = $this->subject->registeredSiteOptions();

        self::assertSame('main', $result[0]['label']);
    }

    #[Test]
    public function registeredSiteOptionsExcludesSiteWhenBackendUserHasNoPageAccess(): void
    {
        $this->userCanAccessPage = false;

        $this->siteFinder->method('getAllSites')->willReturn([
            $this->buildSiteMock('main', 'https://example.com', ['websiteId' => 'w-123']),
        ]);

        self::assertSame([], $this->subject->registeredSiteOptions());
    }

    /** siteLabel */

    #[Test]
    public function siteLabelReturnsSiteIdentifierWhenPageNameIsEmpty(): void
    {
        self::assertSame('main', $this->subject->siteLabel('', 'main'));
    }

    #[Test]
    public function siteLabelReturnsSiteIdentifierWhenPageNameIsWhitespaceOnly(): void
    {
        self::assertSame('main', $this->subject->siteLabel('   ', 'main'));
    }

    #[Test]
    public function siteLabelReturnsCombinedLabelWhenPageNameIsSet(): void
    {
        self::assertSame('Home (main)', $this->subject->siteLabel('Home', 'main'));
    }

    /** resolvePanelClass */

    #[Test]
    public function resolvePanelClassReturnsPanelDefaultWhenStatusIsNull(): void
    {
        self::assertSame('panel-default', $this->subject->resolvePanelClass(null));
    }

    #[Test]
    public function resolvePanelClassReturnsPanelDefaultWhenStatusHasNoConsumption(): void
    {
        self::assertSame('panel-default', $this->subject->resolvePanelClass(['trackingId' => 'abc']));
    }

    #[Test]
    public function resolvePanelClassReturnsPanelDangerWhenLimitedAndExhausted(): void
    {
        $status = ['consumption' => ['limited' => true, 'exhausted' => true, 'warning' => false]];
        self::assertSame('panel-danger', $this->subject->resolvePanelClass($status));
    }

    #[Test]
    public function resolvePanelClassReturnsPanelWarningWhenWarningIsSet(): void
    {
        $status = ['consumption' => ['limited' => false, 'exhausted' => false, 'warning' => true]];
        self::assertSame('panel-warning', $this->subject->resolvePanelClass($status));
    }

    #[Test]
    public function resolvePanelClassReturnsPanelDefaultWhenConsumptionIsHealthy(): void
    {
        $status = ['consumption' => ['limited' => false, 'exhausted' => false, 'warning' => false]];
        self::assertSame('panel-default', $this->subject->resolvePanelClass($status));
    }

    #[Test]
    public function resolvePanelClassReturnsPanelDefaultWhenOnlyLimitedWithoutExhausted(): void
    {
        $status = ['consumption' => ['limited' => true, 'exhausted' => false, 'warning' => false]];
        self::assertSame('panel-default', $this->subject->resolvePanelClass($status));
    }

    /** Helpers */

    private function buildSiteMock(
        string $identifier,
        string $baseUrl,
        array $settings,
        string $websiteTitle = 'Example'
    ): Site {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getBase')->willReturn(new Uri($baseUrl));
        $site->method('getConfiguration')->willReturn(['websiteTitle' => $websiteTitle]);
        $site->method('getRootPageId')->willReturn(1);
        $site->method('getSettings')->willReturn(new SiteSettings(new Settings($settings), [], []));
        return $site;
    }
}
