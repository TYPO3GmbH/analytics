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
 * Site, period, and displayed section are configured via the native widget settings panel.
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
                key: 'section',
                type: 'string',
                default: 'sources',
                label: 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:dashboardWidget.trafficSources.setting.section.label',
                enum: [
                    'sources' => 'Channel',
                    'devices' => 'Devices',
                    'browser' => 'Browser',
                    'countries' => 'Countries',
                ],
            ),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $siteIdentifier = (string)$context->settings->get('site');
        $days = max(1, (int)$context->settings->get('days'));
        $section = (string)$context->settings->get('section');

        $siteOptions = $this->siteProvider->siteOptions();
        if ($siteIdentifier === '' && $siteOptions !== []) {
            $siteIdentifier = array_key_first($siteOptions);
        }

        $sections = match ($section) {
            'devices' => [
                [
                    'icon' => 'display',
                    'title' => $this->translate('dashboardWidget.trafficSources.devices'),
                    'showSiteSelect' => false,
                    'items' => $this->buildDeviceItems($this->loadDevices($siteIdentifier, $days)),
                ],
            ],
            'browser' => [
                [
                    'icon' => 'browser',
                    'title' => $this->translate('dashboardWidget.trafficSources.browsers'),
                    'showSiteSelect' => false,
                    'items' => $this->buildBrowserItems($this->loadBrowsers($siteIdentifier, $days)),
                ],
            ],
            'countries' => [
                [
                    'icon' => 'earth-europe',
                    'title' => $this->translate('dashboardWidget.trafficSources.countries'),
                    'showSiteSelect' => false,
                    'items' => $this->buildCountryItems($this->loadCountries($siteIdentifier, $days)),
                ],
            ],
            default => [
                [
                    'icon' => 'earth-europe',
                    'title' => $this->translate('dashboardWidget.trafficSources.sources'),
                    'showSiteSelect' => false,
                    'items' => $this->buildTrafficSourceItems(
                        $siteIdentifier !== ''
                            ? ($this->trafficSourcesService->loadTrafficSources($siteIdentifier, $days) ?? [])
                            : []
                    ),
                ],
            ],
        };

        $ctaPath = match ($section) {
            'devices', 'browser' => 'devices',
            'countries' => 'visitors/locations',
            default => 'traffic/share',
        };

        $view = $this->viewFactory->create($this->createViewFactoryData($context->request));
        $view->assignMultiple([
            'siteIdentifier' => $siteIdentifier,
            'showAllLabel' => $this->translate('dashboardWidget.trafficSources.showAll'),
            'showAllUrl' => $this->buildDashboardUrl($siteIdentifier, $days, $ctaPath),
            'sections' => $sections,
        ]);

        return new WidgetResult(
            content: $view->render('Dashboard/Widget/TrafficSourcesV14'),
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
