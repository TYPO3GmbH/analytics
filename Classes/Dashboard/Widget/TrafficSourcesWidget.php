<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

final readonly class TrafficSourcesWidget implements WidgetInterface, AdditionalCssInterface, JavaScriptInterface
{
    /** @var array{site?: string, refreshAvailable?: bool} */
    private array $options;

    /**
     * @param array{site?: string, refreshAvailable?: bool} $options
     */
    public function __construct(
        WidgetConfigurationInterface $configuration,
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
            'EXT:analytics/Resources/Public/Css/TrafficSources.css',
        ];
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
        $html = '<div class="tx-analytics-traffic-sources" data-site="' . $this->escape($siteIdentifier) . '">';
        $html .= $this->renderSection(
            'earth-europe',
            $this->translate('dashboardWidget.trafficSources.sources'),
            [
                ['label' => $this->translate('dashboardWidget.trafficSources.source.organic'), 'value' => 42, 'tone' => 'blue'],
                ['label' => $this->translate('dashboardWidget.trafficSources.source.direct'), 'value' => 31, 'tone' => 'green'],
                ['label' => $this->translate('dashboardWidget.trafficSources.source.referral'), 'value' => 14, 'tone' => 'purple'],
                ['label' => $this->translate('dashboardWidget.trafficSources.source.social'), 'value' => 8, 'tone' => 'orange'],
                ['label' => $this->translate('dashboardWidget.trafficSources.source.email'), 'value' => 5, 'tone' => 'gray'],
            ],
            $this->renderSiteSelect($siteIdentifier)
        );
        $html .= $this->renderSection(
            'display',
            $this->translate('dashboardWidget.trafficSources.devices'),
            [
                ['label' => $this->translate('dashboardWidget.trafficSources.device.desktop'), 'value' => 62, 'tone' => 'blue', 'icon' => 'display'],
                ['label' => $this->translate('dashboardWidget.trafficSources.device.mobile'), 'value' => 31, 'tone' => 'green', 'icon' => 'mobile'],
                ['label' => $this->translate('dashboardWidget.trafficSources.device.tablet'), 'value' => 7, 'tone' => 'gray', 'icon' => 'tablet'],
            ]
        );
        $html .= '</div>';

        return $html;
    }

    private function renderSiteSelect(string $siteIdentifier): string
    {
        $options = $this->siteOptions();
        if (count($options) <= 1) {
            return '';
        }

        $selectId = 'tx-analytics-traffic-sources-site-' . substr(sha1($siteIdentifier . implode('', array_keys($options))), 0, 8);
        $html = '<div class="tx-analytics-traffic-sources-toolbar">';
        $html .= '<label class="form-label tx-analytics-traffic-sources-site-label" for="' . $this->escape($selectId) . '">' . $this->escape($this->translate('dashboardWidget.trafficSources.setting.site.label')) . '</label>';
        $html .= '<select id="' . $this->escape($selectId) . '" class="form-select form-select-sm tx-analytics-traffic-sources-site-select">';
        foreach ($options as $identifier => $label) {
            $selected = $identifier === $siteIdentifier ? ' selected' : '';
            $html .= '<option value="' . $this->escape($identifier) . '"' . $selected . '>' . $this->escape($label) . '</option>';
        }
        $html .= '</select></div>';

        return $html;
    }

    /**
     * @param list<array{label: string, value: int, tone: string, icon?: string}> $items
     */
    private function renderSection(string $icon, string $title, array $items, string $toolbar = ''): string
    {
        $html = '<section class="tx-analytics-traffic-sources-section" aria-label="' . $this->escape($title) . '">';
        $html .= '<div class="tx-analytics-traffic-sources-section-header">';
        $html .= '<h3 class="tx-analytics-traffic-sources-heading">';
        $html .= '<span class="tx-analytics-traffic-sources-icon tx-analytics-traffic-sources-icon-' . $this->escape($icon) . '" aria-hidden="true"></span>';
        $html .= '<span>' . $this->escape($title) . '</span></h3>';
        $html .= $toolbar;
        $html .= '</div>';
        $html .= '<ul class="tx-analytics-traffic-sources-list">';
        foreach ($items as $item) {
            $value = max(0, min(100, $item['value']));
            $itemIcon = $item['icon'] ?? null;
            $html .= '<li class="tx-analytics-traffic-sources-row tx-analytics-traffic-sources-tone-' . $this->escape($item['tone']) . '">';
            $html .= '<span class="tx-analytics-traffic-sources-label">';
            if ($itemIcon !== null) {
                $html .= '<span class="tx-analytics-traffic-sources-device-icon tx-analytics-traffic-sources-icon-' . $this->escape($itemIcon) . '" aria-hidden="true"></span>';
            } else {
                $html .= '<span class="tx-analytics-traffic-sources-dot" aria-hidden="true"></span>';
            }
            $html .= '<span>' . $this->escape($item['label']) . '</span></span>';
            $html .= '<typo3-backend-progress-bar class="tx-analytics-traffic-sources-bar" value="' . $value . '" max="100"></typo3-backend-progress-bar>';
            $html .= '<span class="tx-analytics-traffic-sources-value">' . $value . '%</span>';
            $html .= '</li>';
        }
        $html .= '</ul></section>';

        return $html;
    }

    /**
     * @return array<string, string>
     */
    private function siteOptions(): array
    {
        $options = [
            '' => $this->translate('dashboardWidget.trafficSources.setting.site.allSites'),
        ];

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
