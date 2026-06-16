<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use T3G\Analytics\Service\SitePerformanceServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

final readonly class SitePerformanceAjaxController
{
    public function __construct(
        private SitePerformanceServiceInterface $sitePerformanceService,
        private UriBuilder $uriBuilder,
        private ViewFactoryInterface $viewFactory,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $siteIdentifier = (string)($params['site'] ?? '');
        $days = max(1, (int)($params['days'] ?? 7));

        $data = $this->sitePerformanceService->loadPerformanceData($siteIdentifier, $days);
        $metrics = $data !== null ? $this->sitePerformanceService->buildMetricItems(
            $data,
            $this->translate('dashboardWidget.sitePerformance.visits'),
            $this->translate('dashboardWidget.sitePerformance.visitors'),
            $this->translate('dashboardWidget.sitePerformance.bounceRate'),
            $this->translate('dashboardWidget.sitePerformance.averageVisitDuration'),
            $this->translate('dashboardWidget.sitePerformance.comparedToPreviousPeriod'),
        ) : [];

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Templates')],
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Partials')],
        ));
        $view->assign('metrics', $metrics);
        $html = $view->render('Dashboard/Widget/SitePerformanceMetrics');

        $showAllUrl = $siteIdentifier !== ''
            ? (string)$this->uriBuilder->buildUriFromRoute('site_analytics.dashboard', ['siteIdentifier' => $siteIdentifier, 'days' => $days])
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
