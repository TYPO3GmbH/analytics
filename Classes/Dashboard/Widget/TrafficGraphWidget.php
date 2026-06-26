<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use T3G\Analytics\Dashboard\DashboardPeriods;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\TrafficChartDataBuilder;
use T3G\Analytics\Service\TrafficGraphServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

final readonly class TrafficGraphWidget implements WidgetInterface, AdditionalCssInterface, JavaScriptInterface
{
    use TrafficGraphWidgetTrait;

    /** @var array{site: string, days: int} */
    private array $options;

    /**
     * @param array{site?: string, days?: int} $options
     */
    public function __construct(
        /** @phpstan-ignore property.onlyWritten (required by TYPO3 dashboard.widget DI compiler pass) */
        private WidgetConfigurationInterface $configuration,
        private TrafficGraphServiceInterface $trafficGraphService,
        private AnalyticsSiteProviderInterface $siteProvider,
        private UriBuilder $uriBuilder,
        private TrafficChartDataBuilder $chartDataBuilder,
        private ViewFactoryInterface $viewFactory,
        array $options = [],
    ) {
        $this->options = array_replace(
            ['site' => '', 'days' => DashboardPeriods::defaultPeriod()],
            $options
        );
    }

    public function renderWidgetContent(): string
    {
        return $this->renderContent((string) $this->options['site']);
    }

    /**
     * @return array{site: string, days: int}
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return list<JavaScriptModuleInstruction>
     */
    public function getJavaScriptModuleInstructions(): array
    {
        return [
            JavaScriptModuleInstruction::create('@t3g/analytics/traffic-graph-widget.js'),
        ];
    }

    private function renderContent(string $siteIdentifier): string
    {
        $siteOptions = $this->siteProvider->siteOptions();
        if ($siteIdentifier === '' && $siteOptions !== []) {
            $siteIdentifier = array_key_first($siteOptions);
        }
        $days = max(1, (int) $this->options['days']);

        // spl_object_id gives the object's memory address, unique per live object within a request.
        // Combined with site/option data to produce distinct HTML element IDs when multiple
        // widget instances are rendered on the same dashboard.
        $uniqueId = substr(sha1((string) spl_object_id($this) . $siteIdentifier . implode('', array_keys($siteOptions))), 0, 8);

        $metricData = [
            'visits' => $this->trafficGraphService->loadGraphData($siteIdentifier, $days),
            'sessions' => $this->trafficGraphService->loadSessionsData($siteIdentifier, $days),
            'visitors' => $this->trafficGraphService->loadVisitorsData($siteIdentifier, $days),
        ];
        $metricLabels = [
            'visits' => $this->translate('dashboardWidget.trafficGraph.chartLabel.visits'),
            'sessions' => $this->translate('dashboardWidget.trafficGraph.chartLabel.sessions'),
            'visitors' => $this->translate('dashboardWidget.trafficGraph.chartLabel.visitors'),
        ];
        $chart = $this->chartDataBuilder->buildMulti($metricData, $metricLabels, ['visits' => 'visits', 'sessions' => 'sessions', 'visitors' => 'visitors'], ['visits' => 0, 'sessions' => 1, 'visitors' => 1]);

        $view = $this->viewFactory->create($this->createViewFactoryData());
        $view->assignMultiple([
            'siteIdentifier' => $siteIdentifier,
            'selectedDays' => $days,
            'siteSelectId' => 'tx-analytics-traffic-graph-site-' . $uniqueId,
            'siteLabel' => $this->translate('dashboardWidget.trafficGraph.setting.site.label'),
            'showSiteSelect' => count($siteOptions) > 1,
            'siteOptions' => $this->buildSiteOptions($siteOptions, $siteIdentifier),
            'periodSelectId' => 'tx-analytics-traffic-graph-period-' . $uniqueId,
            'periodLabel' => $this->translate('dashboardWidget.trafficGraph.setting.period.label'),
            'periodOptions' => $this->buildPeriodOptions($days),
            'chart' => $chart,
            'showAllLabel' => $this->translate('dashboardWidget.trafficGraph.showAll'),
            'showAllUrl' => $this->buildShowAllUrl($siteIdentifier, $days),
        ]);

        return $view->render('Dashboard/Widget/TrafficGraph');
    }
}
