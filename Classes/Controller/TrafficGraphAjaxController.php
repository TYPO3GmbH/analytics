<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use T3G\Analytics\Service\TrafficChartDataBuilder;
use T3G\Analytics\Service\TrafficGraphServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

final readonly class TrafficGraphAjaxController
{
    public function __construct(
        private TrafficGraphServiceInterface $trafficGraphService,
        private TrafficChartDataBuilder $chartDataBuilder,
        private UriBuilder $uriBuilder,
        private ViewFactoryInterface $viewFactory,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $siteIdentifier = (string) ($params['site'] ?? '');
        // Default matches the widget's configured default (30 days); JS always sends the value
        // explicitly, so this fallback is only reached in direct/test requests.
        $days = max(1, (int) ($params['days'] ?? 30));

        $graphData = $this->trafficGraphService->loadGraphData($siteIdentifier, $days);
        $chart = $this->chartDataBuilder->build($graphData, $this->translate('dashboardWidget.trafficGraph.chartLabel'));

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Templates')],
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Partials')],
        ));
        $view->assign('chart', $chart);
        $html = $view->render('Dashboard/Widget/TrafficGraphChart');

        $showAllUrl = $siteIdentifier !== ''
            ? (string) $this->uriBuilder->buildUriFromRoute('site_analytics.dashboard', ['siteIdentifier' => $siteIdentifier, 'days' => $days])
            : '';

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
