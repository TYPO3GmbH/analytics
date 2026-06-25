<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use Psr\Http\Message\ServerRequestInterface;
use T3G\Analytics\Dashboard\DashboardPeriods;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;

trait SitePerformanceWidgetTrait
{
    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return [
            'EXT:analytics/Resources/Public/Css/AnalyticsColors.css',
            'EXT:analytics/Resources/Public/Css/SitePerformance.css',
        ];
    }

    /**
     * @param array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int}
     * } $data
     * @return list<array{label: string, value: string, tone: string, icon: string, trend: string, trendDirection: string, trendLabel: string}>
     */
    private function buildMetrics(array $data): array
    {
        return $this->sitePerformanceService->buildMetricItems(
            $data,
            $this->translate('dashboardWidget.sitePerformance.visits'),
            $this->translate('dashboardWidget.sitePerformance.visitors'),
            $this->translate('dashboardWidget.sitePerformance.bounceRate'),
            $this->translate('dashboardWidget.sitePerformance.averageVisitDuration'),
            $this->translate('dashboardWidget.sitePerformance.comparedToPreviousPeriod'),
        );
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

    private function buildShowAllUrl(string $siteIdentifier, int $days): string
    {
        return $siteIdentifier !== ''
            ? (string)$this->uriBuilder->buildUriFromRoute(
                'site_analytics.dashboard',
                ['siteIdentifier' => $siteIdentifier, 'days' => $days]
            )
            : '';
    }

    private function createViewFactoryData(?ServerRequestInterface $request = null): ViewFactoryData
    {
        return new ViewFactoryData(
            templateRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Templates')],
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Partials')],
            layoutRootPaths: [
                GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Layouts'),
                GeneralUtility::getFileAbsFileName('EXT:dashboard/Resources/Private/Layouts'),
            ],
            request: $request,
        );
    }

    private function translate(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return $key;
        }
        $label = $languageService->sL(
            'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:' . $key
        );
        return $label !== '' ? $label : $key;
    }
}
