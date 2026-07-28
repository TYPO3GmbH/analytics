<?php

declare(strict_types=1);

namespace T3G\Analytics\EventListener;

use T3G\Analytics\Service\PagePerformanceBarBuilder;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

final readonly class PagePerformanceBarListener
{
    public function __construct(
        private PageRenderer $pageRenderer,
        private AssetCollector $assetCollector,
        private PagePerformanceBarBuilder $builder,
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

        if ((int)($queryParams['viewMode'] ?? 0) === 2) {
            return;
        }

        $languagesParam = $queryParams['languages'] ?? [];
        $languageId = is_array($languagesParam) ? (int)($languagesParam[0] ?? 0) : 0;
        $days = $this->builder->normalizeDays((int)($queryParams['tx_analytics_period'] ?? 7));

        $this->pageRenderer->addCssFile('EXT:analytics/Resources/Public/Css/AnalyticsColors.css');
        $this->pageRenderer->addCssFile('EXT:analytics/Resources/Public/Css/PagePerformance.css');
        $this->pageRenderer->addCssFile('EXT:analytics/Resources/Public/Css/Components/Sparkline.css');
        $this->pageRenderer->addJsFooterFile('EXT:analytics/Resources/Public/JavaScript/page-performance.js');
        $this->assetCollector->addInlineStyleSheet(
            'analytics-page-performance-icons',
            $this->renderIconVariables(),
        );

        $event->addHeaderContent(
            $this->builder->buildSkeletonHtml($pageId, $languageId, $days)
        );
    }

    private function renderIconVariables(): string
    {
        $icons = [
            'arrow-right-from-bracket',
            'arrow-trend-down',
            'arrow-trend-up',
            'calendar-days',
            'circle-plus',
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
            $css .= '.tx-analytics-performance-icon-' . $icon . '{--tx-analytics-performance-icon:url("' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '");}';
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
}
