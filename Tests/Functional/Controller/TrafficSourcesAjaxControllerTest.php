<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Controller\TrafficSourcesAjaxController;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\BackendWidgetAccessCheckerInterface;
use T3G\Analytics\Service\MetricFormatterInterface;
use T3G\Analytics\Service\TrafficSourcesServiceInterface;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use T3G\Analytics\Tests\Functional\Fixtures\WidgetAccessUsers;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Functional tests for TrafficSourcesAjaxController.
 *
 * Unit tests cover TrafficSourcesService item-building logic. These tests cover
 * what unit tests cannot: DI container wiring (public: true), Fluid template
 * rendering via ViewFactoryInterface, and backend route URL generation.
 */
final class TrafficSourcesAjaxControllerTest extends FunctionalTestCase
{
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $this->importCSVDataSet(WidgetAccessUsers::CSV);
        $this->setUpBackendUser(WidgetAccessUsers::V13_WIDGETS);
    }

    #[Test]
    public function controllerIsRegisteredAsPublicService(): void
    {
        $controller = $this->get(TrafficSourcesAjaxController::class);

        self::assertInstanceOf(TrafficSourcesAjaxController::class, $controller);
    }

    #[Test]
    public function handleReturnsOkJsonWithEmptyShowAllUrlWhenNoSiteGiven(): void
    {
        $this->skipOnTypo3V14();
        $service = $this->createMock(TrafficSourcesServiceInterface::class);
        $siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);
        $siteProvider->method('siteOptions')->willReturn([]);

        $controller = new TrafficSourcesAjaxController(
            $service,
            $siteProvider,
            $this->get(MetricFormatterInterface::class),
            $this->get(UriBuilder::class),
            $this->get(ViewFactoryInterface::class),
            $this->get(BackendWidgetAccessCheckerInterface::class),
        );

        $request = (new ServerRequest(new Uri('https://example.com/ajax'), 'GET'))
            ->withQueryParams(['site' => '', 'days' => '7'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $response = $controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertSame('ok', $body['status']);
        self::assertIsString($body['html']);
        self::assertSame('', $body['showAllUrl']);
    }

    #[Test]
    public function handleReturns403WhenUserCannotAccessWidget(): void
    {
        // The user may use another widget, so the controller must check its own widget.
        $this->setUpBackendUser(WidgetAccessUsers::TOP_PAGES_ONLY);
        $service = $this->createMock(TrafficSourcesServiceInterface::class);
        $service->expects(self::never())->method('loadDeviceData');

        $controller = new TrafficSourcesAjaxController(
            $service,
            $this->createMock(AnalyticsSiteProviderInterface::class),
            $this->get(MetricFormatterInterface::class),
            $this->get(UriBuilder::class),
            $this->get(ViewFactoryInterface::class),
            $this->get(BackendWidgetAccessCheckerInterface::class),
        );

        $response = $controller->handle(new ServerRequest(new Uri('https://example.com/ajax'), 'GET'));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function handleReturns403WhenUserCanAccessOnlyASectionWidget(): void
    {
        // Protects only on TYPO3 14, where the section variants exist: each variant shows only one section,
        // so a permission for one variant must not open the route that returns all sections.
        $this->setUpBackendUser(WidgetAccessUsers::CHANNEL_ONLY);
        $service = $this->createMock(TrafficSourcesServiceInterface::class);
        $service->expects(self::never())->method('loadDeviceData');

        $controller = new TrafficSourcesAjaxController(
            $service,
            $this->createMock(AnalyticsSiteProviderInterface::class),
            $this->get(MetricFormatterInterface::class),
            $this->get(UriBuilder::class),
            $this->get(ViewFactoryInterface::class),
            $this->get(BackendWidgetAccessCheckerInterface::class),
        );

        $response = $controller->handle(new ServerRequest(new Uri('https://example.com/ajax'), 'GET'));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function handleReturns403OnTypo3V14WithTypo3V13WidgetPermission(): void
    {
        $this->skipOnTypo3V13();
        // TYPO3 14 replaces the widget with four section variants. A permission from TYPO3 13 then grants no access.
        $this->setUpBackendUser(WidgetAccessUsers::V13_WIDGETS);
        $service = $this->createMock(TrafficSourcesServiceInterface::class);
        $service->expects(self::never())->method('loadDeviceData');

        $controller = new TrafficSourcesAjaxController(
            $service,
            $this->createMock(AnalyticsSiteProviderInterface::class),
            $this->get(MetricFormatterInterface::class),
            $this->get(UriBuilder::class),
            $this->get(ViewFactoryInterface::class),
            $this->get(BackendWidgetAccessCheckerInterface::class),
        );

        $response = $controller->handle(new ServerRequest(new Uri('https://example.com/ajax'), 'GET'));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function handleReturnsShowAllUrlContainingSiteIdentifier(): void
    {
        $this->skipOnTypo3V14();
        $service = $this->createMock(TrafficSourcesServiceInterface::class);
        $siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);
        $siteProvider->method('siteOptions')->willReturn([]);

        $controller = new TrafficSourcesAjaxController(
            $service,
            $siteProvider,
            $this->get(MetricFormatterInterface::class),
            $this->get(UriBuilder::class),
            $this->get(ViewFactoryInterface::class),
            $this->get(BackendWidgetAccessCheckerInterface::class),
        );

        $request = (new ServerRequest(new Uri('https://example.com/ajax'), 'GET'))
            ->withQueryParams(['site' => 'my-site', 'days' => '30'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $response = $controller->handle($request);
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame('ok', $body['status']);
        self::assertNotEmpty($body['showAllUrl']);
        self::assertStringContainsString('my-site', $body['showAllUrl']);
    }

    #[Test]
    public function handleShowAllUrlContainsTrafficShareDashboardPath(): void
    {
        $this->skipOnTypo3V14();
        $service = $this->createMock(TrafficSourcesServiceInterface::class);
        $siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);
        $siteProvider->method('siteOptions')->willReturn([]);

        $controller = new TrafficSourcesAjaxController(
            $service,
            $siteProvider,
            $this->get(MetricFormatterInterface::class),
            $this->get(UriBuilder::class),
            $this->get(ViewFactoryInterface::class),
            $this->get(BackendWidgetAccessCheckerInterface::class),
        );

        $request = (new ServerRequest(new Uri('https://example.com/ajax'), 'GET'))
            ->withQueryParams(['site' => 'my-site', 'days' => '7'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $response = $controller->handle($request);
        $body = json_decode((string)$response->getBody(), true);

        self::assertStringContainsString('traffic', $body['showAllUrl']);
    }

    #[Test]
    public function handleFallsBackToFirstSiteWhenSiteParamIsEmpty(): void
    {
        $this->skipOnTypo3V14();
        $service = $this->createMock(TrafficSourcesServiceInterface::class);
        $siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);
        $siteProvider->method('siteOptions')->willReturn(['fallback-site' => 'Fallback Site']);

        $controller = new TrafficSourcesAjaxController(
            $service,
            $siteProvider,
            $this->get(MetricFormatterInterface::class),
            $this->get(UriBuilder::class),
            $this->get(ViewFactoryInterface::class),
            $this->get(BackendWidgetAccessCheckerInterface::class),
        );

        $request = (new ServerRequest(new Uri('https://example.com/ajax'), 'GET'))
            ->withQueryParams(['site' => '', 'days' => '7'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $response = $controller->handle($request);
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame('ok', $body['status']);
        self::assertNotEmpty($body['showAllUrl']);
        self::assertStringContainsString('fallback-site', $body['showAllUrl']);
    }

    private function skipOnTypo3V13(): void
    {
        if ((new Typo3Version())->getMajorVersion() < 14) {
            self::markTestSkipped('TYPO3 13 registers the traffic sources widget.');
        }
    }

    private function skipOnTypo3V14(): void
    {
        if ((new Typo3Version())->getMajorVersion() >= 14) {
            self::markTestSkipped('TYPO3 14 does not use this route. The section variants render on the server.');
        }
    }
}
