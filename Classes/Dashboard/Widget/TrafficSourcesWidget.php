<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use T3G\Analytics\Dashboard\DashboardPeriods;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\MetricFormatterInterface;
use T3G\Analytics\Service\TrafficSourcesServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

final readonly class TrafficSourcesWidget implements WidgetInterface, AdditionalCssInterface, JavaScriptInterface
{
    use TrafficSourcesWidgetTrait;

    /** @var array{site: string, days: int} */
    private array $options;

    /**
     * @param array{site?: string, days?: int} $options
     */
    public function __construct(
        /** @phpstan-ignore property.onlyWritten (required by TYPO3 dashboard.widget DI compiler pass) */
        private WidgetConfigurationInterface $configuration,
        private AnalyticsSiteProviderInterface $siteProvider,
        private TrafficSourcesServiceInterface $trafficSourcesService,
        private MetricFormatterInterface $formatter,
        private UriBuilder $uriBuilder,
        private ViewFactoryInterface $viewFactory,
        array $options = [],
    ) {
        $this->options = array_replace(
            [
                'site' => '',
                'days' => DashboardPeriods::defaultPeriod(),
            ],
            $options
        );
    }

    public function renderWidgetContent(): string
    {
        return $this->renderContent((string)$this->options['site']);
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
            JavaScriptModuleInstruction::create('@typo3/backend/element/progress-bar-element.js'),
            JavaScriptModuleInstruction::create('@t3g/analytics/traffic-sources-widget.js'),
        ];
    }

    private function renderContent(string $siteIdentifier): string
    {
        $siteOptions = $this->siteProvider->siteOptions();
        if ($siteIdentifier === '' && $siteOptions !== []) {
            $siteIdentifier = array_key_first($siteOptions);
        }
        $days = max(1, (int)$this->options['days']);

        $uniqueId = substr(sha1((string)spl_object_id($this) . $siteIdentifier . implode('', array_keys($siteOptions))), 0, 8);

        $sources = $siteIdentifier !== ''
            ? ($this->trafficSourcesService->loadTrafficSources($siteIdentifier, $days) ?? [])
            : [];
        $devices = $this->loadDevices($siteIdentifier, $days);
        $browsers = $this->loadBrowsers($siteIdentifier, $days);
        $countries = $this->loadCountries($siteIdentifier, $days);

        $view = $this->viewFactory->create($this->createViewFactoryData());
        $view->assignMultiple([
            'siteIdentifier' => $siteIdentifier,
            'selectedDays' => $days,
            'siteSelectId' => 'tx-analytics-traffic-sources-site-' . $uniqueId,
            'siteLabel' => $this->translate('dashboardWidget.trafficSources.setting.site.label'),
            'showSiteSelect' => count($siteOptions) > 1,
            'siteOptions' => $this->buildSiteOptions($siteOptions, $siteIdentifier),
            'periodSelectId' => 'tx-analytics-traffic-sources-period-' . $uniqueId,
            'periodLabel' => $this->translate('dashboardWidget.trafficSources.setting.period.label'),
            'periodOptions' => $this->buildPeriodOptions($days),
            'showAllLabel' => $this->translate('dashboardWidget.trafficSources.showAll'),
            'showAllUrl' => $this->buildDashboardUrl($siteIdentifier, $days, 'traffic/share'),
            'total' => (int) array_sum(array_map(static fn (array $d): int => $d['current'], $sources)),
            'sections' => [
                $this->asSectionData('earth-europe', $this->translate('dashboardWidget.trafficSources.sources'), $this->buildTrafficSourceItems($sources), false, (int) array_sum(array_map(static fn (array $d): int => $d['current'], $sources))),
                $this->asSectionData('display', $this->translate('dashboardWidget.trafficSources.devices'), $this->buildDeviceItems($devices), false, (int) array_sum(array_column($devices, 'sessionCount'))),
                $this->asSectionData('browser', $this->translate('dashboardWidget.trafficSources.browsers'), $this->buildBrowserItems($browsers), false, (int) array_sum(array_column($browsers, 'sessionCount'))),
                $this->asSectionData('earth-europe', $this->translate('dashboardWidget.trafficSources.countries'), $this->buildCountryItems($countries), false, (int) array_sum(array_column($countries, 'sessionCount'))),
            ],
        ]);

        return $view->render('Dashboard/Widget/TrafficSources');
    }
}
