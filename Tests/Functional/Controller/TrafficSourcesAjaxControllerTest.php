<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Controller\TrafficSourcesAjaxController;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\MetricFormatterInterface;
use T3G\Analytics\Service\TrafficSourcesServiceInterface;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
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

        $backendUser = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->getMock();
        $backendUser->method('getModuleData')->willReturn([]);
        $GLOBALS['BE_USER'] = $backendUser;
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
        $service = $this->createMock(TrafficSourcesServiceInterface::class);
        $siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);
        $siteProvider->method('siteOptions')->willReturn([]);

        $controller = new TrafficSourcesAjaxController(
            $service,
            $siteProvider,
            $this->get(MetricFormatterInterface::class),
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
        $service = $this->createMock(TrafficSourcesServiceInterface::class);
        $siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);
        $siteProvider->method('siteOptions')->willReturn([]);

        $controller = new TrafficSourcesAjaxController(
            $service,
            $siteProvider,
            $this->get(MetricFormatterInterface::class),
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
    public function handleShowAllUrlContainsTrafficShareDashboardPath(): void
    {
        $service = $this->createMock(TrafficSourcesServiceInterface::class);
        $siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);
        $siteProvider->method('siteOptions')->willReturn([]);

        $controller = new TrafficSourcesAjaxController(
            $service,
            $siteProvider,
            $this->get(MetricFormatterInterface::class),
            $this->get(UriBuilder::class),
            $this->get(ViewFactoryInterface::class),
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
        $service = $this->createMock(TrafficSourcesServiceInterface::class);
        $siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);
        $siteProvider->method('siteOptions')->willReturn(['fallback-site' => 'Fallback Site']);

        $controller = new TrafficSourcesAjaxController(
            $service,
            $siteProvider,
            $this->get(MetricFormatterInterface::class),
            $this->get(UriBuilder::class),
            $this->get(ViewFactoryInterface::class),
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
}
