<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Dashboard\Widget;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use T3G\Analytics\Dashboard\Widget\TrafficGraphWidgetV14;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\TrafficChartDataBuilder;
use T3G\Analytics\Service\TrafficGraphServiceInterface;
use T3G\Analytics\View\SparklineRenderer;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Settings\SettingsInterface;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TrafficGraphWidgetV14Test extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private WidgetConfigurationInterface&MockObject $configuration;
    private TrafficGraphServiceInterface&MockObject $trafficGraphService;
    private AnalyticsSiteProviderInterface&MockObject $siteProvider;
    private UriBuilder&MockObject $uriBuilder;
    private TrafficChartDataBuilder $chartDataBuilder;
    private ViewFactoryInterface&MockObject $viewFactory;
    private ViewInterface&MockObject $view;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!interface_exists(WidgetConfigurationInterface::class)) {
            $candidates = [
                dirname(__DIR__, 4) . '/vendor/typo3/cms-dashboard/Classes/Widgets/',
                dirname(__DIR__, 4) . '/.Build/dummy-typo3/vendor/typo3/cms-dashboard/Classes/Widgets/',
            ];
            foreach ($candidates as $dashboardPath) {
                if (!is_dir($dashboardPath)) {
                    continue;
                }
                foreach (['WidgetConfigurationInterface.php', 'WidgetInterface.php', 'AdditionalCssInterface.php', 'JavaScriptInterface.php'] as $file) {
                    $full = $dashboardPath . $file;
                    if (file_exists($full)) {
                        require_once $full;
                    }
                }
                break;
            }
        }

        require_once dirname(__DIR__, 4) . '/Build/phpstan.bootstrap-v14stubs.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuration = $this->createMock(WidgetConfigurationInterface::class);
        $this->configuration->method('getIdentifier')->willReturn('analyticsTrafficGraphV14');

        $this->trafficGraphService = $this->createMock(TrafficGraphServiceInterface::class);
        $this->trafficGraphService->method('loadGraphData')->willReturn(null);
        $this->trafficGraphService->method('loadSessionsData')->willReturn(null);
        $this->trafficGraphService->method('loadVisitorsData')->willReturn(null);

        $this->siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);

        $mockUri = $this->createMock(\Psr\Http\Message\UriInterface::class);
        $mockUri->method('__toString')->willReturn('');
        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->uriBuilder->method('buildUriFromRoute')->willReturn($mockUri);

        $this->chartDataBuilder = new TrafficChartDataBuilder(new SparklineRenderer());

        $this->view = $this->createMock(ViewInterface::class);
        $this->view->method('assignMultiple')->willReturnSelf();
        $this->view->method('render')->willReturn('<div>traffic-graph-html</div>');

        $this->viewFactory = $this->createMock(ViewFactoryInterface::class);
        $this->viewFactory->method('create')->willReturn($this->view);
    }

    private function makeWidget(): TrafficGraphWidgetV14
    {
        return new TrafficGraphWidgetV14(
            $this->configuration,
            $this->trafficGraphService,
            $this->siteProvider,
            $this->uriBuilder,
            $this->chartDataBuilder,
            $this->viewFactory,
        );
    }

    private function makeSettings(string $site = 'demo', int $days = 7, string $title = '', bool $showMeta = true): SettingsInterface
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('get')->willReturnMap([
            ['site', $site],
            ['days', $days],
            ['title', $title],
            ['showMeta', $showMeta],
        ]);
        return $settings;
    }

    private function makeContext(SettingsInterface $settings): WidgetContext
    {
        // WidgetContext gained required constructor parameters in TYPO3 v15 (dev-main).
        // ReflectionClass::newInstanceWithoutConstructor() bypasses the constructor on all versions.
        $context = (new \ReflectionClass(WidgetContext::class))->newInstanceWithoutConstructor();
        $context->settings = $settings;
        $context->request = $this->createMock(ServerRequestInterface::class);
        return $context;
    }

    #[Test]
    public function getSettingsDefinitionsContainsExpectedKeys(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo Site']);

        $definitions = $this->makeWidget()->getSettingsDefinitions();
        $keys = array_map(static fn (SettingDefinition $d) => $d->key, $definitions);

        self::assertContains('site', $keys);
        self::assertContains('days', $keys);
        self::assertContains('title', $keys);
        self::assertContains('showMeta', $keys);
        // showVisits/showSessions/showVisitors removed — interactive legend toggle handles visibility
        self::assertNotContains('showVisits', $keys);
        self::assertNotContains('showSessions', $keys);
        self::assertNotContains('showVisitors', $keys);
    }

    #[Test]
    public function renderWidgetReturnsWidgetResultInstance(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo Site']);

        $result = $this->makeWidget()->renderWidget($this->makeContext($this->makeSettings()));

        self::assertInstanceOf(WidgetResult::class, $result);
    }

    #[Test]
    public function renderWidgetLabelIncludesMetaWhenShowMetaIsTrue(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo Site']);

        $result = $this->makeWidget()->renderWidget($this->makeContext($this->makeSettings(
            site: 'demo',
            days: 7,
            showMeta: true,
        )));

        self::assertSame('dashboardWidget.trafficGraph.title (Demo Site · 7 days)', $result->label);
    }

    #[Test]
    public function renderWidgetLabelExcludesMetaWhenShowMetaIsFalse(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo Site']);

        $result = $this->makeWidget()->renderWidget($this->makeContext($this->makeSettings(
            site: 'demo',
            days: 7,
            showMeta: false,
        )));

        self::assertSame('dashboardWidget.trafficGraph.title', $result->label);
    }

    #[Test]
    public function renderWidgetUsesCustomTitleAsBaseWhenSet(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo Site']);

        $result = $this->makeWidget()->renderWidget($this->makeContext($this->makeSettings(
            site: 'demo',
            title: 'My Custom Title',
            showMeta: false,
        )));

        self::assertSame('My Custom Title', $result->label);
    }

    #[Test]
    public function renderWidgetCombinesCustomTitleWithMetaWhenShowMetaIsTrue(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo Site']);

        $result = $this->makeWidget()->renderWidget($this->makeContext($this->makeSettings(
            site: 'demo',
            days: 7,
            title: 'My Custom Title',
            showMeta: true,
        )));

        self::assertSame('My Custom Title (Demo Site · 7 days)', $result->label);
    }

    #[Test]
    public function renderWidgetFallsBackToSiteIdentifierWhenNotInSiteOptions(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['other' => 'Other Site']);

        $result = $this->makeWidget()->renderWidget($this->makeContext($this->makeSettings(
            site: 'unknown-site',
            days: 7,
            showMeta: true,
        )));

        self::assertStringContainsString('unknown-site', $result->label ?? '');
    }
}
