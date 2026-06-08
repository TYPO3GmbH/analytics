<?php

declare(strict_types=1);

namespace T3G\Analytics\EventListener;

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
                'key' => 'views',
                'label' => $this->translate('pagePerformance.views'),
                'icon' => 'eye',
                'tone' => 'primary',
                'value' => '184',
                'trend' => '+23%',
                'trendDirection' => 'up',
                'details' => ['184', '149', '42'],
                'chart' => [42, 56, 48, 72, 64, 88, 100],
            ],
            [
                'key' => 'bounceRate',
                'label' => $this->translate('pagePerformance.bounceRate'),
                'icon' => 'arrow-right-from-bracket',
                'tone' => 'danger',
                'value' => '42%',
                'trend' => '-6%',
                'trendDirection' => 'down',
                'details' => ['42%', '48%', '35%'],
                'chart' => [70, 64, 58, 68, 52, 48, 42],
            ],
            [
                'key' => 'averageTimeOnPage',
                'label' => $this->translate('pagePerformance.averageTimeOnPage'),
                'icon' => 'clock',
                'tone' => 'success',
                'value' => '3:12',
                'trend' => '+14%',
                'trendDirection' => 'up',
                'details' => ['3:12', '2:48', '4:21'],
                'chart' => [45, 50, 46, 60, 66, 74, 82],
            ],
            [
                'key' => 'entryRate',
                'label' => $this->translate('pagePerformance.entryRate'),
                'icon' => 'right-to-bracket',
                'tone' => 'info',
                'value' => '51%',
                'trend' => '+18%',
                'trendDirection' => 'up',
                'details' => ['51%', '43%', '58%'],
                'chart' => [48, 44, 52, 55, 60, 64, 70],
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
     * @param array{label: string, details: list<string>, chart: list<int>} $metric
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
        foreach ($metric['chart'] as $height) {
            $html .= '<span style="--tx-analytics-chart-height:' . max(8, min(100, $height)) . '%"></span>';
        }
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
