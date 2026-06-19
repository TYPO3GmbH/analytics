<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use T3G\Analytics\Dashboard\DashboardPeriods;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\TrafficChartDataBuilder;
use T3G\Analytics\Service\TrafficGraphServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;

/**
 * Traffic Graph dashboard widget for TYPO3 v14+.
 *
 * Site and period are configured via the native widget settings panel (WidgetRendererInterface)
 * rather than inline dropdowns. Requires TYPO3 >= 14 (WidgetRendererInterface).
 */
final readonly class TrafficGraphWidgetV14 implements WidgetRendererInterface, AdditionalCssInterface
{
    use TrafficGraphWidgetTrait;

    public function __construct(
        /** @phpstan-ignore property.onlyWritten (required by TYPO3 dashboard.widget DI compiler pass) */
        private WidgetConfigurationInterface $configuration,
        private TrafficGraphServiceInterface $trafficGraphService,
        private AnalyticsSiteProviderInterface $siteProvider,
        private UriBuilder $uriBuilder,
        private TrafficChartDataBuilder $chartDataBuilder,
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
                label: 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:dashboardWidget.trafficGraph.setting.site.label',
                enum: $siteOptions,
            ),
            new SettingDefinition(
                key: 'days',
                type: 'int',
                default: DashboardPeriods::defaultPeriod(),
                label: 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:dashboardWidget.trafficGraph.setting.period.label',
                enum: $this->periodEnumOptions(),
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
        $siteIdentifier = (string) $context->settings->get('site');
        $days = max(1, (int) $context->settings->get('days'));
        $customTitle = trim((string) $context->settings->get('title'));
        $showMeta = (bool) $context->settings->get('showMeta');

        $siteOptions = $this->siteProvider->siteOptions();
        $siteLabel = $siteOptions[$siteIdentifier] ?? $siteIdentifier;
        $periodOptions = $this->periodEnumOptions();
        $periodLabel = $periodOptions[$days] ?? '';

        $baseTitle = $customTitle !== '' ? $customTitle : $this->translate('dashboardWidget.trafficGraph.title');
        $label = $showMeta && $siteLabel !== ''
            ? $baseTitle . ' (' . $siteLabel . ' · ' . $periodLabel . ')'
            : $baseTitle;

        $graphData = $this->trafficGraphService->loadGraphData($siteIdentifier, $days);
        $chart = $this->buildChartData($graphData);

        $view = $this->viewFactory->create($this->createViewFactoryData($context->request));
        $view->assignMultiple([
            'chart' => $chart,
            'showAllLabel' => $this->translate('dashboardWidget.trafficGraph.showAll'),
            'showAllUrl' => $this->buildShowAllUrl($siteIdentifier, $days),
        ]);

        return new WidgetResult(
            content: $view->render('Dashboard/Widget/TrafficGraphV14'),
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
