<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use T3G\Analytics\Dashboard\DashboardPeriods;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Renders the page performance bar in the page module with the templates in
 * Resources/Private/Templates/PagePerformance.
 */
final readonly class PagePerformanceBarRenderer
{
    public function __construct(
        private ViewFactoryInterface $viewFactory,
    ) {
    }

    /**
     * Placeholder that the JavaScript of the bar replaces with the bar.
     */
    public function renderSkeleton(int $pageId, int $languageId, int $days): string
    {
        return $this->render('PagePerformance/Skeleton', [
            'pageId' => $pageId,
            'languageId' => $languageId,
            'days' => $days,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $metrics See PagePerformanceDataProvider::getMetrics()
     * @param array<string, mixed> $queryParams Parameters of the page module that the period form keeps
     */
    public function renderBar(int $pageId, int $languageId, int $days, array $metrics, array $queryParams, string $detailsUri): string
    {
        return $this->render('PagePerformance/Bar', [
            'pageId' => $pageId,
            'languageId' => $languageId,
            'metrics' => $metrics,
            'detailsId' => 'tx-analytics-performance-details-' . $pageId,
            'expanded' => $this->isDetailsExpanded(),
            'periods' => array_map(
                static fn (int $period): array => ['value' => $period, 'selected' => $period === $days],
                DashboardPeriods::periods()
            ),
            'hiddenFields' => $this->hiddenQueryFields($queryParams, $pageId),
            'detailsUri' => $detailsUri,
        ]);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $template, array $variables): string
    {
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Templates')],
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Partials')],
        ));
        $view->assignMultiple($variables);
        return trim($view->render($template));
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, string>
     */
    private function hiddenQueryFields(array $queryParams, int $pageId): array
    {
        $fields = ['id' => (string)$pageId];
        foreach ($queryParams as $name => $value) {
            if ($name === 'id' || $name === 'tx_analytics_period') {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (is_scalar($v)) {
                        $fields[$name . '[' . $k . ']'] = (string)$v;
                    }
                }
            } elseif (is_scalar($value)) {
                $fields[$name] = (string)$value;
            }
        }
        return $fields;
    }

    private function isDetailsExpanded(): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        return (bool)($backendUser->uc['tx_analytics']['pagePerformanceExpanded'] ?? false);
    }
}
