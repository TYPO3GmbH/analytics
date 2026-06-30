<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use T3G\Analytics\Dashboard\DashboardPeriods;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\MetricFormatterInterface;
use T3G\Analytics\Service\TrafficSourcesServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;

/**
 * Traffic Sources dashboard widget for TYPO3 v14+.
 *
 * Site, period, displayed section and chart type are configured via the native widget settings panel.
 */
final readonly class TrafficSourcesWidgetV14 implements WidgetRendererInterface, AdditionalCssInterface
{
    use TrafficSourcesWidgetTrait;

    public function __construct(
        /** @phpstan-ignore property.onlyWritten (required by TYPO3 dashboard.widget DI compiler pass) */
        private WidgetConfigurationInterface $configuration,
        private AnalyticsSiteProviderInterface $siteProvider,
        private TrafficSourcesServiceInterface $trafficSourcesService,
        private MetricFormatterInterface $formatter,
        private UriBuilder $uriBuilder,
        private ViewFactoryInterface $viewFactory,
        private string $section = 'sources',
        private string $chartType = 'list',
    ) {
    }

    /**
     * @return list<SettingDefinition>
     */
    public function getSettingsDefinitions(): array
    {
        $siteOptions = $this->siteProvider->siteOptions();

        return [
            new SettingDefinition(
                key: 'site',
                type: 'string',
                default: array_key_first($siteOptions) ?? '',
                label: 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:dashboardWidget.trafficSources.setting.site.label',
                enum: $siteOptions,
            ),
            new SettingDefinition(
                key: 'days',
                type: 'int',
                default: DashboardPeriods::defaultPeriod(),
                label: 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:dashboardWidget.trafficSources.setting.period.label',
                enum: $this->periodEnumOptions(),
            ),
            new SettingDefinition(
                key: 'chartType',
                type: 'string',
                default: $this->chartType,
                label: 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:dashboardWidget.trafficSources.setting.chartType.label',
                enum: [
                    'list' => 'List',
                    'donut' => 'Donut',
                ],
            ),
            new SettingDefinition(
                key: 'title',
                type: 'string',
                default: '',
                label: 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:dashboardWidget.setting.title.label',
            ),
            new SettingDefinition(
                key: 'showMeta',
                type: 'bool',
                default: true,
                label: 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:dashboardWidget.setting.showMeta.label',
            ),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $siteIdentifier = (string)$context->settings->get('site');
        $days = max(1, (int)$context->settings->get('days'));
        $section = $this->section;
        $isDonut = $context->settings->get('chartType') === 'donut';
        $customTitle = trim((string)$context->settings->get('title'));
        $showMeta = (bool)$context->settings->get('showMeta');

        $siteOptions = $this->siteProvider->siteOptions();
        if ($siteIdentifier === '' && $siteOptions !== []) {
            $siteIdentifier = array_key_first($siteOptions);
        }
        $siteLabel = $siteOptions[$siteIdentifier] ?? $siteIdentifier;
        $periodOptions = $this->periodEnumOptions();
        $periodLabel = $periodOptions[$days] ?? '';

        if ($section === 'devices') {
            $devices = $this->loadDevices($siteIdentifier, $days);
            $sections = [
                $this->asSectionData('display', $this->translate('dashboardWidget.trafficSources.devices'), $this->buildDeviceItems($devices), $isDonut, (int) array_sum(array_column($devices, 'sessionCount'))),
            ];
        } elseif ($section === 'browser') {
            $browsers = $this->loadBrowsers($siteIdentifier, $days);
            $sections = [
                $this->asSectionData('browser', $this->translate('dashboardWidget.trafficSources.browsers'), $this->buildBrowserItems($browsers), $isDonut, (int) array_sum(array_column($browsers, 'sessionCount'))),
            ];
        } elseif ($section === 'countries') {
            $countries = $this->loadCountries($siteIdentifier, $days);
            $sections = [
                $this->asSectionData('earth-europe', $this->translate('dashboardWidget.trafficSources.countries'), $this->buildCountryItems($countries), $isDonut, (int) array_sum(array_column($countries, 'sessionCount'))),
            ];
        } else {
            $sources = $siteIdentifier !== '' ? ($this->trafficSourcesService->loadTrafficSources($siteIdentifier, $days) ?? []) : [];
            $sections = [
                $this->asSectionData('earth-europe', $this->translate('dashboardWidget.trafficSources.sources'), $this->buildTrafficSourceItems($sources), $isDonut, (int) array_sum(array_map(static fn (array $d): int => $d['current'], $sources))),
            ];
        }

        $ctaPath = match ($section) {
            'devices', 'browser' => 'devices',
            'countries' => 'visitors/locations',
            default => 'traffic/share',
        };

        $baseTitle = $customTitle !== '' ? $customTitle : $this->translate('dashboardWidget.trafficSources.title');
        $label = $showMeta && $siteLabel !== ''
            ? $baseTitle . ' (' . $siteLabel . ' · ' . $periodLabel . ')'
            : $baseTitle;

        $view = $this->viewFactory->create($this->createViewFactoryData($context->request));
        $view->assignMultiple([
            'siteIdentifier' => $siteIdentifier,
            'noData' => ($sections[0]['items'] ?? []) === [],
            'periodLabel' => $periodLabel,
            'showAllLabel' => $this->translate('dashboardWidget.trafficSources.showAll'),
            'showAllUrl' => $this->buildDashboardUrl($siteIdentifier, $days, $ctaPath),
            'sections' => $sections,
        ]);

        return new WidgetResult(
            content: $view->render('Dashboard/Widget/TrafficSourcesV14'),
            label: $label,
        );
    }

    /**
     * @return array<int, string>
     */
    private function periodEnumOptions(): array
    {
        $label = $this->translate('pagePerformance.days');
        $hasLabel = $label !== 'pagePerformance.days';
        $options = [];
        foreach (DashboardPeriods::periods() as $days) {
            $options[$days] = $hasLabel ? sprintf($label, $days) : ($days . ' days');
        }
        return $options;
    }
}
