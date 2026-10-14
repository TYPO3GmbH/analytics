<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use GuzzleHttp\Promise\Utils;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use T3G\Analytics\Utility\AnalyticsQueryUtility;
use T3G\Analytics\Utility\AnalyticsResponseUtility;

readonly class AnalyticsDataClient implements AnalyticsDataClientInterface
{
    private const PAGE_METRICS = [
        'visitCount',
        'bounceRate',
        'averageVisitDuration',
    ];

    private const SITE_SESSION_METRICS = [
        'visitCount',
        'visitorCount',
        'bounceRate',
    ];


    public function __construct(
        private RequestFactory $requestFactory,
        private ApiExceptionExtractorInterface $exceptionExtractor,
        private ApiConfiguration $apiConfiguration,
        private GuzzleClientFactory $guzzleClientFactory,
    ) {
    }

    /**
     * Fetches analytics metrics for a specific page URL.
     *
     * @return array<string, mixed>|null the matching page entry, or null when no data exists
     * @throws AnalyticsApiException
     */
    public function fetchPageAnalytics(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): ?array {
        $payload = [
            'metrics' => self::PAGE_METRICS,
            'dimensions' => ['pageUrl'],
            'order' => [['member' => 'visitCount', 'direction' => 'desc']],
            'pagination' => ['page' => 1, 'pageSize' => 1],
            'where' => ['and' => [
                ['member' => 'pageUrl', 'operator' => 'eq', 'values' => [$pageUrl]],
            ]],
            'dateRange' => AnalyticsQueryUtility::utcDayRange($from, $to),
        ];

        $body = $this->postJson($this->websiteUrl($websiteId, 'analytics/pages?trace=pagesDashboard'), $apiKey, $payload);
        $rows = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        return $rows[0] ?? null;
    }

    /**
     * Fetches top pages ordered by visit count (no page-URL filter).
     *
     * @param list<string> $pageUrlFilter Parts of page URLs, see AnalyticsQueryUtility::pageUrlWhere()
     * @return list<array<string, mixed>>
     * @throws AnalyticsApiException
     */
    public function fetchTopPages(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $previousFrom,
        \DateTimeImmutable $previousTo,
        int $limit = 10,
        array $pageUrlFilter = [],
    ): array {
        $payload = [
            'metrics' => ['visitCount', 'previousVisitCount', 'visitPercentOfTotal', 'visitCountPercentageChange'],
            'dimensions' => ['pageUrl'],
            'order' => [['member' => 'visitCount', 'direction' => 'desc']],
            'pagination' => ['page' => 1, 'pageSize' => $limit],
            'where' => AnalyticsQueryUtility::pageUrlWhere($pageUrlFilter),
            'dateRange' => AnalyticsQueryUtility::utcDayRange($from, $to),
            'previousDateRange' => AnalyticsQueryUtility::utcDayRange($previousFrom, $previousTo),
        ];

        $body = $this->postJson($this->websiteUrl($websiteId, 'analytics/pages?trace=topPagesDashboard'), $apiKey, $payload);
        $rows = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        return array_values($rows);
    }

    /**
     * Fetches a daily time-series for visit counts.
     *
     * @return list<int|float>
     * @throws AnalyticsApiException
     */
    public function fetchVisitsTimeSeries(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->fetchTimeSeries($websiteId, $apiKey, $pageUrl, 'visits/graph', $from, $to);
    }

    /**
     * Fetches a daily time-series for bounce rate (values in percent, 0–100).
     *
     * @return list<int|float>
     * @throws AnalyticsApiException
     */
    public function fetchBounceRateTimeSeries(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->fetchTimeSeries($websiteId, $apiKey, $pageUrl, 'stats/bounce-rate/graph', $from, $to);
    }

    /**
     * Fetches a daily time-series for average page view duration (values in seconds).
     *
     * @return list<int|float>
     * @throws AnalyticsApiException
     */
    public function fetchAvgDurationTimeSeries(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->fetchTimeSeries($websiteId, $apiKey, $pageUrl, 'stats/average-page-view-duration/graph', $from, $to);
    }

    /**
     * Fetches all three time-series concurrently via parallel HTTP requests.
     *
     * @return array{
     *     visits: list<int|float>,
     *     bounceRate: list<int|float>,
     *     avgDuration: list<int|float>,
     *     dates: list<string>,
     *     failures: array<string, string>
     * }
     */
    public function fetchAllTimeSeries(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $query = AnalyticsQueryUtility::utcDayGraphQuery($from, $to);
        $options = $this->buildRequestOptions($apiKey, ['where' => ['and' => [
            ['member' => 'pageUrl', 'operator' => 'eq', 'values' => [$pageUrl]],
        ]]]);

        $client = $this->guzzleClientFactory->getClient();
        $promises = [
            'visits' => $client->requestAsync('POST', $this->websiteUrl($websiteId, 'visits/graph' . $query), $options),
            'bounceRate' => $client->requestAsync('POST', $this->websiteUrl($websiteId, 'stats/bounce-rate/graph' . $query), $options),
            'avgDuration' => $client->requestAsync('POST', $this->websiteUrl($websiteId, 'stats/average-page-view-duration/graph' . $query), $options),
        ];

        /** @var array<string, array{state: string, value?: \Psr\Http\Message\ResponseInterface, reason?: \Throwable}> $settled */
        $settled = Utils::settle($promises)->wait();

        $visits = [];
        $bounceRate = [];
        $avgDuration = [];
        $dates = [];
        $failures = [];

        foreach ($settled as $key => $result) {
            try {
                $parsed = AnalyticsResponseUtility::timeSeriesData(AnalyticsResponseUtility::decodeSettledResponse($result));
                if ($dates === []) {
                    $dates = $parsed['labels'];
                }
                match ($key) {
                    'visits' => $visits = $parsed['data'],
                    'bounceRate' => $bounceRate = $parsed['data'],
                    'avgDuration' => $avgDuration = $parsed['data'],
                    default => null,
                };
            } catch (\Throwable $e) {
                $failures[$key] = $this->exceptionExtractor->extractReason($e);
            }
        }

        return [
            'visits' => $visits,
            'bounceRate' => $bounceRate,
            'avgDuration' => $avgDuration,
            'dates' => $dates,
            'failures' => $failures,
        ];
    }

    /**
     * @return list<int|float>
     * @throws AnalyticsApiException
     */
    private function fetchTimeSeries(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        string $graphPath,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $query = AnalyticsQueryUtility::utcDayGraphQuery($from, $to);
        $body = ['where' => ['and' => [
            ['member' => 'pageUrl', 'operator' => 'eq', 'values' => [$pageUrl]],
        ]]];

        $response = $this->postJson($this->websiteUrl($websiteId, $graphPath . $query), $apiKey, $body);
        return AnalyticsResponseUtility::timeSeriesData($response)['data'];
    }

    /**
     * @param list<string> $pageUrlFilter Parts of page URLs, see AnalyticsQueryUtility::pageUrlWhere()
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, total: int}>}
     * @throws AnalyticsApiException
     */
    public function fetchSiteVisitsGraph(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $pageUrlFilter = [],
    ): array {
        return $this->fetchGraph($websiteId, $apiKey, 'visits/graph', $from, $to, $pageUrlFilter);
    }

    /**
     * @param list<string> $pageUrlFilter Parts of page URLs, see AnalyticsQueryUtility::pageUrlWhere()
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, total: int}>}
     * @throws AnalyticsApiException
     */
    public function fetchSiteSessionsGraph(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $pageUrlFilter = [],
    ): array {
        return $this->fetchGraph($websiteId, $apiKey, 'sessions/all/graph', $from, $to, $pageUrlFilter);
    }

    /**
     * @param list<string> $pageUrlFilter Parts of page URLs, see AnalyticsQueryUtility::pageUrlWhere()
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, total: int}>}
     * @throws AnalyticsApiException
     */
    public function fetchSiteVisitorsGraph(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $pageUrlFilter = [],
    ): array {
        return $this->fetchGraph($websiteId, $apiKey, 'visitors/all/graph', $from, $to, $pageUrlFilter);
    }

    /**
     * @param list<string> $pageUrlFilter Parts of page URLs, see AnalyticsQueryUtility::pageUrlWhere()
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, total: int}>}
     * @throws AnalyticsApiException
     */
    public function fetchTrafficShareInDepth(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $pageUrlFilter = [],
    ): array {
        $range = AnalyticsQueryUtility::utcDayRange($from, $to);
        $query = '?' . http_build_query(['from' => $range['start'], 'until' => $range['end'], 'unit' => 'day']);
        $body = $this->postJson(
            $this->websiteUrl($websiteId, 'traffic-api/traffic-share/in-depth' . $query),
            $apiKey,
            ['where' => AnalyticsQueryUtility::pageUrlWhere($pageUrlFilter)]
        );
        // Unlike the other graph endpoints, this endpoint has no "payload" wrapper.
        return AnalyticsResponseUtility::graph($body);
    }

    /**
     * @param list<string> $pageUrlFilter Parts of page URLs, see AnalyticsQueryUtility::pageUrlWhere()
     * @return list<array{deviceType: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}>
     * @throws AnalyticsApiException
     */
    public function fetchDeviceSessions(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?\DateTimeImmutable $previousFrom = null,
        ?\DateTimeImmutable $previousTo = null,
        array $pageUrlFilter = [],
    ): array {
        /** @var list<array{deviceType: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}> $result */
        $result = $this->fetchSessionsWithDimension($websiteId, $apiKey, 'devices', 'deviceType', $from, $to, $previousFrom, $previousTo, 12, $pageUrlFilter);
        return $result;
    }

    /**
     * @param list<string> $pageUrlFilter Parts of page URLs, see AnalyticsQueryUtility::pageUrlWhere()
     * @return list<array{browserName: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}>
     * @throws AnalyticsApiException
     */
    public function fetchBrowserSessions(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?\DateTimeImmutable $previousFrom = null,
        ?\DateTimeImmutable $previousTo = null,
        array $pageUrlFilter = [],
    ): array {
        /** @var list<array{browserName: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}> $result */
        $result = $this->fetchSessionsWithDimension($websiteId, $apiKey, 'browsers', 'browserName', $from, $to, $previousFrom, $previousTo, 6, $pageUrlFilter);
        return $result;
    }

    /**
     * @param list<string> $pageUrlFilter Parts of page URLs, see AnalyticsQueryUtility::pageUrlWhere()
     * @return list<array{countryCode: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}>
     * @throws AnalyticsApiException
     */
    public function fetchCountrySessions(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?\DateTimeImmutable $previousFrom = null,
        ?\DateTimeImmutable $previousTo = null,
        array $pageUrlFilter = [],
    ): array {
        /** @var list<array{countryCode: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}> $result */
        $result = $this->fetchSessionsWithDimension($websiteId, $apiKey, 'countryCode', 'countryCode', $from, $to, $previousFrom, $previousTo, 6, $pageUrlFilter);
        return $result;
    }

    /**
     * @param list<string> $pageUrlFilter Parts of page URLs, see AnalyticsQueryUtility::pageUrlWhere()
     * @return list<array<string, mixed>>
     * @throws AnalyticsApiException
     */
    private function fetchSessionsWithDimension(
        string $websiteId,
        string $apiKey,
        string $traceParam,
        string $dimension,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?\DateTimeImmutable $previousFrom,
        ?\DateTimeImmutable $previousTo,
        int $pageSize,
        array $pageUrlFilter = [],
    ): array {
        $metrics = ['sessionCount', 'sessionPercentOfTotal'];
        $json = [
            'where' => AnalyticsQueryUtility::pageUrlWhere($pageUrlFilter),
            'metrics' => $metrics,
            'dimensions' => [$dimension],
            'order' => [['member' => 'sessionPercentOfTotal', 'direction' => 'desc']],
            'dateRange' => AnalyticsQueryUtility::utcDayRange($from, $to),
            'pagination' => ['page' => 1, 'pageSize' => $pageSize],
        ];

        if ($previousFrom !== null && $previousTo !== null) {
            $json['metrics'][] = 'previousSessionCount';
            $json['previousDateRange'] = AnalyticsQueryUtility::utcDayRange($previousFrom, $previousTo);
        }

        $body = $this->postJson($this->websiteUrl($websiteId, 'analytics/sessions?trace=' . $traceParam), $apiKey, $json);
        $rows = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        return array_values($rows);
    }

    /**
     * Site-wide metrics for the current and the previous period plus the daily series of the
     * current period, with 5 parallel requests. Visits, visitors and bounce rate come from the
     * sessions endpoint. The time on page comes from the page view duration graph, the same
     * metric as in the page module. All requests use the same UTC day range, so the totals
     * match the daily series.
     *
     * A failed request leaves its values at 0 (or the series empty) and adds its key to
     * "failures": current, previous, series, currentTimeOnPage, previousTimeOnPage.
     *
     * @param list<string> $pageUrlFilter Parts of page URLs, see AnalyticsQueryUtility::pageUrlWhere()
     * @return array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgTimeOnPage: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgTimeOnPage: int},
     *     series: array{dates: list<string>, visits: list<int>, visitors: list<int>, bounceRate: list<float>, avgTimeOnPage: list<int>},
     *     failures: array<string, string>
     * }
     */
    public function fetchSitePerformance(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $previousFrom,
        \DateTimeImmutable $previousTo,
        array $pageUrlFilter = [],
    ): array {
        $buildSessionsOptions = fn (\DateTimeImmutable $f, \DateTimeImmutable $t, array $dimensions = []): array => $this->buildRequestOptions($apiKey, [
            'where' => AnalyticsQueryUtility::pageUrlWhere($pageUrlFilter),
            'metrics' => self::SITE_SESSION_METRICS,
            ...$dimensions,
            'dateRange' => AnalyticsQueryUtility::utcDayRange($f, $t),
        ]);
        $timeOnPageOptions = $this->buildRequestOptions($apiKey, ['where' => AnalyticsQueryUtility::pageUrlWhere($pageUrlFilter)]);

        $client = $this->guzzleClientFactory->getClient();
        $promises = [
            'current' => $client->requestAsync('POST', $this->buildSessionsUrl($websiteId, 'sitePerformanceDashboard'), $buildSessionsOptions($from, $to)),
            'previous' => $client->requestAsync('POST', $this->buildSessionsUrl($websiteId, 'sitePerformanceDashboard'), $buildSessionsOptions($previousFrom, $previousTo)),
            'series' => $client->requestAsync('POST', $this->buildSessionsUrl($websiteId, 'sitePerformanceDaily'), $buildSessionsOptions($from, $to, ['dimensions' => ['date'], 'granularity' => 'day'])),
            'currentTimeOnPage' => $client->requestAsync('POST', $this->buildTimeOnPageUrl($websiteId, $from, $to), $timeOnPageOptions),
            'previousTimeOnPage' => $client->requestAsync('POST', $this->buildTimeOnPageUrl($websiteId, $previousFrom, $previousTo), $timeOnPageOptions),
        ];

        /** @var array<string, array{state: string, value?: \Psr\Http\Message\ResponseInterface, reason?: \Throwable}> $settled */
        $settled = Utils::settle($promises)->wait();

        $bodies = [];
        $failures = [];
        foreach ($settled as $key => $result) {
            try {
                $bodies[$key] = AnalyticsResponseUtility::decodeSettledResponse($result);
            } catch (\Throwable $e) {
                $failures[$key] = $this->exceptionExtractor->extractReason($e);
            }
        }

        $empty = ['visitCount' => 0, 'visitorCount' => 0, 'bounceRate' => 0.0];
        $currentTimeOnPage = isset($bodies['currentTimeOnPage']) ? AnalyticsResponseUtility::timeOnPageGraph($bodies['currentTimeOnPage']) : null;
        $previousTimeOnPage = isset($bodies['previousTimeOnPage']) ? AnalyticsResponseUtility::timeOnPageGraph($bodies['previousTimeOnPage']) : null;

        return [
            'current' => (isset($bodies['current']) ? AnalyticsResponseUtility::sessionTotals($bodies['current']) : $empty) + ['avgTimeOnPage' => $currentTimeOnPage['average'] ?? 0],
            'previous' => (isset($bodies['previous']) ? AnalyticsResponseUtility::sessionTotals($bodies['previous']) : $empty) + ['avgTimeOnPage' => $previousTimeOnPage['average'] ?? 0],
            'series' => AnalyticsResponseUtility::dailySeries($bodies['series'] ?? [], $currentTimeOnPage),
            'failures' => $failures,
        ];
    }

    /**
     * Daily graph of a website over full UTC days, for example "visits/graph".
     *
     * @param list<string> $pageUrlFilter
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, total: int}>}
     * @throws AnalyticsApiException
     */
    private function fetchGraph(
        string $websiteId,
        string $apiKey,
        string $graphPath,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $pageUrlFilter,
    ): array {
        $body = $this->postJson(
            $this->websiteUrl($websiteId, $graphPath . AnalyticsQueryUtility::utcDayGraphQuery($from, $to)),
            $apiKey,
            ['where' => AnalyticsQueryUtility::pageUrlWhere($pageUrlFilter)]
        );
        return AnalyticsResponseUtility::graph($body['payload'] ?? null);
    }

    /**
     * Sends a POST request with a JSON body and decodes the JSON response.
     *
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     * @throws AnalyticsApiException
     */
    private function postJson(string $url, string $apiKey, array $json): array
    {
        try {
            $response = $this->requestFactory->request($url, 'POST', $this->buildRequestOptions($apiKey, $json));
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return is_array($body) ? $body : [];
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
    }

    /**
     * @param string $path Path below the website, with query if needed, for example "visits/graph?…"
     */
    private function websiteUrl(string $websiteId, string $path): string
    {
        return $this->apiConfiguration->getAnalyticsApiBaseUrl() . '/v2/websites/' . rawurlencode($websiteId) . '/' . $path;
    }

    private function buildSessionsUrl(string $websiteId, string $trace): string
    {
        return $this->websiteUrl($websiteId, 'analytics/sessions?trace=' . rawurlencode($trace));
    }

    /**
     * Site-wide page view duration graph: the same metric as "Avg. time on page" in the
     * page module, without the pageUrl filter.
     */
    private function buildTimeOnPageUrl(string $websiteId, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        return $this->websiteUrl($websiteId, 'stats/average-page-view-duration/graph' . AnalyticsQueryUtility::utcDayGraphQuery($from, $to));
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private function buildRequestOptions(string $apiKey, array $json): array
    {
        return array_merge(
            [
                'headers' => ['X-Api-Key' => $apiKey, 'Accept' => 'application/json'],
                'json' => $json,
            ],
            $this->apiConfiguration->getRequestOptions()
        );
    }
}
