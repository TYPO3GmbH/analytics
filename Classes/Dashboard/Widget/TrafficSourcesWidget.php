<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
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
        private ViewFactoryInterface $viewFactory,
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
        $trafficSourcesResponse = [
            'points' => [
                [
                    'channelType' => 'direct',
                    'values' => [
                        ['value' => 388],
                    ],
                ],
                [
                    'channelType' => 'search',
                    'values' => [
                        ['value' => 525],
                    ],
                ],
                [
                    'channelType' => 'social',
                    'values' => [
                        ['value' => 100],
                    ],
                ],
                [
                    'channelType' => 'email',
                    'values' => [
                        ['value' => 62],
                    ],
                ],
                [
                    'channelType' => 'paid',
                    'values' => [
                        ['value' => 88],
                    ],
                ],
                [
                    'channelType' => 'unknown',
                    'values' => [
                        ['value' => 54],
                    ],
                ],
                [
                    'channelType' => 'ai_traffic',
                    'values' => [
                        ['value' => 33],
                    ],
                ],
            ],
        ];
        $devicesResponse = [
            'payload' => [
                ['deviceType' => 'desktop', 'sessionCount' => 775, 'sessionPercentOfTotal' => 62.0],
                ['deviceType' => 'mobile', 'sessionCount' => 388, 'sessionPercentOfTotal' => 31.0],
                ['deviceType' => 'tablet', 'sessionCount' => 87, 'sessionPercentOfTotal' => 7.0],
            ],
        ];

        $siteOptions = $this->siteOptions();
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Templates')],
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Partials')],
            layoutRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Layouts')],
        ));
        $view->assignMultiple([
            'siteIdentifier' => $siteIdentifier,
            'siteSelectId' => 'tx-analytics-traffic-sources-site-' . substr(sha1($siteIdentifier . implode('', array_keys($siteOptions))), 0, 8),
            'siteLabel' => $this->translate('dashboardWidget.trafficSources.setting.site.label'),
            'siteOptions' => $this->buildSiteOptions($siteOptions, $siteIdentifier),
            'sections' => [
                [
                    'icon' => 'earth-europe',
                    'title' => $this->translate('dashboardWidget.trafficSources.sources'),
                    'showSiteSelect' => count($siteOptions) > 1,
                    'items' => $this->buildTrafficSourceItems($trafficSourcesResponse['points']),
                ],
                [
                    'icon' => 'display',
                    'title' => $this->translate('dashboardWidget.trafficSources.devices'),
                    'showSiteSelect' => false,
                    'items' => $this->buildDeviceItems($devicesResponse['payload']),
                ],
            ],
        ]);

        return $view->render('Dashboard/Widget/TrafficSources');
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
     * @param list<array{channelType: string, values: list<array{value: int}>}> $points
     * @return list<array{label: string, value: int, tone: string, icon: string}>
     */
    private function buildTrafficSourceItems(array $points): array
    {
        $tones = [
            'direct' => 'green',
            'search' => 'blue',
            'social' => 'orange',
            'email' => 'gray',
            'paid' => 'purple',
            'unknown' => 'gray',
            'ai_traffic' => 'purple',
        ];

        $totalVisitCount = array_sum(array_map(
            static fn (array $point): int => array_sum(array_column($point['values'], 'value')),
            $points,
        ));

        $items = [];
        foreach ($points as $point) {
            $source = $point['channelType'];
            $visitCount = array_sum(array_column($point['values'], 'value'));
            $items[] = [
                'label' => $this->translate('dashboardWidget.trafficSources.source.' . $source),
                'value' => $this->percentage($visitCount, $totalVisitCount),
                'tone' => $tones[$source] ?? 'gray',
                'icon' => '',
            ];
        }

        return $items;
    }

    /**
     * @param list<array{deviceType: string, sessionCount: int, sessionPercentOfTotal: int|float}> $payload
     * @return list<array{label: string, value: int, tone: string, icon: string}>
     */
    private function buildDeviceItems(array $payload): array
    {
        $tones = [
            'desktop' => 'blue',
            'mobile' => 'green',
            'tablet' => 'gray',
        ];
        $icons = [
            'desktop' => 'display',
            'mobile' => 'mobile',
            'tablet' => 'tablet',
        ];

        $totalVisitCount = array_sum(array_column($payload, 'sessionCount'));

        $items = [];
        foreach ($payload as $item) {
            $deviceType = $item['deviceType'];
            $items[] = [
                'label' => $this->translate('dashboardWidget.trafficSources.device.' . $deviceType),
                'value' => $this->percentage($item['sessionCount'], $totalVisitCount),
                'tone' => $tones[$deviceType] ?? 'gray',
                'icon' => $icons[$deviceType] ?? '',
            ];
        }

        return $items;
    }

    private function percentage(int $value, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return max(0, min(100, (int)round($value / $total * 100)));
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
