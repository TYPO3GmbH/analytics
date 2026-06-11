<?php

declare(strict_types=1);

namespace T3G\Analytics\EventListener;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\AnalyticsDataClient;
use T3G\Analytics\View\SparklineRenderer;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

final readonly class PagePerformanceBarListener
{
    public function __construct(
        private PageRenderer $pageRenderer,
        private AssetCollector $assetCollector,
        private SparklineRenderer $sparklineRenderer,
        private SiteFinder $siteFinder,
        private UriBuilder $uriBuilder,
        private AnalyticsDataClient $analyticsClient,
        private CipherService $cipherService,
        private LoggerInterface $logger,
        private FrontendInterface $pageAnalyticsCache,
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

        $days = $this->normalizeDays((int)($queryParams['tx_analytics_period'] ?? 7));

        $this->pageRenderer->addCssFile('EXT:analytics/Resources/Public/Css/PagePerformance.css');
        $this->pageRenderer->addCssFile('EXT:analytics/Resources/Public/Css/Components/Sparkline.css');
        $this->pageRenderer->addJsFooterFile('EXT:analytics/Resources/Public/JavaScript/page-performance.js');
        $this->assetCollector->addInlineStyleSheet(
            'analytics-page-performance-icons',
            $this->renderIconVariables(),
        );
        $site = $this->trySite($pageId);
        $languagesParam = $queryParams['languages'] ?? [];
        $languageId = is_array($languagesParam) ? (int)($languagesParam[0] ?? 0) : 0;
        $language = $this->trySiteLanguage($site, $languageId);
        $event->addHeaderContent($this->render($pageId, $site, $language, $days, $queryParams, $this->buildDetailsUri($site, $days)));
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function render(int $pageId, ?Site $site, ?SiteLanguage $language, int $days, array $queryParams, string $detailsUri): string
    {
        $metrics = $this->buildMetrics($pageId, $site, $language, $days);

        $html = '<section class="tx-analytics-performance-bar" aria-label="' . $this->escape($this->translate('pagePerformance.ariaLabel')) . '">';
        foreach ($metrics as $metric) {
            $html .= '<div class="tx-analytics-performance-metric tx-analytics-performance-metric-' . $this->escape($metric['tone']) . '" tabindex="0">';
            $html .= '<div class="tx-analytics-performance-metric-body">';
            $html .= '<span class="tx-analytics-performance-icon tx-analytics-performance-icon-' . $this->escape($metric['icon']) . '" aria-hidden="true"></span>';
            $html .= '<span class="tx-analytics-performance-value">' . $this->escape($metric['value']) . '</span>';
            $html .= '<span class="tx-analytics-performance-label">' . $this->escape($metric['label']) . '</span>';
            if ($metric['trend'] !== null) {
                $html .= '<span class="tx-analytics-performance-trend tx-analytics-performance-trend-' . $this->escape((string)$metric['trendDirection']) . '">';
                $html .= '<span class="tx-analytics-performance-trend-icon tx-analytics-performance-icon-arrow-trend-' . $this->escape((string)$metric['trendDirection']) . '" aria-hidden="true"></span>';
                $html .= $this->escape((string)$metric['trend']) . '</span>';
            }
            $html .= $this->renderTooltip($metric);
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '<div class="tx-analytics-performance-meta">';
        $html .= '<div class="tx-analytics-performance-meta-item tx-analytics-performance-period">';
        $html .= '<span class="tx-analytics-performance-meta-icon tx-analytics-performance-icon-calendar-days" aria-hidden="true"></span>';
        $html .= '<form class="tx-analytics-performance-period-form" method="get">';
        foreach ($this->hiddenQueryFields($queryParams, $pageId) as $name => $value) {
            $html .= '<input type="hidden" name="' . $this->escape($name) . '" value="' . $this->escape($value) . '">';
        }
        $html .= '<label class="visually-hidden" for="tx-analytics-period-select">' . $this->escape($this->translate('pagePerformance.period')) . '</label>';
        $html .= '<select id="tx-analytics-period-select" class="form-select form-select-sm" name="tx_analytics_period">';
        foreach ([7, 14, 30] as $period) {
            $selected = $period === $days ? ' selected' : '';
            $html .= '<option value="' . $period . '"' . $selected . '>' . $this->escape($this->translate('pagePerformance.days', [$period])) . '</option>';
        }
        $html .= '</select></form></div>';
        if ($detailsUri !== '') {
            $html .= '<a class="tx-analytics-performance-meta-item tx-analytics-performance-meta-link" href="' . $this->escape($detailsUri) . '">';
            $html .= '<span class="tx-analytics-performance-meta-icon tx-analytics-performance-icon-circle-plus" aria-hidden="true"></span>';
            $html .= '<span>' . $this->escape($this->translate('pagePerformance.details')) . '</span>';
            $html .= '</a>';
        }
        $html .= '</div></section>';

        return $html;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildMetrics(int $pageId, ?Site $site, ?SiteLanguage $language, int $days): array
    {
        $analyticsData = $this->loadPageData($pageId, $site, $language, $days);

        if ($analyticsData === null) {
            return $this->buildPlaceholderMetrics();
        }

        $pageData = $analyticsData['page'];
        $visitsSeries = $analyticsData['visitsSeries'];
        $bounceRateSeries = $analyticsData['bounceRateSeries'];
        $avgDurationSeries = $analyticsData['avgDurationSeries'];

        $visitCount = (int)($pageData['visitCount'] ?? 0);
        $bounceRate = (float)($pageData['bounceRate'] ?? 0.0);
        $avgDuration = (float)($pageData['averageVisitDuration'] ?? 0.0);
        $continuationRate = max(0.0, 100.0 - $bounceRate);

        $visitsChart = $visitsSeries !== [] ? $visitsSeries : [$visitCount];
        $visitsLegendStart = $visitsSeries !== [] ? (string)(int)$visitsSeries[0] : '-';
        $visitsLegendEnd = number_format($visitCount, 0, '.', "\u{202F}");

        $bounceChart = $bounceRateSeries !== [] ? $bounceRateSeries : [$bounceRate];
        $bounceLegendStart = $bounceRateSeries !== [] ? number_format((float)$bounceRateSeries[0], 1) . '%' : '-';

        $continuationChart = $bounceRateSeries !== []
            ? array_map(static fn (int|float $v): float => max(0.0, 100.0 - (float)$v), $bounceRateSeries)
            : [$continuationRate];
        $continuationLegendStart = $bounceRateSeries !== []
            ? number_format(max(0.0, 100.0 - (float)$bounceRateSeries[0]), 1) . '%'
            : '-';

        return [
            [
                'key' => 'visitCount',
                'label' => $this->translate('pagePerformance.views'),
                'description' => $this->translate('pagePerformance.tooltip.description.views'),
                'icon' => 'eye',
                'tone' => 'primary',
                'value' => number_format($visitCount, 0, '.', "\u{202F}"),
                'trend' => null,
                'trendDirection' => null,
                'details' => [number_format($visitCount, 0, '.', "\u{202F}"), '-', '-'],
                'chart' => $visitsChart,
                'chartLegend' => [$visitsLegendStart, $visitsLegendEnd],
            ],
            [
                'key' => 'bounceRate',
                'label' => $this->translate('pagePerformance.bounceRate'),
                'description' => $this->translate('pagePerformance.tooltip.description.bounceRate'),
                'icon' => 'arrow-right-from-bracket',
                'tone' => 'danger',
                'value' => number_format($bounceRate, 1) . '%',
                'trend' => null,
                'trendDirection' => null,
                'details' => [number_format($bounceRate, 1) . '%', '-', '-'],
                'chart' => $bounceChart,
                'chartLegend' => [$bounceLegendStart, number_format($bounceRate, 1) . '%'],
            ],
            [
                'key' => 'averageVisitDuration',
                'label' => $this->translate('pagePerformance.averageTimeOnPage'),
                'description' => $this->translate('pagePerformance.tooltip.description.averageTimeOnPage'),
                'icon' => 'clock',
                'tone' => 'success',
                'value' => $this->formatDuration($avgDuration),
                'trend' => null,
                'trendDirection' => null,
                'details' => [$this->formatDuration($avgDuration), '-', '-'],
                'chart' => $avgDurationSeries !== [] ? $avgDurationSeries : [$avgDuration],
                'chartLegend' => [
                    $avgDurationSeries !== [] ? $this->formatDuration((float)$avgDurationSeries[0]) : '-',
                    $this->formatDuration($avgDuration),
                ],
            ],
            [
                'key' => 'continuationRate',
                'label' => $this->translate('pagePerformance.continuationRate'),
                'description' => $this->translate('pagePerformance.tooltip.description.continuationRate'),
                'icon' => 'right-to-bracket',
                'tone' => 'info',
                'value' => number_format($continuationRate, 1) . '%',
                'trend' => null,
                'trendDirection' => null,
                'details' => [number_format($continuationRate, 1) . '%', '-', '-'],
                'chart' => $continuationChart,
                'chartLegend' => [$continuationLegendStart, number_format($continuationRate, 1) . '%'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPlaceholderMetrics(): array
    {
        $placeholders = [
            ['visitCount', $this->translate('pagePerformance.views'), 'eye', 'primary'],
            ['bounceRate', $this->translate('pagePerformance.bounceRate'), 'arrow-right-from-bracket', 'danger'],
            ['averageVisitDuration', $this->translate('pagePerformance.averageTimeOnPage'), 'clock', 'success'],
            ['continuationRate', $this->translate('pagePerformance.continuationRate'), 'right-to-bracket', 'info'],
        ];

        $metrics = [];
        foreach ($placeholders as [$key, $label, $icon, $tone]) {
            $metrics[] = [
                'key' => $key,
                'label' => $label,
                'description' => $this->translate('pagePerformance.tooltip.description.' . match ($key) {
                    'visitCount' => 'views',
                    'bounceRate' => 'bounceRate',
                    'averageVisitDuration' => 'averageTimeOnPage',
                    default => 'continuationRate',
                }),
                'icon' => $icon,
                'tone' => $tone,
                'value' => '-',
                'trend' => null,
                'trendDirection' => null,
                'details' => ['-', '-', '-'],
                'chart' => [],
                'chartLegend' => ['-', '-'],
            ];
        }
        return $metrics;
    }

    /**
     * Loads page analytics and time series data from cache or API.
     * Returns null when data is unavailable.
     *
     * @return array{page: array<string, mixed>, visitsSeries: list<int|float>, bounceRateSeries: list<int|float>, avgDurationSeries: list<int|float>}|null
     */
    private function loadPageData(int $pageId, ?Site $site, ?SiteLanguage $language, int $days): ?array
    {
        if ($site === null) {
            return null;
        }

        $settings = $site->getSettings();
        try {
            $websiteId = (string)($settings->get('externalWebsiteId', '') ?: '');
            $encryptedApiKey = (string)($settings->get('apiKey', '') ?: '');
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface) {
            return null;
        }

        if ($websiteId === '' || $encryptedApiKey === '') {
            return null;
        }

        try {
            $apiKey = $this->cipherService->decrypt($encryptedApiKey);
        } catch (\Throwable) {
            return null;
        }

        $pageUrl = $this->resolvePageUrl($site, $pageId, $language);
        if ($pageUrl === '') {
            return null;
        }

        $cacheKey = 'page_' . md5($websiteId . '_' . $pageUrl . '_' . $days);

        /** @var array{page: array<string, mixed>, visitsSeries: list<int|float>, bounceRateSeries: list<int|float>, avgDurationSeries: list<int|float>}|null|false $cached */
        $cached = $this->pageAnalyticsCache->get($cacheKey);
        if ($cached === false) {
            try {
                $cached = $this->fetchFromApi($websiteId, $apiKey, $pageUrl, $days);
                $this->pageAnalyticsCache->set($cacheKey, $cached);
            } catch (AnalyticsApiException $e) {
                $this->logger->warning('PagePerformanceBarListener: Page analytics call failed.', [
                    'websiteId' => $websiteId,
                    'reason' => $e->reason,
                ]);
                return null;
            }
        }

        return $cached ?: null;
    }

    /**
     * @return array{page: array<string, mixed>, visitsSeries: list<int|float>, bounceRateSeries: list<int|float>, avgDurationSeries: list<int|float>}|null
     * @throws AnalyticsApiException
     */
    private function fetchFromApi(string $websiteId, string $apiKey, string $pageUrl, int $days): ?array
    {
        $to = new \DateTimeImmutable('today 23:59:59');
        $from = $to->modify('-' . ($days - 1) . ' days');

        $page = $this->analyticsClient->fetchPageAnalytics($websiteId, $apiKey, $pageUrl, $from, $to);

        if ($page === null) {
            return null;
        }

        $seriesResult = $this->analyticsClient->fetchAllTimeSeries($websiteId, $apiKey, $from, $to);
        foreach ($seriesResult['failures'] as $key => $reason) {
            $this->logger->warning('PagePerformanceBarListener: Time series call failed.', [
                'websiteId' => $websiteId,
                'series' => $key,
                'reason' => $reason,
            ]);
        }

        return [
            'page' => $page,
            'visitsSeries' => $seriesResult['visits'],
            'bounceRateSeries' => $seriesResult['bounceRate'],
            'avgDurationSeries' => $seriesResult['avgDuration'],
        ];
    }

    private function resolvePageUrl(Site $site, int $pageId, ?SiteLanguage $language = null): string
    {
        try {
            $routeParams = $language !== null ? ['_language' => $language] : [];
            $url = (string)$site->getRouter()->generateUri($pageId, $routeParams, '', RouterInterface::ABSOLUTE_URL);
            return rtrim($url, '/');
        } catch (\Throwable) {
            return '';
        }
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds <= 0) {
            return '0:00';
        }
        $total = (int)round($seconds);
        return intdiv($total, 60) . ':' . str_pad((string)($total % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string, mixed> $metric
     */
    private function renderTooltip(array $metric): string
    {
        $detailLabels = [
            $this->translate('pagePerformance.tooltip.current'),
            $this->translate('pagePerformance.tooltip.previous'),
            $this->translate('pagePerformance.tooltip.peak'),
        ];

        $html = '<div class="tx-analytics-performance-tooltip" role="tooltip">';
        $html .= '<div class="tx-analytics-performance-tooltip-title">' . $this->escape($metric['label']) . '</div>';
        if (isset($metric['description']) && $metric['description'] !== '') {
            $html .= '<p class="tx-analytics-performance-tooltip-description">' . $this->escape((string)$metric['description']) . '</p>';
        }
        $html .= '<dl class="tx-analytics-performance-tooltip-data">';
        foreach ($detailLabels as $index => $label) {
            $html .= '<div><dt>' . $this->escape($label) . '</dt><dd>' . $this->escape($metric['details'][$index] ?? '-') . '</dd></div>';
        }
        $html .= '</dl>';
        if ($metric['chart'] !== []) {
            $html .= '<div class="tx-analytics-performance-tooltip-chart" aria-label="' . $this->escape($this->translate('pagePerformance.tooltip.chart')) . '">';
            $html .= $this->sparklineRenderer->render($metric['chart'], [
                'label' => $this->translate('pagePerformance.tooltip.chart') . ': ' . $metric['label'],
                'class' => 'tx-analytics-performance-sparkline',
                'tone' => $metric['tone'],
            ]);
            $html .= '<div class="tx-analytics-performance-tooltip-chart-legend">';
            $html .= '<span><span>' . $this->escape($this->translate('pagePerformance.tooltip.start')) . '</span><strong>' . $this->escape($metric['chartLegend'][0]) . '</strong></span>';
            $html .= '<span><span>' . $this->escape($this->translate('pagePerformance.tooltip.now')) . '</span><strong>' . $this->escape($metric['chartLegend'][1]) . '</strong></span>';
            $html .= '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    private function normalizeDays(int $days): int
    {
        return in_array($days, [7, 14, 30], true) ? $days : 7;
    }

    private function buildDetailsUri(?Site $site, int $days): string
    {
        if ($site === null) {
            return '';
        }
        try {
            return (string)$this->uriBuilder->buildUriFromRoute('site_analytics.dashboard', [
                'siteIdentifier' => $site->getIdentifier(),
                'days' => $days,
            ]);
        } catch (RouteNotFoundException) {
            return '';
        }
    }

    private function trySite(int $pageId): ?Site
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return null;
        }
    }

    private function trySiteLanguage(?Site $site, int $languageId): ?SiteLanguage
    {
        if ($site === null) {
            return null;
        }
        try {
            return $site->getLanguageById($languageId);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, string>
     */
    private function hiddenQueryFields(array $queryParams, int $pageId): array
    {
        $fields = ['id' => (string)$pageId];
        foreach ($queryParams as $name => $value) {
            if ($name === 'id' || $name === 'tx_analytics_period' || !is_scalar($value)) {
                continue;
            }
            $fields[(string)$name] = (string)$value;
        }
        return $fields;
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
            $css .= '.tx-analytics-performance-icon-' . $icon . '{--tx-analytics-performance-icon:url("' . $this->escape($url) . '");}';
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

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param list<int|string> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $label = $this->getLanguageService()->sL(
            'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:' . $key
        );
        if ($label === '') {
            return $key;
        }
        return $arguments === [] ? $label : sprintf($label, ...$arguments);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
