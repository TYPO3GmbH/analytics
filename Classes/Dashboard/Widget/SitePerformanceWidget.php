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

final readonly class SitePerformanceWidget implements WidgetInterface, AdditionalCssInterface, JavaScriptInterface
{
    private const DUMMY_SITE_PERFORMANCE_RESPONSE = [
        'payload' => [
            'current' => [
                'pageViewCount' => 34820,
                'visitCount' => 12340,
                'bounceRate' => 54.0,
                'averageVisitDuration' => 138,
            ],
            'previous' => [
                'pageViewCount' => 31945,
                'visitCount' => 11642,
                'bounceRate' => 54.0,
                'averageVisitDuration' => 124,
            ],
        ],
    ];

    /** @var array{site?: string, refreshAvailable?: bool} */
    private array $options;

    /**
     * @param array{site?: string, refreshAvailable?: bool} $options
     */
    public function __construct(
        private WidgetConfigurationInterface $configuration,
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
            'EXT:analytics/Resources/Public/Css/SitePerformance.css',
        ];
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
        $siteOptions = $this->siteOptions();
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Templates')],
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Partials')],
            layoutRootPaths: [
                GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Layouts'),
                GeneralUtility::getFileAbsFileName('EXT:dashboard/Resources/Private/Layouts'),
            ],
        ));
        $view->assignMultiple([
            'siteIdentifier' => $siteIdentifier,
            'siteSelectId' => 'tx-analytics-site-performance-site-' . substr(sha1($this->configuration->getIdentifier() . $siteIdentifier . implode('', array_keys($siteOptions))), 0, 8),
            'siteLabel' => $this->translate('dashboardWidget.sitePerformance.setting.site.label'),
            'showSiteSelect' => count($siteOptions) > 1,
            'siteOptions' => $this->buildSiteOptions($siteOptions, $siteIdentifier),
            'metrics' => $this->buildMetrics(self::DUMMY_SITE_PERFORMANCE_RESPONSE['payload']),
        ]);

        return $view->render('Dashboard/Widget/SitePerformance');
    }

    /**
     * @param array{
     *     current: array{pageViewCount: int, visitCount: int, bounceRate: float, averageVisitDuration: int},
     *     previous: array{pageViewCount: int, visitCount: int, bounceRate: float, averageVisitDuration: int}
     * } $payload
     * @return list<array{label: string, value: string, tone: string, icon: string, trend: string, trendDirection: string, trendLabel: string}>
     */
    private function buildMetrics(array $payload): array
    {
        $current = $payload['current'];
        $previous = $payload['previous'];

        return [
            [
                'label' => $this->translate('dashboardWidget.sitePerformance.pageViews'),
                'value' => $this->formatNumber($current['pageViewCount']),
                'tone' => 'primary',
                'icon' => 'eye',
                'trend' => $this->formatRelativeTrend((float)$current['pageViewCount'], (float)$previous['pageViewCount']),
                'trendDirection' => $this->trendDirection((float)$current['pageViewCount'], (float)$previous['pageViewCount']),
                'trendLabel' => $this->translate('dashboardWidget.sitePerformance.comparedToPreviousPeriod'),
            ],
            [
                'label' => $this->translate('dashboardWidget.sitePerformance.visitors'),
                'value' => $this->formatNumber($current['visitCount']),
                'tone' => 'success',
                'icon' => 'circle-plus',
                'trend' => $this->formatRelativeTrend((float)$current['visitCount'], (float)$previous['visitCount']),
                'trendDirection' => $this->trendDirection((float)$current['visitCount'], (float)$previous['visitCount']),
                'trendLabel' => $this->translate('dashboardWidget.sitePerformance.comparedToPreviousPeriod'),
            ],
            [
                'label' => $this->translate('dashboardWidget.sitePerformance.bounceRate'),
                'value' => $this->formatPercentage($current['bounceRate']),
                'tone' => 'danger',
                'icon' => 'arrow-right-from-bracket',
                'trend' => $this->formatRelativeTrend($current['bounceRate'], $previous['bounceRate']),
                'trendDirection' => $this->trendDirection($previous['bounceRate'], $current['bounceRate']),
                'trendLabel' => $this->translate('dashboardWidget.sitePerformance.comparedToPreviousPeriod'),
            ],
            [
                'label' => $this->translate('dashboardWidget.sitePerformance.averageVisitDuration'),
                'value' => $this->formatDuration($current['averageVisitDuration']),
                'tone' => 'info',
                'icon' => 'clock',
                'trend' => $this->formatRelativeTrend((float)$current['averageVisitDuration'], (float)$previous['averageVisitDuration']),
                'trendDirection' => $this->trendDirection((float)$current['averageVisitDuration'], (float)$previous['averageVisitDuration']),
                'trendLabel' => $this->translate('dashboardWidget.sitePerformance.comparedToPreviousPeriod'),
            ],
        ];
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
     * @return array<string, string>
     */
    private function siteOptions(): array
    {
        $options = [
            '' => $this->translate('dashboardWidget.sitePerformance.setting.site.allSites'),
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

    private function formatNumber(int $value): string
    {
        return number_format($value, 0, '.', '.');
    }

    private function formatPercentage(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') . '%';
    }

    private function formatDuration(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private function formatRelativeTrend(float $currentValue, float $previousValue): string
    {
        if ($previousValue === 0.0) {
            return '';
        }

        return $this->formatSignedNumber((($currentValue - $previousValue) / $previousValue) * 100) . '%';
    }

    private function trendDirection(float $currentValue, float $previousValue): string
    {
        if ($currentValue === $previousValue) {
            return 'neutral';
        }

        return $currentValue > $previousValue ? 'up' : 'down';
    }

    private function formatSignedNumber(float $value): string
    {
        $formattedValue = rtrim(rtrim(number_format(abs($value), 1, '.', ''), '0'), '.');

        if ($formattedValue === '0') {
            return '±0';
        }

        return ($value >= 0 ? '+' : '-') . $formattedValue;
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
