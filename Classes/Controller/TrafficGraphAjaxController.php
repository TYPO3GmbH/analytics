<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use T3G\Analytics\Dashboard\DashboardPeriods;
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
        $requestedDays = (int) ($params['days'] ?? 30);
        $days = DashboardPeriods::sanitizePeriod($requestedDays);
        $hiddenKeys = array_values(array_filter(array_map('strval', (array)($params['hidden'] ?? []))));

        ['metricData' => $metricData, 'chart' => $chart] = $this->chartDataBuilder->buildForTrafficGraph(
            $this->trafficGraphService,
            $siteIdentifier,
            $days,
            [
                'visits' => $this->translate('dashboardWidget.trafficGraph.chartLabel.visits'),
                'sessions' => $this->translate('dashboardWidget.trafficGraph.chartLabel.sessions'),
                'visitors_new' => $this->translate('dashboardWidget.trafficGraph.chartLabel.visitors.new'),
                'visitors_returning' => $this->translate('dashboardWidget.trafficGraph.chartLabel.visitors.returning'),
                'visitors_overall' => $this->translate('dashboardWidget.trafficGraph.chartLabel.visitors.overall'),
            ],
            $hiddenKeys,
        );

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Templates')],
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Partials')],
        ));
        $view->assignMultiple([
            'chart' => $chart,
            'noData' => array_filter($metricData, static fn (?array $d): bool => $d !== null && array_sum($d['data']) > 0) === [],
            'currentPeriodLabel' => sprintf($this->translate('pagePerformance.days'), $days),
        ]);
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
