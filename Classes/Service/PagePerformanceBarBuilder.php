<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Dashboard\DashboardPeriods;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\View\SparklineRenderer;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class PagePerformanceBarBuilder
{
    public function __construct(
        private SparklineRenderer $sparklineRenderer,
        private SiteFinder $siteFinder,
        private UriBuilder $uriBuilder,
        private AnalyticsDataClientInterface $analyticsClient,
        private CipherServiceInterface $cipherService,
        private LoggerInterface $logger,
        private FrontendInterface $pageAnalyticsCache,
        private MetricFormatterInterface $formatter,
    ) {
    }

    public function buildSkeletonHtml(int $pageId, int $languageId, int $days): string
    {
        return '<section class="tx-analytics-performance-bar tx-analytics-performance-bar--loading"'
            . ' aria-label="' . $this->escape($this->translate('pagePerformance.ariaLabel')) . '"'
            . ' aria-busy="true"'
            . ' data-page-id="' . $pageId . '"'
            . ' data-language-id="' . $languageId . '"'
            . ' data-days="' . $days . '">'
            . '<div class="tx-analytics-performance-loading-overlay" aria-live="polite">'
            . '<span class="tx-analytics-performance-loading-spinner" aria-hidden="true"></span>'
            . '<span>' . $this->escape($this->translate('pagePerformance.loading')) . '</span>'
            . '</div>'
            . '</section>';
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    public function buildHtml(int $pageId, ?Site $site, ?SiteLanguage $language, int $days, array $queryParams, string $detailsUri): string
    {
        $metrics = $this->buildMetrics($pageId, $site, $language, $days);
        $detailsId = 'tx-analytics-performance-details-' . $pageId;
        $expanded = $this->isDetailsExpanded();

        $html = '<section class="tx-analytics-performance-bar' . ($expanded ? ' tx-analytics-performance-bar--expanded' : '') . '"'
            . ' aria-label="' . $this->escape($this->translate('pagePerformance.ariaLabel')) . '"'
            . ' data-page-id="' . $pageId . '"'
            . ' data-language-id="' . ($language?->getLanguageId() ?? 0) . '">';

        foreach ($metrics as $metric) {
            $html .= '<div class="tx-analytics-performance-metric tx-analytics-performance-metric-' . $this->escape($metric['tone']) . '" tabindex="0">';
            $html .= '<div class="tx-analytics-performance-metric-body">';
            $html .= '<span class="tx-analytics-performance-icon tx-analytics-performance-icon-' . $this->escape($metric['icon']) . '" aria-hidden="true"></span>';
            $html .= '<span class="tx-analytics-performance-value-row">';
            $html .= '<span class="tx-analytics-performance-value">' . $this->escape($metric['value']) . '</span>';
            if ($metric['trend'] !== null) {
                $html .= '<span class="tx-analytics-performance-trend tx-analytics-performance-trend-' . $this->escape((string)$metric['trendDirection']) . '">';
                $html .= '<span class="tx-analytics-performance-trend-icon tx-analytics-performance-icon-arrow-trend-' . $this->escape((string)$metric['trendDirection']) . '" aria-hidden="true"></span>';
                $html .= $this->escape((string)$metric['trend']);
                $html .= '<span class="visually-hidden"> ' . $this->escape($this->translate(
                    in_array($metric['key'], ['bounceRate', 'continuationRate'], true)
                        ? 'dashboardWidget.sitePerformance.comparedToPreviousPeriodInPercentagePoints'
                        : 'dashboardWidget.sitePerformance.comparedToPreviousPeriod'
                )) . '</span>';
                $html .= '</span>';
            } elseif (($metric['trendNote'] ?? null) !== null) {
                // The metric already opens the details tooltip, which holds the note as text, so the icon has no tooltip of its own.
                $html .= '<span class="tx-analytics-performance-trend tx-analytics-performance-trend-neutral">';
                $html .= '<span class="tx-analytics-performance-trend-icon tx-analytics-performance-icon-circle-info" aria-hidden="true"></span>';
                $html .= '<span class="visually-hidden">' . $this->escape($this->translate('trendNote.' . $metric['trendNote'])) . '</span>';
                $html .= '</span>';
            }
            $html .= '</span>';
            $html .= '<span class="tx-analytics-performance-label">' . $this->escape($metric['label']) . '</span>';
            $html .= '<div class="tx-analytics-performance-tooltip" role="tooltip">' . $this->renderMetricDetails($metric) . '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '<div class="tx-analytics-performance-loading-overlay" aria-live="polite" aria-hidden="true">';
        $html .= '<span class="tx-analytics-performance-loading-spinner" aria-hidden="true"></span>';
        $html .= '<span>' . $this->escape($this->translate('pagePerformance.loading')) . '</span>';
        $html .= '</div>';

        $html .= '<div class="tx-analytics-performance-meta">';
        $html .= '<div class="tx-analytics-performance-meta-item tx-analytics-performance-period">';
        $html .= '<span class="tx-analytics-performance-meta-icon tx-analytics-performance-icon-calendar-days" aria-hidden="true"></span>';
        $html .= '<form class="tx-analytics-performance-period-form" method="get">';
        foreach ($this->hiddenQueryFields($queryParams, $pageId) as $name => $value) {
            $html .= '<input type="hidden" name="' . $this->escape($name) . '" value="' . $this->escape($value) . '">';
        }
        $html .= '<label class="visually-hidden" for="tx-analytics-period-select">' . $this->escape($this->translate('pagePerformance.period')) . '</label>';
        $html .= '<select id="tx-analytics-period-select" class="form-select form-select-sm" name="tx_analytics_period">';
        foreach (DashboardPeriods::periods() as $period) {
            $selected = $period === $days ? ' selected' : '';
            $html .= '<option value="' . $period . '"' . $selected . '>' . $this->escape($this->translate('pagePerformance.days', [$period])) . '</option>';
        }
        $html .= '</select></form></div>';
        $html .= '<button type="button" class="tx-analytics-performance-meta-item tx-analytics-performance-meta-link tx-analytics-performance-details-toggle"'
            . ' aria-expanded="' . ($expanded ? 'true' : 'false') . '"'
            . ' aria-controls="' . $detailsId . '"'
            . ' data-label-show="' . $this->escape($this->translate('pagePerformance.showAllDetails')) . '"'
            . ' data-label-hide="' . $this->escape($this->translate('pagePerformance.hideAllDetails')) . '">';
        $html .= '<span class="tx-analytics-performance-details-toggle-icon" aria-hidden="true"></span>';
        $html .= '<span class="tx-analytics-performance-details-toggle-label">'
            . $this->escape($this->translate($expanded ? 'pagePerformance.hideAllDetails' : 'pagePerformance.showAllDetails'))
            . '</span>';
        $html .= '</button>';
        if ($detailsUri !== '') {
            $html .= '<a class="tx-analytics-performance-meta-item tx-analytics-performance-meta-link" href="' . $this->escape($detailsUri) . '">';
            $html .= '<span class="tx-analytics-performance-meta-icon tx-analytics-performance-icon-circle-plus" aria-hidden="true"></span>';
            $html .= '<span>' . $this->escape($this->translate('pagePerformance.showInAnalytics')) . '</span>';
            $html .= '</a>';
        }
        $html .= '</div>';

        $html .= '<div id="' . $detailsId . '" class="tx-analytics-performance-details"' . ($expanded ? '' : ' hidden') . '>';
        foreach ($metrics as $metric) {
            $html .= '<div class="tx-analytics-performance-detail">' . $this->renderMetricDetails($metric) . '</div>';
        }
        $html .= '</div></section>';

        return $html;
    }

    public function trySite(int $pageId): ?Site
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return null;
        }
    }

    public function trySiteLanguage(?Site $site, int $languageId): ?SiteLanguage
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

    public function normalizeDays(int $days): int
    {
        return DashboardPeriods::sanitizePeriod($days);
    }

    public function buildDetailsUri(?Site $site, int $days, string $pageUrl = ''): string
    {
        if ($site === null) {
            return '';
        }
        try {
            $params = ['siteIdentifier' => $site->getIdentifier(), 'days' => $days];
            if ($pageUrl !== '') {
                $params['pageUrl'] = $pageUrl;
            }
            return (string)$this->uriBuilder->buildUriFromRoute('site_analytics.dashboard', $params);
        } catch (RouteNotFoundException) {
            return '';
        }
    }

    public function resolvePageUrl(Site $site, int $pageId, ?SiteLanguage $language = null): string
    {
        try {
            $routeParams = $language !== null ? ['_language' => $language] : [];
            $url = (string)$site->getRouter()->generateUri($pageId, $routeParams, '', RouterInterface::ABSOLUTE_URL);
            return rtrim($url, '/');
        } catch (\Throwable) {
            return '';
        }
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
        $previousPage = $analyticsData['previousPage'] ?? null;
        $visitsSeries = $analyticsData['visitsSeries'];
        $bounceRateSeries = $analyticsData['bounceRateSeries'];
        $avgDurationSeries = $analyticsData['avgDurationSeries'];
        $formattedDates = array_map(
            fn (string $d): string => $this->formatter->formatDate(new \DateTimeImmutable($d)),
            $analyticsData['seriesDates'],
        );

        $visitCount = (int)($pageData['visitCount'] ?? 0);
        $bounceRate = (float)($pageData['bounceRate'] ?? 0.0);
        $avgDuration = (float)($pageData['averageVisitDuration'] ?? 0.0);
        $continuationRate = max(0.0, 100.0 - $bounceRate);

        // Without a row for the previous period the page had no visits there. The views compare with 0 as a measured value.
        // The rates of a period without visits are not measured, so they show a note instead of a trend.
        $prevVisitCount = $previousPage !== null ? (int)($previousPage['visitCount'] ?? 0) : 0;
        $prevBounceRate = $previousPage !== null ? (float)($previousPage['bounceRate'] ?? 0.0) : null;
        $prevAvgDuration = $previousPage !== null ? (float)($previousPage['averageVisitDuration'] ?? 0.0) : null;
        $prevContinuationRate = $prevBounceRate !== null ? max(0.0, 100.0 - $prevBounceRate) : null;
        $rateNote = $previousPage === null ? TrendNote::NoPreviousVisits->value : null;

        $visitsChart = $visitsSeries !== [] ? $visitsSeries : [$visitCount];
        $visitsCurrent = $this->seriesCurrent($visitsSeries);
        $visitsPrevious = $this->seriesPrevious($visitsSeries);
        $visitsPeak = $this->seriesPeak($visitsSeries);

        $bounceChart = $bounceRateSeries !== [] ? $bounceRateSeries : [$bounceRate];
        $bounceRateCurrent = $this->seriesCurrent($bounceRateSeries);
        $bounceRatePrevious = $this->seriesPrevious($bounceRateSeries);
        $bounceRatePeak = $this->seriesPeak($bounceRateSeries);

        $continuationChart = $bounceRateSeries !== []
            ? array_map(static fn (int|float $v): float => max(0.0, 100.0 - (float)$v), $bounceRateSeries)
            : [$continuationRate];
        $continuationCurrent = $bounceRateCurrent !== null ? max(0.0, 100.0 - $bounceRateCurrent) : null;
        $continuationPrevious = $bounceRatePrevious !== null ? max(0.0, 100.0 - $bounceRatePrevious) : null;
        $continuationPeak = $bounceRateSeries !== [] ? max(0.0, 100.0 - (float)min($bounceRateSeries)) : null;

        $avgDurationCurrent = $this->seriesCurrent($avgDurationSeries);
        $avgDurationPrevious = $this->seriesPrevious($avgDurationSeries);
        $avgDurationPeak = $this->seriesPeak($avgDurationSeries);

        return [
            [
                'key' => 'visitCount',
                'label' => $this->translate('pagePerformance.views'),
                'description' => $this->translate('pagePerformance.tooltip.description.views'),
                'icon' => 'eye',
                'tone' => 'visits',
                'value' => number_format($visitCount, 0, '.', "\u{202F}"),
                'trend' => $this->formatter->formatAbsoluteCountTrend($visitCount, $prevVisitCount),
                'trendDirection' => $this->formatter->trendDirection($visitCount, $prevVisitCount),
                'trendNote' => null,
                'details' => [
                    $visitsCurrent !== null ? number_format((int)$visitsCurrent, 0, '.', "\u{202F}") : '-',
                    $visitsPrevious !== null ? number_format((int)$visitsPrevious, 0, '.', "\u{202F}") : '-',
                    $visitsPeak !== null ? number_format((int)$visitsPeak, 0, '.', "\u{202F}") : '-',
                ],
                'chart' => $visitsChart,
                'chartLabels' => $this->buildChartLabels($visitsChart, $formattedDates, static fn (int|float $v): string => number_format((int)$v, 0, '.', "\u{202F}")),
                'chartLegend' => [
                    $visitsSeries !== [] ? number_format((int)$visitsSeries[0], 0, '.', "\u{202F}") : '-',
                    $visitsCurrent !== null ? number_format((int)$visitsCurrent, 0, '.', "\u{202F}") : '-',
                ],
            ],
            [
                'key' => 'bounceRate',
                'label' => $this->translate('pagePerformance.bounceRate'),
                'description' => $this->translate('pagePerformance.tooltip.description.bounceRate'),
                'icon' => 'arrow-right-from-bracket',
                'tone' => 'bounce-rate',
                'value' => number_format($bounceRate, 2) . '%',
                'trend' => $prevBounceRate !== null ? $this->formatter->formatAbsolutePercentPointTrend($bounceRate, $prevBounceRate) : null,
                'trendDirection' => $prevBounceRate !== null ? $this->formatter->trendDirection($prevBounceRate, $bounceRate) : null,
                'trendNote' => $rateNote,
                'details' => [
                    $bounceRateCurrent !== null ? number_format($bounceRateCurrent, 2) . '%' : '-',
                    $bounceRatePrevious !== null ? number_format($bounceRatePrevious, 2) . '%' : '-',
                    $bounceRatePeak !== null ? number_format($bounceRatePeak, 2) . '%' : '-',
                ],
                'chart' => $bounceChart,
                'chartLabels' => $this->buildChartLabels($bounceChart, $formattedDates, static fn (int|float $v): string => number_format((float)$v, 2) . '%'),
                'chartLegend' => [
                    $bounceRateSeries !== [] ? number_format((float)$bounceRateSeries[0], 2) . '%' : '-',
                    $bounceRateCurrent !== null ? number_format($bounceRateCurrent, 2) . '%' : '-',
                ],
            ],
            [
                'key' => 'averageVisitDuration',
                'label' => $this->translate('pagePerformance.averageTimeOnPage'),
                'description' => $this->translate('pagePerformance.tooltip.description.averageTimeOnPage'),
                'icon' => 'clock',
                'tone' => 'avg-duration',
                'value' => $this->formatDuration($avgDuration),
                'trend' => $prevAvgDuration !== null ? $this->formatter->formatAbsoluteDurationTrend((int)round($avgDuration), (int)round($prevAvgDuration)) : null,
                'trendDirection' => $prevAvgDuration !== null ? $this->formatter->trendDirection(round($avgDuration), round($prevAvgDuration)) : null,
                'trendNote' => $rateNote,
                'details' => [
                    $avgDurationCurrent !== null ? $this->formatDuration($avgDurationCurrent) : '-',
                    $avgDurationPrevious !== null ? $this->formatDuration($avgDurationPrevious) : '-',
                    $avgDurationPeak !== null ? $this->formatDuration($avgDurationPeak) : '-',
                ],
                'chart' => $avgDurationSeries !== [] ? $avgDurationSeries : [$avgDuration],
                'chartLabels' => $this->buildChartLabels($avgDurationSeries !== [] ? $avgDurationSeries : [$avgDuration], $formattedDates, fn (int|float $v): string => $this->formatDuration((float)$v)),
                'chartLegend' => [
                    $avgDurationSeries !== [] ? $this->formatDuration((float)$avgDurationSeries[0]) : '-',
                    $avgDurationCurrent !== null ? $this->formatDuration($avgDurationCurrent) : '-',
                ],
            ],
            [
                'key' => 'continuationRate',
                'label' => $this->translate('pagePerformance.continuationRate'),
                'description' => $this->translate('pagePerformance.tooltip.description.continuationRate'),
                'icon' => 'right-to-bracket',
                'tone' => 'continuation-rate',
                'value' => number_format($continuationRate, 2) . '%',
                'trend' => $prevContinuationRate !== null ? $this->formatter->formatAbsolutePercentPointTrend($continuationRate, $prevContinuationRate) : null,
                'trendDirection' => $prevContinuationRate !== null ? $this->formatter->trendDirection($continuationRate, $prevContinuationRate) : null,
                'trendNote' => $rateNote,
                'details' => [
                    $continuationCurrent !== null ? number_format($continuationCurrent, 2) . '%' : '-',
                    $continuationPrevious !== null ? number_format($continuationPrevious, 2) . '%' : '-',
                    $continuationPeak !== null ? number_format($continuationPeak, 2) . '%' : '-',
                ],
                'chart' => $continuationChart,
                'chartLabels' => $this->buildChartLabels($continuationChart, $formattedDates, static fn (int|float $v): string => number_format((float)$v, 2) . '%'),
                'chartLegend' => [
                    $bounceRateSeries !== [] ? number_format(max(0.0, 100.0 - (float)$bounceRateSeries[0]), 2) . '%' : '-',
                    $continuationCurrent !== null ? number_format($continuationCurrent, 2) . '%' : '-',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPlaceholderMetrics(): array
    {
        $placeholders = [
            ['visitCount', $this->translate('pagePerformance.views'), 'eye', 'visits'],
            ['bounceRate', $this->translate('pagePerformance.bounceRate'), 'arrow-right-from-bracket', 'bounce-rate'],
            ['averageVisitDuration', $this->translate('pagePerformance.averageTimeOnPage'), 'clock', 'avg-duration'],
            ['continuationRate', $this->translate('pagePerformance.continuationRate'), 'right-to-bracket', 'continuation-rate'],
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
                'trendNote' => null,
                'details' => ['-', '-', '-'],
                'chart' => [],
                'chartLegend' => ['-', '-'],
            ];
        }
        return $metrics;
    }

    /**
     * @return array{page: array<string, mixed>, previousPage: array<string, mixed>|null, visitsSeries: list<int|float>, bounceRateSeries: list<int|float>, avgDurationSeries: list<int|float>, seriesDates: list<string>}|null
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

        /** @var array{page: array<string, mixed>, previousPage: array<string, mixed>|null, visitsSeries: list<int|float>, bounceRateSeries: list<int|float>, avgDurationSeries: list<int|float>, seriesDates: list<string>}|null|false $cached */
        $cached = $this->pageAnalyticsCache->get($cacheKey);
        if ($cached === false) {
            try {
                $cached = $this->fetchFromApi($websiteId, $apiKey, $pageUrl, $days);
                $this->pageAnalyticsCache->set($cacheKey, $cached);
            } catch (AnalyticsApiException $e) {
                $this->logger->warning('PagePerformanceBarBuilder: Page analytics call failed.', [
                    'websiteId' => $websiteId,
                    'reason' => $e->reason,
                ]);
                return null;
            }
        }

        return $cached ?: null;
    }

    /**
     * @return array{page: array<string, mixed>, previousPage: array<string, mixed>|null, visitsSeries: list<int|float>, bounceRateSeries: list<int|float>, avgDurationSeries: list<int|float>, seriesDates: list<string>}|null
     * @throws AnalyticsApiException
     */
    private function fetchFromApi(string $websiteId, string $apiKey, string $pageUrl, int $days): ?array
    {
        $to = new \DateTimeImmutable('today 23:59:59');
        $from = $to->modify('-' . ($days - 1) . ' days');
        $prevTo = $from->modify('-1 day');
        $prevFrom = $prevTo->modify('-' . ($days - 1) . ' days');

        $page = $this->analyticsClient->fetchPageAnalytics($websiteId, $apiKey, $pageUrl, $from, $to);
        $previousPage = $this->analyticsClient->fetchPageAnalytics($websiteId, $apiKey, $pageUrl, $prevFrom, $prevTo);

        if ($page === null && $previousPage === null) {
            return null;
        }

        if ($page === null) {
            $page = ['visitCount' => 0, 'bounceRate' => 0.0, 'averageVisitDuration' => 0.0];
        }

        $seriesResult = $this->analyticsClient->fetchAllTimeSeries($websiteId, $apiKey, $pageUrl, $from, $to);
        foreach ($seriesResult['failures'] as $key => $reason) {
            $this->logger->warning('PagePerformanceBarBuilder: Time series call failed.', [
                'websiteId' => $websiteId,
                'series' => $key,
                'reason' => $reason,
            ]);
        }

        return [
            'page' => $page,
            'previousPage' => $previousPage,
            'visitsSeries' => $seriesResult['visits'],
            'bounceRateSeries' => $seriesResult['bounceRate'],
            'avgDurationSeries' => $seriesResult['avgDuration'],
            'seriesDates' => $seriesResult['dates'],
        ];
    }

    /**
     * @param list<int|float> $chartValues
     * @param array<string> $dates
     * @return list<string>
     */
    private function buildChartLabels(array $chartValues, array $dates, callable $valueFormatter): array
    {
        $labels = [];
        foreach ($chartValues as $i => $v) {
            $date = $dates[$i] ?? '';
            $labels[] = $date !== '' ? $date . ': ' . $valueFormatter($v) : $valueFormatter($v);
        }
        return $labels;
    }

    /** @param list<int|float> $series */
    private function seriesCurrent(array $series): int|float|null
    {
        return $series !== [] ? $series[count($series) - 1] : null;
    }

    /** @param list<int|float> $series */
    private function seriesPrevious(array $series): int|float|null
    {
        $count = count($series);
        return $count >= 2 ? $series[$count - 2] : null;
    }

    /** @param list<int|float> $series */
    private function seriesPeak(array $series): int|float|null
    {
        return $series !== [] ? max($series) : null;
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
    private function renderMetricDetails(array $metric): string
    {
        $detailLabels = [
            $this->translate('pagePerformance.tooltip.today'),
            $this->translate('pagePerformance.tooltip.yesterday'),
            $this->translate('pagePerformance.tooltip.peak'),
        ];

        $html = '<div class="tx-analytics-performance-tooltip-title">' . $this->escape($metric['label']) . '</div>';
        // Description and chart are always rendered so the rows line up across metrics.
        // The note stays inside the description: the details panel is a subgrid with one row per part.
        $html .= '<p class="tx-analytics-performance-tooltip-description">' . $this->escape((string)($metric['description'] ?? ''));
        if (($metric['trendNote'] ?? null) !== null) {
            $html .= '<span class="tx-analytics-performance-tooltip-note">'
                . '<span class="tx-analytics-performance-trend-icon tx-analytics-performance-icon-circle-info" aria-hidden="true"></span>'
                . $this->escape($this->translate('trendNote.' . $metric['trendNote']))
                . '</span>';
        }
        $html .= '</p>';
        $html .= '<dl class="tx-analytics-performance-tooltip-data">';
        foreach ($detailLabels as $index => $label) {
            $html .= '<div><dt>' . $this->escape($label) . '</dt><dd>' . $this->escape($metric['details'][$index] ?? '-') . '</dd></div>';
        }
        $html .= '</dl>';
        $html .= '<div class="tx-analytics-performance-tooltip-chart">';
        if ($metric['chart'] !== []) {
            $html .= $this->sparklineRenderer->render($metric['chart'], [
                'label' => $this->translate('pagePerformance.tooltip.chart') . ': ' . $metric['label'],
                'class' => 'tx-analytics-performance-sparkline',
                'tone' => $metric['tone'],
                'labels' => $metric['chartLabels'] ?? [],
                'smooth' => true,
                'axes' => true,
                // The bottom axis is drawn at yMin, so it has to be zero to read as one.
                'yMin' => 0.0,
            ]);
        }
        $html .= '</div>';

        return $html;
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

    private function isDetailsExpanded(): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        return (bool)($backendUser->uc['tx_analytics']['pagePerformanceExpanded'] ?? false);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
