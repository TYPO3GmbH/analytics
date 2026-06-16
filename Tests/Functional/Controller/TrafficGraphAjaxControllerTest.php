<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Controller\TrafficGraphAjaxController;
use T3G\Analytics\Service\TrafficChartDataBuilder;
use T3G\Analytics\Service\TrafficGraphServiceInterface;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use T3G\Analytics\View\SparklineRenderer;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Functional tests for TrafficGraphAjaxController.
 *
 * Unit tests cover TrafficGraphService::loadGraphData logic. These tests cover
 * what unit tests cannot: DI container wiring (public: true), Fluid template
 * rendering via ViewFactoryInterface, and backend route URL generation.
 */
final class TrafficGraphAjaxControllerTest extends FunctionalTestCase
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

        $backendUser = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->getMock();
        $backendUser->method('getModuleData')->willReturn([]);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    #[Test]
    public function controllerIsRegisteredAsPublicService(): void
    {
        $controller = $this->get(TrafficGraphAjaxController::class);

        self::assertInstanceOf(TrafficGraphAjaxController::class, $controller);
    }

    #[Test]
    public function handleReturnsOkJsonWithEmptyShowAllUrlWhenNoSiteGiven(): void
    {
        $service = $this->createMock(TrafficGraphServiceInterface::class);
        $service->method('loadGraphData')->willReturn(null);

        $controller = new TrafficGraphAjaxController(
            $service,
            new TrafficChartDataBuilder(new SparklineRenderer()),
            $this->get(UriBuilder::class),
            $this->get(ViewFactoryInterface::class),
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
    public function handleReturnsShowAllUrlContainingSiteIdentifier(): void
    {
        $service = $this->createMock(TrafficGraphServiceInterface::class);
        $service->method('loadGraphData')->willReturn(null);

        $controller = new TrafficGraphAjaxController(
            $service,
            new TrafficChartDataBuilder(new SparklineRenderer()),
            $this->get(UriBuilder::class),
            $this->get(ViewFactoryInterface::class),
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
    public function handleRendersYLabelsBasedOnCalcScaleMax(): void
    {
        $service = $this->createMock(TrafficGraphServiceInterface::class);
        // max=6 → calcScaleMax=9 (step=3) → y-labels top-to-bottom: 9, 6, 3, 0
        $service->method('loadGraphData')->willReturn([
            'labels' => ['2024-01-01'],
            'data' => [6],
        ]);

        $controller = new TrafficGraphAjaxController(
            $service,
            new TrafficChartDataBuilder(new SparklineRenderer()),
            $this->get(UriBuilder::class),
            $this->get(ViewFactoryInterface::class),
        );

        $request = (new ServerRequest(new Uri('https://example.com/ajax'), 'GET'))
            ->withQueryParams(['site' => '', 'days' => '30'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $response = $controller->handle($request);
        $body = json_decode((string)$response->getBody(), true);
        $html = $body['html'];

        self::assertStringContainsString('tx-analytics-traffic-graph-y-label">9<', $html);
        self::assertStringContainsString('tx-analytics-traffic-graph-y-label">6<', $html);
        self::assertStringContainsString('tx-analytics-traffic-graph-y-label">3<', $html);
        self::assertStringContainsString('tx-analytics-traffic-graph-y-label">0<', $html);
    }
}
