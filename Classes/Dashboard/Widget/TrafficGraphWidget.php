<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use T3G\Analytics\View\SparklineRenderer;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

final readonly class TrafficGraphWidget implements WidgetInterface, AdditionalCssInterface, JavaScriptInterface
{
    private const DUMMY_DATA = [520, 610, 580, 640, 720, 690, 750, 800, 780, 840, 820, 880, 870, 935, 905, 965, 945, 1010, 990, 1055, 1025, 1085, 1065, 1145, 1125, 1205, 1285, 1355];
    private const Y_MIN = 0;
    private const Y_MAX = 1800;
    private const Y_LABELS = [['value' => 1800, 'label' => '1.8k'], ['value' => 1200, 'label' => '1.2k'], ['value' => 600, 'label' => '600'], ['value' => 0, 'label' => '0']];
    private const X_LABELS = ['15.4.', '22.4.', '29.4.', '6.5.', '12.5.'];

    /** @var array{site?: string, refreshAvailable?: bool} */
    private array $options;

    /**
     * @param array{site?: string, refreshAvailable?: bool} $options
     */
    public function __construct(
        WidgetConfigurationInterface $configuration,
        private SparklineRenderer $sparklineRenderer,
        private SiteFinder $siteFinder,
        array $options = [],
    ) {
        $this->options = array_replace(
            [
                'site' => '',
                'refreshAvailable' => true,
            ],
            $options
        );
    }

    public function renderWidgetContent(): string
    {
        return $this->renderContent((string)$this->options['site']);
    }

    /**
     * @return array{site?: string, refreshAvailable?: bool}
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return [
            'EXT:analytics/Resources/Public/Css/Components/Sparkline.css',
            'EXT:analytics/Resources/Public/Css/TrafficGraph.css',
        ];
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
        $uid = substr(sha1($siteIdentifier), 0, 8);

        $html = '<div class="tx-analytics-traffic-graph" data-site="' . $this->escape($siteIdentifier) . '">';
        $html .= $this->renderHeader($siteIdentifier, $uid);
        $html .= $this->renderChart();
        $html .= '</div>';

        return $html;
    }

    private function renderHeader(string $siteIdentifier, string $uid): string
    {
        $siteSelect = $this->renderSiteSelect($siteIdentifier, $uid);

        $html = '<div class="tx-analytics-traffic-graph-header">';
        $html .= '<a href="#" class="tx-analytics-traffic-graph-analyse-link">';
        $html .= '<span>' . $this->escape($this->translate('dashboardWidget.trafficGraph.analyse')) . '</span>';
        $html .= '<span class="tx-analytics-traffic-graph-icon tx-analytics-traffic-graph-icon-arrow-up-right-from-square" aria-hidden="true"></span>';
        $html .= '</a>';
        $html .= $siteSelect;
        $html .= '</div>';

        return $html;
    }

    private function renderSiteSelect(string $siteIdentifier, string $uid): string
    {
        $options = $this->siteOptions();
        if (count($options) <= 1) {
            return '';
        }

        $selectId = 'tx-analytics-traffic-graph-site-' . $uid;
        $html = '<div class="tx-analytics-traffic-graph-toolbar">';
        $html .= '<label class="form-label tx-analytics-traffic-graph-site-label" for="' . $this->escape($selectId) . '">' . $this->escape($this->translate('dashboardWidget.trafficGraph.setting.site.label')) . '</label>';
        $html .= '<select id="' . $this->escape($selectId) . '" class="form-select form-select-sm tx-analytics-traffic-graph-site-select">';
        foreach ($options as $identifier => $label) {
            $selected = $identifier === $siteIdentifier ? ' selected' : '';
            $html .= '<option value="' . $this->escape($identifier) . '"' . $selected . '>' . $this->escape($label) . '</option>';
        }
        $html .= '</select></div>';

        return $html;
    }

    private function renderChart(): string
    {
        $sparkline = $this->sparklineRenderer->render(self::DUMMY_DATA, [
            'label' => $this->translate('dashboardWidget.trafficGraph.chartLabel'),
            'class' => 'tx-analytics-traffic-graph-sparkline',
            'yMin' => self::Y_MIN,
            'yMax' => self::Y_MAX,
            'gridLines' => array_column(self::Y_LABELS, 'value'),
            'showLastPoint' => false,
            'preserveAspectRatio' => 'none',
        ]);

        $html = '<div class="tx-analytics-traffic-graph-chart-wrap">';

        $html .= '<div class="tx-analytics-traffic-graph-y-axis" aria-hidden="true">';
        foreach (self::Y_LABELS as $label) {
            $html .= '<span class="tx-analytics-traffic-graph-y-label">' . $this->escape($label['label']) . '</span>';
        }
        $html .= '</div>';

        $html .= '<div class="tx-analytics-traffic-graph-chart-area">' . $sparkline . '</div>';

        $html .= '<div class="tx-analytics-traffic-graph-x-axis" aria-hidden="true">';
        foreach (self::X_LABELS as $label) {
            $html .= '<span>' . $this->escape($label) . '</span>';
        }
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * @return array<string, string>
     */
    private function siteOptions(): array
    {
        $options = ['' => $this->translate('dashboardWidget.trafficGraph.setting.site.allSites')];
        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $siteTitle = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));
                $options[$site->getIdentifier()] = $siteTitle === ''
                    ? $site->getIdentifier()
                    : $siteTitle . ' (' . $site->getIdentifier() . ')';
            }
        } catch (\Throwable) {
            return $options;
        }

        return $options;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function translate(string $key): string
    {
        $label = $this->getLanguageService()->sL(
            'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:' . $key
        );

        return $label === '' ? $key : $label;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
