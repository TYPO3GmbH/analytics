<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Dashboard\DashboardPeriods;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Data of the page performance bar: the site of a page, the page URL, the link to the dashboard
 * and the metrics. The labels are label keys, PagePerformanceBarRenderer renders the bar.
 */
final readonly class PagePerformanceDataProvider
{
    public function __construct(
        private SiteFinder $siteFinder,
        private DashboardLinkBuilderInterface $dashboardLinkBuilder,
        private AnalyticsDataClientInterface $analyticsClient,
        private CipherServiceInterface $cipherService,
        private LoggerInterface $logger,
        private FrontendInterface $pageAnalyticsCache,
        private MetricFormatterInterface $formatter,
    ) {
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

    /**
     * For a site attached to an active site, the link opens the dashboard of the active site with
     * the page filter. Without access to the root page of that site, the bar shows no link.
     */
    public function buildDetailsUri(?Site $site, int $days, string $pageUrl = ''): string
    {
        if ($site === null) {
            return '';
        }
        return $this->dashboardLinkBuilder->buildForPage($site->getIdentifier(), $pageUrl, ['days' => $days]);
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
     * Metrics of the bar. Without analytics data the values are placeholders.
     *
     * @return list<array<string, mixed>>
     */
    public function getMetrics(int $pageId, ?Site $site, ?SiteLanguage $language, int $days): array
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
                'labelKey' => 'pagePerformance.views',
                'descriptionKey' => 'pagePerformance.tooltip.description.views',
                'trendContextKey' => 'dashboardWidget.sitePerformance.comparedToPreviousPeriod',
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
                'labelKey' => 'pagePerformance.bounceRate',
                'descriptionKey' => 'pagePerformance.tooltip.description.bounceRate',
                'trendContextKey' => 'dashboardWidget.sitePerformance.comparedToPreviousPeriodInPercentagePoints',
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
                'labelKey' => 'pagePerformance.averageTimeOnPage',
                'descriptionKey' => 'pagePerformance.tooltip.description.averageTimeOnPage',
                'trendContextKey' => 'dashboardWidget.sitePerformance.comparedToPreviousPeriod',
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
                'labelKey' => 'pagePerformance.continuationRate',
                'descriptionKey' => 'pagePerformance.tooltip.description.continuationRate',
                'trendContextKey' => 'dashboardWidget.sitePerformance.comparedToPreviousPeriodInPercentagePoints',
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
            ['visitCount', 'pagePerformance.views', 'pagePerformance.tooltip.description.views', 'eye', 'visits'],
            ['bounceRate', 'pagePerformance.bounceRate', 'pagePerformance.tooltip.description.bounceRate', 'arrow-right-from-bracket', 'bounce-rate'],
            ['averageVisitDuration', 'pagePerformance.averageTimeOnPage', 'pagePerformance.tooltip.description.averageTimeOnPage', 'clock', 'avg-duration'],
            ['continuationRate', 'pagePerformance.continuationRate', 'pagePerformance.tooltip.description.continuationRate', 'right-to-bracket', 'continuation-rate'],
        ];

        $metrics = [];
        foreach ($placeholders as [$key, $labelKey, $descriptionKey, $icon, $tone]) {
            $metrics[] = [
                'key' => $key,
                'labelKey' => $labelKey,
                'descriptionKey' => $descriptionKey,
                'trendContextKey' => '',
                'icon' => $icon,
                'tone' => $tone,
                'value' => '-',
                'trend' => null,
                'trendDirection' => null,
                'trendNote' => null,
                'details' => ['-', '-', '-'],
                'chart' => [],
                'chartLabels' => [],
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
                $this->logger->warning('PagePerformanceDataProvider: Page analytics call failed.', [
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
            $this->logger->warning('PagePerformanceDataProvider: Time series call failed.', [
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
}
