<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;

trait TrafficSourcesWidgetTrait
{
    use TrafficSourcesItemsTrait;

    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return [
            'EXT:analytics/Resources/Public/Css/AnalyticsColors.css',
            'EXT:analytics/Resources/Public/Css/TrafficSources.css',
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
     * @return list<array{deviceType: string, sessionCount: int, sessionPercentOfTotal: int|float, previousSessionCount?: int}>
     */
    private function loadDevices(string $siteIdentifier, int $days): array
    {
        return $this->trafficSourcesService->loadDeviceData($siteIdentifier, $days) ?? [];
    }

    /**
     * @return list<array{browserName: string, sessionCount: int, sessionPercentOfTotal: int|float, previousSessionCount?: int}>
     */
    private function loadBrowsers(string $siteIdentifier, int $days): array
    {
        return $this->trafficSourcesService->loadBrowserData($siteIdentifier, $days) ?? [];
    }

    /**
     * @return list<array{countryCode: string, sessionCount: int, sessionPercentOfTotal: int|float, previousSessionCount?: int}>
     */
    private function loadCountries(string $siteIdentifier, int $days): array
    {
        return $this->trafficSourcesService->loadCountryData($siteIdentifier, $days) ?? [];
    }

    private function buildDashboardUrl(string $siteIdentifier, int $days, string $dashboardPath): string
    {
        if ($siteIdentifier === '') {
            return '';
        }
        return (string)$this->uriBuilder->buildUriFromRoute(
            'site_analytics.dashboard',
            ['siteIdentifier' => $siteIdentifier, 'days' => $days, 'dashboardPath' => $dashboardPath]
        );
    }

    /**
     * @return list<array{value: int, label: string, selected: bool}>
     */
    private function buildPeriodOptions(int $selectedDays): array
    {
        $options = [];
        foreach (\T3G\Analytics\Dashboard\DashboardPeriods::periods() as $period) {
            $options[] = [
                'value' => $period,
                'label' => sprintf($this->translate('pagePerformance.days'), $period),
                'selected' => $period === $selectedDays,
            ];
        }
        return $options;
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
