<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use T3G\Analytics\Dashboard\Widget\TrafficSourcesItemsTrait;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\MetricFormatterInterface;
use T3G\Analytics\Service\TrafficSourcesServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

final readonly class TrafficSourcesAjaxController
{
    use TrafficSourcesItemsTrait;
    public function __construct(
        private TrafficSourcesServiceInterface $trafficSourcesService,
        private AnalyticsSiteProviderInterface $siteProvider,
        private MetricFormatterInterface $formatter,
        private UriBuilder $uriBuilder,
        private ViewFactoryInterface $viewFactory,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $siteIdentifier = (string)($params['site'] ?? '');
        $days = max(1, (int)($params['days'] ?? 30));

        if ($siteIdentifier === '') {
            $siteOptions = $this->siteProvider->siteOptions();
            if ($siteOptions !== []) {
                $siteIdentifier = array_key_first($siteOptions);
            }
        }

        $trafficSources = $siteIdentifier !== ''
            ? ($this->trafficSourcesService->loadTrafficSources($siteIdentifier, $days) ?? [])
            : [];

        $sections = [
            $this->buildSection(
                'earth-europe',
                $this->translate('dashboardWidget.trafficSources.sources'),
                $this->buildTrafficSourceItems($trafficSources),
            ),
            $this->buildSection(
                'display',
                $this->translate('dashboardWidget.trafficSources.devices'),
                $this->buildDeviceItems($this->trafficSourcesService->loadDeviceData($siteIdentifier, $days) ?? []),
            ),
            $this->buildSection(
                'browser',
                $this->translate('dashboardWidget.trafficSources.browsers'),
                $this->buildBrowserItems($this->trafficSourcesService->loadBrowserData($siteIdentifier, $days) ?? []),
            ),
            $this->buildSection(
                'earth-europe',
                $this->translate('dashboardWidget.trafficSources.countries'),
                $this->buildCountryItems($this->trafficSourcesService->loadCountryData($siteIdentifier, $days) ?? []),
            ),
        ];

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Templates')],
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Partials')],
        ));
        $view->assign('sections', $sections);
        $html = $view->render('Dashboard/Widget/TrafficSourcesSections');

        $showAllUrl = $siteIdentifier !== ''
            ? (string)$this->uriBuilder->buildUriFromRoute('site_analytics.dashboard', [
                'siteIdentifier' => $siteIdentifier,
                'days' => $days,
                'dashboardPath' => 'traffic/share',
            ])
            : '';

        return new JsonResponse(['status' => 'ok', 'html' => $html, 'showAllUrl' => $showAllUrl]);
    }

    /**
     * @param list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}> $items
     * @return array{icon: string, title: string, showSiteSelect: bool, isDonut: bool, chartSvg: string, items: list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}>}
     */
    private function buildSection(string $icon, string $title, array $items): array
    {
        return ['icon' => $icon, 'title' => $title, 'showSiteSelect' => false, 'isDonut' => false, 'chartSvg' => '', 'items' => $items];
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
