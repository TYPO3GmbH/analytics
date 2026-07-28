<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Dashboard\Widget;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use T3G\Analytics\Dashboard\Widget\TrafficSourcesWidgetV14;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\MetricFormatterInterface;
use T3G\Analytics\Service\TrafficSourcesServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Settings\SettingsInterface;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TrafficSourcesWidgetV14Test extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private WidgetConfigurationInterface&MockObject $configuration;
    private AnalyticsSiteProviderInterface&MockObject $siteProvider;
    private TrafficSourcesServiceInterface&MockObject $trafficSourcesService;
    private MetricFormatterInterface&MockObject $formatter;
    private UriBuilder&MockObject $uriBuilder;
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
        $this->configuration->method('getIdentifier')->willReturn('analyticsTrafficSourcesV14');

        $this->siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);

        $this->trafficSourcesService = $this->createMock(TrafficSourcesServiceInterface::class);
        $this->trafficSourcesService->method('loadTrafficSources')->willReturn([]);
        $this->trafficSourcesService->method('loadDeviceData')->willReturn([]);
        $this->trafficSourcesService->method('loadBrowserData')->willReturn([]);
        $this->trafficSourcesService->method('loadCountryData')->willReturn([]);

        $this->formatter = $this->createMock(MetricFormatterInterface::class);
        $this->formatter->method('formatShare')->willReturn('0.00');
        $this->formatter->method('formatPercentageChange')->willReturn(null);

        $mockUri = $this->createMock(\Psr\Http\Message\UriInterface::class);
        $mockUri->method('__toString')->willReturn('');
        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->uriBuilder->method('buildUriFromRoute')->willReturn($mockUri);

        $this->view = $this->createMock(ViewInterface::class);
        $this->view->method('assignMultiple')->willReturnSelf();
        $this->view->method('render')->willReturn('<div>traffic-sources-html</div>');

        $this->viewFactory = $this->createMock(ViewFactoryInterface::class);
        $this->viewFactory->method('create')->willReturn($this->view);
    }

    private function makeWidget(): TrafficSourcesWidgetV14
    {
        return new TrafficSourcesWidgetV14(
            $this->configuration,
            $this->siteProvider,
            $this->trafficSourcesService,
            $this->formatter,
            $this->uriBuilder,
            $this->viewFactory,
        );
    }

    private function makeSettings(
        string $site = 'demo',
        int $days = 7,
        string $section = 'sources',
        string $chartType = 'list',
        string $title = '',
        bool $showMeta = true,
    ): SettingsInterface {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('get')->willReturnMap([
            ['site', $site],
            ['days', $days],
            ['section', $section],
            ['chartType', $chartType],
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
        self::assertNotContains('section', $keys);
        self::assertContains('chartType', $keys);
        self::assertContains('title', $keys);
        self::assertContains('showMeta', $keys);
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

        self::assertSame('dashboardWidget.trafficSources.title (Demo Site · 7 days)', $result->label);
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

        self::assertSame('dashboardWidget.trafficSources.title', $result->label);
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

    #[Test]
    public function renderWidgetFallsBackToFirstSiteWhenSiteIdentifierIsEmpty(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo Site', 'other' => 'Other Site']);

        $result = $this->makeWidget()->renderWidget($this->makeContext($this->makeSettings(
            site: '',
            days: 7,
            showMeta: true,
        )));

        self::assertStringContainsString('Demo Site', $result->label ?? '');
    }
}
