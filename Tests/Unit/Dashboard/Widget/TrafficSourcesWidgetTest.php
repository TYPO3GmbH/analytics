<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Dashboard\Widget;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use T3G\Analytics\Dashboard\Widget\TrafficSourcesWidget;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\MetricFormatterInterface;
use T3G\Analytics\Service\TrafficSourcesServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TrafficSourcesWidgetTest extends UnitTestCase
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
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuration = $this->createMock(WidgetConfigurationInterface::class);
        $this->configuration->method('getIdentifier')->willReturn('analyticsTrafficSources');

        $this->siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);
        $this->trafficSourcesService = $this->createMock(TrafficSourcesServiceInterface::class);

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

    private function makeWidget(array $options = []): TrafficSourcesWidget
    {
        return new TrafficSourcesWidget(
            $this->configuration,
            $this->siteProvider,
            $this->trafficSourcesService,
            $this->formatter,
            $this->uriBuilder,
            $this->viewFactory,
            $options,
        );
    }

    #[Test]
    public function getOptionsReturnsDefaultsWhenNoneGiven(): void
    {
        $widget = $this->makeWidget();

        $options = $widget->getOptions();

        self::assertSame('', $options['site']);
        self::assertTrue($options['refreshAvailable']);
    }

    #[Test]
    public function getOptionsMergesGivenOptions(): void
    {
        $widget = $this->makeWidget(['site' => 'my-site', 'refreshAvailable' => false]);

        $options = $widget->getOptions();

        self::assertSame('my-site', $options['site']);
        self::assertFalse($options['refreshAvailable']);
    }

    #[Test]
    public function getCssFilesReturnsCssPath(): void
    {
        self::assertContains(
            'EXT:analytics/Resources/Public/Css/TrafficSources.css',
            $this->makeWidget()->getCssFiles(),
        );
    }

    #[Test]
    public function getJavaScriptModuleInstructionsReturnsTwoInstructions(): void
    {
        self::assertCount(2, $this->makeWidget()->getJavaScriptModuleInstructions());
    }

    #[Test]
    public function renderWidgetContentUsesFirstSiteWhenOptionSiteIsEmpty(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo Site', 'other' => 'Other']);
        $this->trafficSourcesService->expects(self::once())
            ->method('loadTrafficSources')->with('demo', 30)->willReturn([]);
        $this->trafficSourcesService->expects(self::once())
            ->method('loadDeviceData')->with('demo', 30)->willReturn([]);

        $this->makeWidget()->renderWidgetContent();
    }

    #[Test]
    public function renderWidgetContentUsesConfiguredSite(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo', 'other' => 'Other']);
        $this->trafficSourcesService->expects(self::once())
            ->method('loadTrafficSources')->with('other', 30)->willReturn([]);

        $this->makeWidget(['site' => 'other'])->renderWidgetContent();
    }

    #[Test]
    public function renderWidgetContentHandlesNullTrafficSources(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo']);
        $this->trafficSourcesService->method('loadTrafficSources')->willReturn(null);
        $this->trafficSourcesService->method('loadDeviceData')->willReturn([]);

        $this->view->expects(self::once())->method('assignMultiple')->with(
            self::callback(static fn (array $vars): bool => $vars['sections'][0]['items'] === []),
        )->willReturnSelf();

        $this->makeWidget()->renderWidgetContent();
    }

    #[Test]
    public function renderWidgetContentHandlesNullDeviceData(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo']);
        $this->trafficSourcesService->method('loadTrafficSources')->willReturn([]);
        $this->trafficSourcesService->method('loadDeviceData')->willReturn(null);

        $this->view->expects(self::once())->method('assignMultiple')->with(
            self::callback(static fn (array $vars): bool => $vars['sections'][1]['items'] === []),
        )->willReturnSelf();

        $this->makeWidget()->renderWidgetContent();
    }

    #[Test]
    public function renderWidgetContentShowsSiteSelectWhenMultipleSites(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo', 'other' => 'Other']);
        $this->trafficSourcesService->method('loadTrafficSources')->willReturn([]);
        $this->trafficSourcesService->method('loadDeviceData')->willReturn([]);

        $this->view->expects(self::once())->method('assignMultiple')->with(
            self::callback(static fn (array $vars): bool => $vars['showSiteSelect'] === true),
        )->willReturnSelf();

        $this->makeWidget()->renderWidgetContent();
    }

    #[Test]
    public function renderWidgetContentHidesSiteSelectWithSingleSite(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo']);
        $this->trafficSourcesService->method('loadTrafficSources')->willReturn([]);
        $this->trafficSourcesService->method('loadDeviceData')->willReturn([]);

        $this->view->expects(self::once())->method('assignMultiple')->with(
            self::callback(static fn (array $vars): bool => $vars['showSiteSelect'] === false),
        )->willReturnSelf();

        $this->makeWidget()->renderWidgetContent();
    }

    #[Test]
    public function renderWidgetContentReturnsViewOutput(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo']);
        $this->trafficSourcesService->method('loadTrafficSources')->willReturn([]);
        $this->trafficSourcesService->method('loadDeviceData')->willReturn([]);

        self::assertSame('<div>traffic-sources-html</div>', $this->makeWidget()->renderWidgetContent());
    }

    #[Test]
    public function renderWidgetContentSkipsTrafficSourcesCallWithNoSites(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn([]);
        $this->trafficSourcesService->expects(self::never())->method('loadTrafficSources');
        $this->trafficSourcesService->method('loadDeviceData')->willReturn(null);

        $this->view->expects(self::once())->method('assignMultiple')->with(
            self::callback(
                static fn (array $vars): bool =>
                $vars['siteIdentifier'] === ''
                && $vars['sections'][0]['items'] === []
                && $vars['sections'][1]['items'] === []
            ),
        )->willReturnSelf();

        $this->makeWidget()->renderWidgetContent();
    }
}
