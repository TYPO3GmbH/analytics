<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;

trait TopPagesWidgetTrait
{
    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return ['EXT:analytics/Resources/Public/Css/TopPages.css'];
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

    /**
     * @return list<array{position: int, url: string, title: string, visitCount: string, visitPercentOfTotal: string, trend: string, trendDirection: string, trendLabel: string}>
     */
    private function buildPages(string $siteIdentifier, int $days, int $limit): array
    {
        $pages = $this->topPagesService->loadTopPagesData($siteIdentifier, $days, $limit);
        $trendLabel = $this->translate('dashboardWidget.topPages.comparedToPreviousPeriod');
        return $pages !== null ? $this->topPagesService->buildPageItems($pages, $trendLabel) : [];
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
