<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\SitePerformanceServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

final readonly class SitePerformanceWidget implements WidgetInterface, AdditionalCssInterface, JavaScriptInterface
{
    use SitePerformanceWidgetTrait;

    /** @var array{site: string, days: int} */
    private array $options;

    /**
     * @param array{site?: string, days?: int} $options
     */
    public function __construct(
        /** @phpstan-ignore property.onlyWritten (required by TYPO3 dashboard.widget DI compiler pass) */
        private WidgetConfigurationInterface $configuration,
        private SitePerformanceServiceInterface $sitePerformanceService,
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
            JavaScriptModuleInstruction::create('@t3g/analytics/site-performance-widget.js'),
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

        $data = $this->sitePerformanceService->loadPerformanceData($siteIdentifier, $days);
        $metrics = $data !== null ? $this->buildMetrics($data) : [];
        $currentPeriodLabel = sprintf($this->translate('pagePerformance.days'), $days);

        $view = $this->viewFactory->create($this->createViewFactoryData());
        $view->assignMultiple([
            'siteIdentifier' => $siteIdentifier,
            'selectedDays' => $days,
            'siteSelectId' => 'tx-analytics-site-performance-site-' . $uniqueId,
            'siteLabel' => $this->translate('dashboardWidget.sitePerformance.setting.site.label'),
            'showSiteSelect' => count($siteOptions) > 1,
            'siteOptions' => $this->buildSiteOptions($siteOptions, $siteIdentifier),
            'periodSelectId' => 'tx-analytics-site-performance-period-' . $uniqueId,
            'periodLabel' => $this->translate('dashboardWidget.sitePerformance.setting.period.label'),
            'periodOptions' => $this->buildPeriodOptions($days),
            'metrics' => $metrics,
            'noData' => $metrics === [],
            'currentPeriodLabel' => $currentPeriodLabel,
            'showAllLabel' => $this->translate('dashboardWidget.sitePerformance.showAll'),
            'showAllUrl' => $this->buildShowAllUrl($siteIdentifier, $days),
        ]);

        return $view->render('Dashboard/Widget/SitePerformance');
    }
}
