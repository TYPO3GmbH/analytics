<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Controller\BackendModuleController;
use T3G\Analytics\Service\AnalyticsStatusService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Imaging\IconFactory;
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
 */
final class BackendModuleControllerTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Both LANG and BE_USER are normally initialised by the backend bootstrap
        // when a backend user logs in. Provide minimal stubs here.
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');

        $backendUser = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->getMock();
        $backendUser->method('getModuleData')->willReturn([]);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        ],
    ];

    // -------------------------------------------------------------------------
    // indexAction
    // -------------------------------------------------------------------------

    #[Test]
    public function indexActionRendersHtmlResponseWithNoSites(): void
    {
        $controller = $this->buildController();
        $request = $this->buildModuleRequest('GET', '/module/site/analytics');

        $response = $controller->indexAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }

    // -------------------------------------------------------------------------
    // dashboardAction – happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function dashboardActionRendersResponseContainingDashboardUrl(): void
    {
        $dashboardUrl = 'https://app-3as.visitor-analytics.io?intpc_token=jwt&externalWebsiteId=w-123';

        /** @var AnalyticsStatusService&MockObject $analyticsStatusService */
        $analyticsStatusService = $this->createMock(AnalyticsStatusService::class);
        $analyticsStatusService
            ->method('getDashboardUrl')
            ->willReturn($dashboardUrl);

        /** @var SiteFinder&MockObject $siteFinder */
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder
            ->method('getSiteByIdentifier')
            ->willReturn($this->buildSiteMock('main'));

        $controller = $this->buildController(
            analyticsStatusService: $analyticsStatusService,
            siteFinder: $siteFinder,
        );

        $request = $this->buildModuleRequest('GET', '/module/site/analytics/dashboard')
            ->withQueryParams(['siteIdentifier' => 'main']);

        $response = $controller->dashboardAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        // Fluid HTML-encodes & in attribute values, so check for the HTML-encoded form
        self::assertStringContainsString(htmlspecialchars($dashboardUrl, ENT_QUOTES), $body);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds the controller with real TYPO3 services from the DI container,
     * optionally replacing individual services with test doubles.
     */
    private function buildController(
        ?AnalyticsStatusService $analyticsStatusService = null,
        ?SiteFinder $siteFinder = null,
    ): BackendModuleController {
        return new BackendModuleController(
            $this->get(ModuleTemplateFactory::class),
            $this->get(UriBuilder::class),
            $this->get(IconFactory::class),
            $this->get(FlashMessageService::class),
            $siteFinder ?? $this->get(SiteFinder::class),
            $this->get(SiteSettingsService::class),
            $this->get(SiteSettingsFactory::class),
            $this->createMock(RequestFactory::class), // never calls real HTTP in these tests
            $this->get(CipherService::class),
            $analyticsStatusService ?? $this->createMock(AnalyticsStatusService::class),
            $this->get(ConnectionPool::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * Creates a ServerRequest with the backend route attribute that
     * ModuleTemplateFactory / BackendViewFactory require for template lookup.
     */
    private function buildModuleRequest(string $method, string $path): ServerRequest
    {
        $route = new Route($path, ['packageName' => 't3g/analytics']);

        return (new ServerRequest(new Uri('https://example.com' . $path), $method))
            ->withAttribute('route', $route);
    }

    private function buildSiteMock(string $identifier): Site
    {
        $siteSettings = new SiteSettings(new Settings([]), [], []);

        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getBase')->willReturn(new Uri('https://example.com'));
        $site->method('getConfiguration')->willReturn(['websiteTitle' => 'Example']);
        $site->method('getRootPageId')->willReturn(1);
        $site->method('getSettings')->willReturn($siteSettings);
        return $site;
    }
}
