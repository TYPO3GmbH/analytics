<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use T3G\Analytics\Service\SiteDataProvider;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;

final readonly class TrafficSourcesWidget implements WidgetRendererInterface, AdditionalCssInterface, JavaScriptInterface
{
    public function __construct(
        WidgetConfigurationInterface $configuration,
        private SiteDataProvider $siteDataProvider,
    ) {
    }

    /**
     * @return SettingDefinition[]
     */
    public function getSettingsDefinitions(): array
    {
        return [
            new SettingDefinition(
                key: 'site',
                type: 'string',
                default: '',
                label: 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:dashboardWidget.trafficSources.setting.site.label',
                description: 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:dashboardWidget.trafficSources.setting.site.description',
                enum: $this->siteOptions(),
            ),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        return new WidgetResult(
            content: $this->renderContent((string)$context->settings->get('site')),
            refreshable: true,
        );
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
            ]
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

    /**
     * @param list<array{label: string, value: int, tone: string, icon?: string}> $items
     */
    private function renderSection(string $icon, string $title, array $items): string
    {
        $html = '<section class="tx-analytics-traffic-sources-section" aria-label="' . $this->escape($title) . '">';
        $html .= '<h3 class="tx-analytics-traffic-sources-heading">';
        $html .= '<span class="tx-analytics-traffic-sources-icon tx-analytics-traffic-sources-icon-' . $this->escape($icon) . '" aria-hidden="true"></span>';
        $html .= '<span>' . $this->escape($title) . '</span></h3>';
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
            foreach ($this->siteDataProvider->registeredSiteOptions() as $site) {
                $options[$site['identifier']] = $site['label'];
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
