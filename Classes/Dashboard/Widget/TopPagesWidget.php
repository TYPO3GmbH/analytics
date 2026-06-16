<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use T3G\Analytics\Dashboard\DashboardPeriods;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\TopPagesServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

final readonly class TopPagesWidget implements WidgetInterface, AdditionalCssInterface, JavaScriptInterface
{
    use TopPagesWidgetTrait;

    /** @var array{site: string, days: int} */
    private array $options;

    /**
     * @param array{site?: string, days?: int} $options
     */
    public function __construct(
        /** @phpstan-ignore property.onlyWritten (required by TYPO3 dashboard.widget DI compiler pass) */
        private WidgetConfigurationInterface $configuration,
        private TopPagesServiceInterface $topPagesService,
        private AnalyticsSiteProviderInterface $siteProvider,
        private UriBuilder $uriBuilder,
        private ViewFactoryInterface $viewFactory,
        array $options = [],
    ) {
        $this->options = array_replace(
            ['site' => '', 'days' => 7],
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
            JavaScriptModuleInstruction::create('@t3g/analytics/top-pages-widget.js'),
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

        $view = $this->viewFactory->create($this->createViewFactoryData());
        $view->assignMultiple([
            'siteIdentifier' => $siteIdentifier,
            'selectedDays' => $days,
            'siteSelectId' => 'tx-analytics-top-pages-site-' . $uniqueId,
            'siteLabel' => $this->translate('dashboardWidget.topPages.setting.site.label'),
            'showSiteSelect' => count($siteOptions) > 1,
            'siteOptions' => $this->buildSiteOptions($siteOptions, $siteIdentifier),
            'periodSelectId' => 'tx-analytics-top-pages-period-' . $uniqueId,
            'periodLabel' => $this->translate('dashboardWidget.topPages.setting.period.label'),
            'periodOptions' => $this->buildPeriodOptions($days),
            'showAllLabel' => $this->translate('dashboardWidget.topPages.showAll'),
            'showAllUrl' => $this->buildShowAllUrl($siteIdentifier, $days),
            'pages' => $this->buildPages($siteIdentifier, $days, 10),
        ]);

        return $view->render('Dashboard/Widget/TopPages');
    }

    /**
     * @param array<string, string> $siteOptions
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function buildSiteOptions(array $siteOptions, string $siteIdentifier): array
    {
        $options = [];
        foreach ($siteOptions as $identifier => $label) {
            $options[] = [
                'value' => $identifier,
                'label' => $label,
                'selected' => $identifier === $siteIdentifier,
            ];
        }
        return $options;
    }

    /**
     * @return list<array{value: int, label: string, selected: bool}>
     */
    private function buildPeriodOptions(int $selectedDays): array
    {
        $options = [];
        foreach (DashboardPeriods::periods() as $period) {
            $options[] = [
                'value' => $period,
                'label' => sprintf($this->translate('pagePerformance.days'), $period),
                'selected' => $period === $selectedDays,
            ];
        }
        return $options;
    }
}
