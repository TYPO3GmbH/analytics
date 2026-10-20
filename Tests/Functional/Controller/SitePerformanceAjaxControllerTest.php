<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Controller\SitePerformanceAjaxController;
use T3G\Analytics\Service\BackendWidgetAccessCheckerInterface;
use T3G\Analytics\Service\SitePerformanceServiceInterface;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use T3G\Analytics\Tests\Functional\Fixtures\WidgetAccessUsers;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Functional tests for SitePerformanceAjaxController.
 *
 * Unit tests cover SitePerformanceService::buildMetricItems logic. These tests cover
 * what unit tests cannot: DI container wiring (public: true), Fluid template
 * rendering via ViewFactoryInterface, and backend route URL generation.
 */
final class SitePerformanceAjaxControllerTest extends FunctionalTestCase
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
        $controller = $this->get(SitePerformanceAjaxController::class);

        self::assertInstanceOf(SitePerformanceAjaxController::class, $controller);
    }

    #[Test]
    public function handleReturnsOkJsonWithEmptyShowAllUrlWhenNoSiteGiven(): void
    {
        $service = $this->createMock(SitePerformanceServiceInterface::class);
        $service->method('loadPerformanceData')->willReturn(null);

        $controller = new SitePerformanceAjaxController(
            $service,
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
        $service = $this->createMock(SitePerformanceServiceInterface::class);
        $service->expects(self::never())->method('loadPerformanceData');

        $controller = new SitePerformanceAjaxController(
            $service,
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
        $service = $this->createMock(SitePerformanceServiceInterface::class);
        $service->method('loadPerformanceData')->willReturn(null);

        $controller = new SitePerformanceAjaxController(
            $service,
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
}
