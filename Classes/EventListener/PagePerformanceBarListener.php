<?php

declare(strict_types=1);

namespace T3G\Analytics\EventListener;

use T3G\Analytics\View\SparklineRenderer;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

final readonly class PagePerformanceBarListener
{
    public function __construct(
        private PageRenderer $pageRenderer,
        private AssetCollector $assetCollector,
        private SparklineRenderer $sparklineRenderer,
    ) {
    }

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $request = $event->getRequest();
        $queryParams = $request->getQueryParams();
        $pageId = (int)($queryParams['id'] ?? 0);
        if ($pageId <= 0) {
            return;
        }

        $days = $this->normalizeDays((int)($queryParams['tx_analytics_period'] ?? 7));

        $this->pageRenderer->addCssFile('EXT:analytics/Resources/Public/Css/PagePerformance.css');
        $this->pageRenderer->addCssFile('EXT:analytics/Resources/Public/Css/Components/Sparkline.css');
        $this->assetCollector->addInlineStyleSheet(
            'analytics-page-performance-icons',
            $this->renderIconVariables(),
        );
        $event->addHeaderContent($this->render($pageId, $days, $queryParams));
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function render(int $pageId, int $days, array $queryParams): string
    {
        $metrics = [
            [
                'key' => 'visitCount',
                'label' => $this->translate('pagePerformance.views'),
                'icon' => 'eye',
                'tone' => 'primary',
                'value' => '536',
                'trend' => '+11%',
                'trendDirection' => 'up',
                'details' => ['536', '482', '95'],
                'chart' => [64, 72, 58, 84, 76, 87, 95],
                'chartLegend' => ['64', '95'],
            ],
            [
                'key' => 'bounceRate',
                'label' => $this->translate('pagePerformance.bounceRate'),
                'icon' => 'arrow-right-from-bracket',
                'tone' => 'danger',
                'value' => '54.2%',
                'trend' => '-3.7%',
                'trendDirection' => 'down',
                'details' => ['54.2%', '57.9%', '61.4%'],
                'chart' => [61.4, 58.8, 56.1, 59.6, 55.2, 53.9, 54.2],
                'chartLegend' => ['61.4%', '54.2%'],
            ],
            [
                'key' => 'averageTimeOnPage',
                'label' => $this->translate('pagePerformance.averageTimeOnPage'),
                'icon' => 'clock',
                'tone' => 'success',
                'value' => '2:08',
                'trend' => '+18s',
                'trendDirection' => 'up',
                'details' => ['2:08', '1:50', '2:21'],
                'chart' => [96, 104, 99, 112, 118, 141, 128],
                'chartLegend' => ['1:36', '2:08'],
            ],
            [
                'key' => 'continuationRate',
                'label' => $this->translate('pagePerformance.continuationRate'),
                'icon' => 'right-to-bracket',
                'tone' => 'info',
                'value' => '45.8%',
                'trend' => '+5.1%',
                'trendDirection' => 'up',
                'details' => ['45.8%', '40.7%', '48.3%'],
                'chart' => [38.6, 41.2, 39.4, 44.8, 42.1, 48.3, 45.8],
                'chartLegend' => ['38.6%', '45.8%'],
            ],
        ];

        $html = '<section class="tx-analytics-performance-bar" aria-label="' . $this->escape($this->translate('pagePerformance.ariaLabel')) . '">';
        foreach ($metrics as $metric) {
            $html .= '<div class="tx-analytics-performance-metric tx-analytics-performance-metric-' . $this->escape($metric['tone']) . '" tabindex="0">';
            $html .= '<div class="tx-analytics-performance-metric-body">';
            $html .= '<span class="tx-analytics-performance-icon tx-analytics-performance-icon-' . $this->escape($metric['icon']) . '" aria-hidden="true"></span>';
            $html .= '<span class="tx-analytics-performance-value">' . $this->escape($metric['value']) . '</span>';
            $html .= '<span class="tx-analytics-performance-label">' . $this->escape($metric['label']) . '</span>';
            $html .= '<span class="tx-analytics-performance-trend tx-analytics-performance-trend-' . $this->escape($metric['trendDirection']) . '">';
            $html .= '<span class="tx-analytics-performance-trend-icon tx-analytics-performance-icon-arrow-trend-' . $this->escape($metric['trendDirection']) . '" aria-hidden="true"></span>';
            $html .= $this->escape($metric['trend']) . '</span>';
            $html .= $this->renderTooltip($metric);
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '<div class="tx-analytics-performance-period">';
        $html .= '<span class="tx-analytics-performance-period-icon tx-analytics-performance-icon-calendar-days" aria-hidden="true"></span>';
        $html .= '<form class="tx-analytics-performance-period-form" method="get">';
        foreach ($this->hiddenQueryFields($queryParams, $pageId) as $name => $value) {
            $html .= '<input type="hidden" name="' . $this->escape($name) . '" value="' . $this->escape($value) . '">';
        }
        $html .= '<label class="visually-hidden" for="tx-analytics-period-select">' . $this->escape($this->translate('pagePerformance.period')) . '</label>';
        $html .= '<select id="tx-analytics-period-select" class="form-select form-select-sm" name="tx_analytics_period" onchange="this.form.submit()">';
        foreach ([7, 14, 30] as $period) {
            $selected = $period === $days ? ' selected' : '';
            $html .= '<option value="' . $period . '"' . $selected . '>' . $this->escape($this->translate('pagePerformance.days', [$period])) . '</option>';
        }
        $html .= '</select></form>';
        $html .= '</div></section>';

        return $html;
    }

    /**
     * @param array{label: string, tone: string, details: list<string>, chart: list<int|float>, chartLegend: array{0: string, 1: string}} $metric
     */
    private function renderTooltip(array $metric): string
    {
        $detailLabels = [
            $this->translate('pagePerformance.tooltip.current'),
            $this->translate('pagePerformance.tooltip.previous'),
            $this->translate('pagePerformance.tooltip.peak'),
        ];

        $html = '<div class="tx-analytics-performance-tooltip" role="tooltip">';
        $html .= '<div class="tx-analytics-performance-tooltip-title">' . $this->escape($metric['label']) . '</div>';
        $html .= '<dl class="tx-analytics-performance-tooltip-data">';
        foreach ($detailLabels as $index => $label) {
            $html .= '<div><dt>' . $this->escape($label) . '</dt><dd>' . $this->escape($metric['details'][$index] ?? '-') . '</dd></div>';
        }
        $html .= '</dl>';
        $html .= '<div class="tx-analytics-performance-tooltip-chart" aria-label="' . $this->escape($this->translate('pagePerformance.tooltip.chart')) . '">';
        $html .= $this->sparklineRenderer->render($metric['chart'], [
            'label' => $this->translate('pagePerformance.tooltip.chart') . ': ' . $metric['label'],
            'class' => 'tx-analytics-performance-sparkline',
            'tone' => $metric['tone'],
        ]);
        $html .= '<div class="tx-analytics-performance-tooltip-chart-legend">';
        $html .= '<span><span>' . $this->escape($this->translate('pagePerformance.tooltip.start')) . '</span><strong>' . $this->escape($metric['chartLegend'][0]) . '</strong></span>';
        $html .= '<span><span>' . $this->escape($this->translate('pagePerformance.tooltip.now')) . '</span><strong>' . $this->escape($metric['chartLegend'][1]) . '</strong></span>';
        $html .= '</div>';
        $html .= '</div></div>';

        return $html;
    }

    private function normalizeDays(int $days): int
    {
        return in_array($days, [7, 14, 30], true) ? $days : 7;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, string>
     */
    private function hiddenQueryFields(array $queryParams, int $pageId): array
    {
        $fields = ['id' => (string)$pageId];
        foreach ($queryParams as $name => $value) {
            if ($name === 'id' || $name === 'tx_analytics_period' || !is_scalar($value)) {
                continue;
            }
            $fields[(string)$name] = (string)$value;
        }
        return $fields;
    }

    private function renderIconVariables(): string
    {
        $icons = [
            'arrow-right-from-bracket',
            'arrow-trend-down',
            'arrow-trend-up',
            'calendar-days',
            'clock',
            'eye',
            'right-to-bracket',
            'triangle-exclamation',
        ];
        $css = '';
        foreach ($icons as $icon) {
            $url = $this->assetUrl('EXT:analytics/Resources/Public/Icons/PagePerformance/' . $icon . '.svg');
            if ($url === '') {
                continue;
            }
            $css .= '.tx-analytics-performance-icon-' . $icon . '{--tx-analytics-performance-icon:url("' . $this->escape($url) . '");}';
        }
        return $css;
    }

    private function assetUrl(string $path): string
    {
        $absolutePath = GeneralUtility::getFileAbsFileName($path);
        if ($absolutePath === '') {
            return '';
        }
        return PathUtility::getAbsoluteWebPath($absolutePath);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param list<int|string> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $label = $this->getLanguageService()->sL(
            'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:' . $key
        );
        if ($label === '') {
            return $key;
        }
        return $arguments === [] ? $label : sprintf($label, ...$arguments);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
