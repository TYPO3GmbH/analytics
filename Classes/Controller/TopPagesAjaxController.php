<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use T3G\Analytics\Dashboard\DashboardPeriods;
use T3G\Analytics\Service\TopPagesServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

final readonly class TopPagesAjaxController
{
    public function __construct(
        private TopPagesServiceInterface $topPagesService,
        private UriBuilder $uriBuilder,
        private ViewFactoryInterface $viewFactory,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $siteIdentifier = (string)($params['site'] ?? '');
        $requestedDays = (int)($params['days'] ?? 7);
        $days = DashboardPeriods::sanitizePeriod($requestedDays);

        $pages = $this->topPagesService->loadTopPagesData($siteIdentifier, $days);
        $trendLabel = $this->translate('dashboardWidget.topPages.comparedToPreviousPeriod');
        $pages = $pages !== null ? $this->topPagesService->buildPageItems($pages, $trendLabel) : [];

        $showAllUrl = $siteIdentifier !== ''
            ? (string)$this->uriBuilder->buildUriFromRoute('site_analytics.dashboard', ['siteIdentifier' => $siteIdentifier, 'days' => $days, 'dashboardPath' => 'dashboard/pages'])
            : '';

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Templates')],
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Partials')],
        ));
        $view->assignMultiple([
            'pages' => $pages,
            'noData' => $pages === [],
            'currentPeriodLabel' => sprintf($this->translate('pagePerformance.days'), $days),
        ]);
        $html = $view->render('Dashboard/Widget/TopPagesList');

        return new JsonResponse(['status' => 'ok', 'html' => $html, 'showAllUrl' => $showAllUrl]);
    }

    private function translate(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return $key;
        }
        $label = $languageService->sL('LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:' . $key);
        return $label !== '' ? $label : $key;
    }
}
