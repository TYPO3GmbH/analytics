<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Backend\Middleware\BackendModuleValidator;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;

final class AjaxRoutesTest extends FunctionalTestCase
{
    public static function ajaxRouteDataProvider(): \Generator
    {
        yield 'top pages' => ['ajax_analytics_top_pages_content', 'dashboard'];
        yield 'site performance' => ['ajax_analytics_site_performance_content', 'dashboard'];
        yield 'traffic graph' => ['ajax_analytics_traffic_graph_content', 'dashboard'];
        yield 'traffic sources' => ['ajax_analytics_traffic_sources_content', 'dashboard'];
        yield 'page performance' => ['ajax_analytics_page_performance_content', 'web_layout'];
        yield 'plans' => ['ajax_analytics_plans_content', 'site_analytics'];
    }

    #[Test]
    #[DataProvider('ajaxRouteDataProvider')]
    public function ajaxRouteInheritsAccessFromModule(string $routeIdentifier, string $moduleIdentifier): void
    {
        $route = $this->get(Router::class)->getRoute($routeIdentifier);

        self::assertNotNull($route);
        self::assertSame($moduleIdentifier, $route->getOption('inheritAccessFromModule'));
        self::assertTrue($this->get(ModuleProvider::class)->isModuleRegistered($moduleIdentifier));
    }

    #[Test]
    #[DataProvider('ajaxRouteDataProvider')]
    public function ajaxRouteDeniesUserWithoutModuleAccess(string $routeIdentifier): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/ajax_route_access.csv');
        $this->setUpBackendUser(1);

        $response = $this->get(BackendModuleValidator::class)->process($this->buildRequest($routeIdentifier), $this->buildHandler());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    #[DataProvider('ajaxRouteDataProvider')]
    public function ajaxRouteAllowsUserWithModuleAccess(string $routeIdentifier): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/ajax_route_access.csv');
        $this->setUpBackendUser(2);

        $response = $this->get(BackendModuleValidator::class)->process($this->buildRequest($routeIdentifier), $this->buildHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    private function buildRequest(string $routeIdentifier): ServerRequestInterface
    {
        return (new ServerRequest('https://example.com/', 'GET'))
            ->withAttribute('route', $this->get(Router::class)->getRoute($routeIdentifier));
    }

    private function buildHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
    }
}
