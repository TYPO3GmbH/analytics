<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use GuzzleHttp\Promise\Utils;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;

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
            'dateRange' => $this->buildUtcDayRange($from, $to),
        ];

        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getAnalyticsApiBaseUrl() . '/v2/websites/' . rawurlencode($websiteId) . '/analytics/pages?trace=pagesDashboard',
                'POST',
                array_merge(
                    [
                        'headers' => [
                            'X-Api-Key' => $apiKey,
                            'Accept' => 'application/json',
                        ],
                        'json' => $payload,
                    ],
                    $this->apiConfiguration->getRequestOptions()
                )
            );
            /** @var array<string, mixed> $body */
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rows = is_array($body['payload'] ?? null) ? $body['payload'] : [];
            return $rows[0] ?? null;
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
    }

    /**
     * Fetches top pages ordered by visit count (no page-URL filter).
     *
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
    ): array {
        $payload = [
            'metrics' => ['visitCount', 'previousVisitCount', 'visitPercentOfTotal', 'visitCountPercentageChange'],
            'dimensions' => ['pageUrl'],
            'order' => [['member' => 'visitCount', 'direction' => 'desc']],
            'pagination' => ['page' => 1, 'pageSize' => $limit],
            'where' => ['and' => []],
            'dateRange' => [
                'start' => $from->format('Y-m-d\T00:00:00.000P'),
                'end' => $to->format('Y-m-d\T23:59:59.999P'),
            ],
            'previousDateRange' => [
                'start' => $previousFrom->format('Y-m-d\T00:00:00.000P'),
                'end' => $previousTo->format('Y-m-d\T23:59:59.999P'),
            ],
        ];

        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getAnalyticsApiBaseUrl() . '/v2/websites/' . rawurlencode($websiteId) . '/analytics/pages?trace=topPagesDashboard',
                'POST',
                array_merge(
                    [
                        'headers' => [
                            'X-Api-Key' => $apiKey,
                            'Accept' => 'application/json',
                        ],
                        'json' => $payload,
                    ],
                    $this->apiConfiguration->getRequestOptions()
                )
            );
            /** @var array<string, mixed> $body */
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rows = is_array($body['payload'] ?? null) ? $body['payload'] : [];
            return array_values($rows);
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
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
        $websiteBase = $this->apiConfiguration->getAnalyticsApiBaseUrl() . '/v2/websites/' . rawurlencode($websiteId) . '/';
        $query = $this->buildUtcDayGraphQuery($from, $to);
        $body = ['where' => ['and' => [
            ['member' => 'pageUrl', 'operator' => 'eq', 'values' => [$pageUrl]],
        ]]];
        $options = array_merge(
            [
                'headers' => ['X-Api-Key' => $apiKey, 'Accept' => 'application/json'],
                'json' => $body,
            ],
            $this->apiConfiguration->getRequestOptions()
        );

        $client = $this->guzzleClientFactory->getClient();
        $promises = [
            'visits' => $client->requestAsync('POST', $websiteBase . 'visits/graph' . $query, $options),
            'bounceRate' => $client->requestAsync('POST', $websiteBase . 'stats/bounce-rate/graph' . $query, $options),
            'avgDuration' => $client->requestAsync('POST', $websiteBase . 'stats/average-page-view-duration/graph' . $query, $options),
        ];

        /** @var array<string, array{state: string, value?: \Psr\Http\Message\ResponseInterface, reason?: \Throwable}> $settled */
        $settled = Utils::settle($promises)->wait();

        $visits = [];
        $bounceRate = [];
        $avgDuration = [];
        $dates = [];
        $failures = [];

        foreach ($settled as $key => $result) {
            if ($result['state'] !== 'fulfilled') {
                $failures[$key] = $this->exceptionExtractor->extractReason($result['reason'] ?? new \RuntimeException('Unknown error'));
                continue;
            }
            try {
                if (!isset($result['value'])) {
                    throw new \RuntimeException('Fulfilled without response body');
                }
                $parsed = $this->parseTimeSeriesData((string)$result['value']->getBody());
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
        $query = $this->buildUtcDayGraphQuery($from, $to);
        $body = ['where' => ['and' => [
            ['member' => 'pageUrl', 'operator' => 'eq', 'values' => [$pageUrl]],
        ]]];

        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getAnalyticsApiBaseUrl() . '/v2/websites/' . rawurlencode($websiteId) . '/' . $graphPath . $query,
                'POST',
                array_merge(
                    [
                        'headers' => ['X-Api-Key' => $apiKey, 'Accept' => 'application/json'],
                        'json' => $body,
                    ],
                    $this->apiConfiguration->getRequestOptions()
                )
            );
            return $this->parseTimeSeriesData((string)$response->getBody())['data'];
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
    }

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, total: int}>}
     * @throws AnalyticsApiException
     */
    public function fetchSiteVisitsGraph(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $query = '?' . http_build_query([
            'from' => $from->format('Y-m-d\T00:00:00.000P'),
            'until' => $to->format('Y-m-d\T23:59:59.999P'),
            'type' => 'time-series',
            'unit' => 'day',
        ]);

        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getAnalyticsApiBaseUrl() . '/v2/websites/' . rawurlencode($websiteId) . '/visits/graph' . $query,
                'POST',
                array_merge(
                    [
                        'headers' => ['X-Api-Key' => $apiKey, 'Accept' => 'application/json'],
                        'json' => ['where' => ['and' => []]],
                    ],
                    $this->apiConfiguration->getRequestOptions()
                )
            );
            /** @var array{payload?: array{labels?: list<string>, datasets?: list<array{label: string, data: list<int>, total: int}>}} $body */
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $payload = $body['payload'] ?? [];
            return [
                'labels' => $payload['labels'] ?? [],
                'datasets' => $payload['datasets'] ?? [],
            ];
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
    }

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, total: int}>}
     * @throws AnalyticsApiException
     */
    public function fetchSiteSessionsGraph(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $query = '?' . http_build_query([
            'from' => $from->format('Y-m-d\T00:00:00.000P'),
            'until' => $to->format('Y-m-d\T23:59:59.999P'),
            'type' => 'time-series',
            'unit' => 'day',
        ]);

        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getAnalyticsApiBaseUrl() . '/v2/websites/' . rawurlencode($websiteId) . '/sessions/all/graph' . $query,
                'POST',
                array_merge(
                    [
                        'headers' => ['X-Api-Key' => $apiKey, 'Accept' => 'application/json'],
                        'json' => ['where' => ['and' => []]],
                    ],
                    $this->apiConfiguration->getRequestOptions()
                )
            );
            /** @var array{payload?: array{labels?: list<string>, datasets?: list<array{label: string, data: list<int>, total: int}>}} $body */
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $payload = $body['payload'] ?? [];
            return [
                'labels' => $payload['labels'] ?? [],
                'datasets' => $payload['datasets'] ?? [],
            ];
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
    }

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, total: int}>}
     * @throws AnalyticsApiException
     */
    public function fetchSiteVisitorsGraph(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $query = '?' . http_build_query([
            'from' => $from->format('Y-m-d\T00:00:00.000P'),
            'until' => $to->format('Y-m-d\T23:59:59.999P'),
            'type' => 'time-series',
            'unit' => 'day',
        ]);

        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getAnalyticsApiBaseUrl() . '/v2/websites/' . rawurlencode($websiteId) . '/visitors/all/graph' . $query,
                'POST',
                array_merge(
                    [
                        'headers' => ['X-Api-Key' => $apiKey, 'Accept' => 'application/json'],
                        'json' => ['where' => ['and' => []]],
                    ],
                    $this->apiConfiguration->getRequestOptions()
                )
            );
            /** @var array{payload?: array{labels?: list<string>, datasets?: list<array{label: string, data: list<int>, total: int}>}} $body */
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $payload = $body['payload'] ?? [];
            return [
                'labels' => $payload['labels'] ?? [],
                'datasets' => $payload['datasets'] ?? [],
            ];
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
    }

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, total: int}>}
     * @throws AnalyticsApiException
     */
    public function fetchTrafficShareInDepth(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $query = '?' . http_build_query([
            'from' => $from->format('Y-m-d\T00:00:00.000P'),
            'until' => $to->format('Y-m-d\T23:59:59.999P'),
            'unit' => 'day',
        ]);

        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getAnalyticsApiBaseUrl() . '/v2/websites/' . rawurlencode($websiteId) . '/traffic-api/traffic-share/in-depth' . $query,
                'POST',
                array_merge(
                    [
                        'headers' => ['X-Api-Key' => $apiKey, 'Accept' => 'application/json'],
                        'json' => ['where' => ['and' => []]],
                    ],
                    $this->apiConfiguration->getRequestOptions()
                )
            );
            /** @var array{labels?: list<string>, datasets?: list<array{label: string, data: list<int>, total: int}>} $body */
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return [
                'labels' => $body['labels'] ?? [],
                'datasets' => $body['datasets'] ?? [],
            ];
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
    }

    /**
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
    ): array {
        /** @var list<array{deviceType: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}> $result */
        $result = $this->fetchSessionsWithDimension($websiteId, $apiKey, 'devices', 'deviceType', $from, $to, $previousFrom, $previousTo, 12);
        return $result;
    }

    /**
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
    ): array {
        /** @var list<array{browserName: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}> $result */
        $result = $this->fetchSessionsWithDimension($websiteId, $apiKey, 'browsers', 'browserName', $from, $to, $previousFrom, $previousTo, 6);
        return $result;
    }

    /**
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
    ): array {
        /** @var list<array{countryCode: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}> $result */
        $result = $this->fetchSessionsWithDimension($websiteId, $apiKey, 'countryCode', 'countryCode', $from, $to, $previousFrom, $previousTo, 6);
        return $result;
    }

    /**
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
    ): array {
        $metrics = ['sessionCount', 'sessionPercentOfTotal'];
        $json = [
            'where' => ['and' => []],
            'metrics' => $metrics,
            'dimensions' => [$dimension],
            'order' => [['member' => 'sessionPercentOfTotal', 'direction' => 'desc']],
            'dateRange' => [
                'start' => $from->format('Y-m-d\T00:00:00.000P'),
                'end' => $to->format('Y-m-d\T23:59:59.999P'),
            ],
            'pagination' => ['page' => 1, 'pageSize' => $pageSize],
        ];

        if ($previousFrom !== null && $previousTo !== null) {
            $json['metrics'][] = 'previousSessionCount';
            $json['previousDateRange'] = [
                'start' => $previousFrom->format('Y-m-d\T00:00:00.000P'),
                'end' => $previousTo->format('Y-m-d\T23:59:59.999P'),
            ];
        }

        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getAnalyticsApiBaseUrl() . '/v2/websites/' . rawurlencode($websiteId) . '/analytics/sessions?trace=' . $traceParam,
                'POST',
                array_merge(
                    [
                        'headers' => ['X-Api-Key' => $apiKey, 'Accept' => 'application/json'],
                        'json' => $json,
                    ],
                    $this->apiConfiguration->getRequestOptions()
                )
            );
            /** @var array{payload?: list<array<string, mixed>>} $body */
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rows = is_array($body['payload'] ?? null) ? $body['payload'] : [];
            return array_values($rows);
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
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
    ): array {
        $buildSessionsOptions = fn (\DateTimeImmutable $f, \DateTimeImmutable $t, array $dimensions = []): array => $this->buildRequestOptions($apiKey, [
            'where' => ['and' => []],
            'metrics' => self::SITE_SESSION_METRICS,
            ...$dimensions,
            'dateRange' => $this->buildUtcDayRange($f, $t),
        ]);
        $timeOnPageOptions = $this->buildRequestOptions($apiKey, ['where' => ['and' => []]]);

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
                $bodies[$key] = $this->decodeSettledResponse($result);
            } catch (\Throwable $e) {
                $failures[$key] = $this->exceptionExtractor->extractReason($e);
            }
        }

        $empty = ['visitCount' => 0, 'visitorCount' => 0, 'bounceRate' => 0.0];
        $currentTimeOnPage = isset($bodies['currentTimeOnPage']) ? $this->parseTimeOnPageGraph($bodies['currentTimeOnPage']) : null;
        $previousTimeOnPage = isset($bodies['previousTimeOnPage']) ? $this->parseTimeOnPageGraph($bodies['previousTimeOnPage']) : null;

        return [
            'current' => (isset($bodies['current']) ? $this->parseSessionTotals($bodies['current']) : $empty) + ['avgTimeOnPage' => $currentTimeOnPage['average'] ?? 0],
            'previous' => (isset($bodies['previous']) ? $this->parseSessionTotals($bodies['previous']) : $empty) + ['avgTimeOnPage' => $previousTimeOnPage['average'] ?? 0],
            'series' => $this->buildDailySeries($bodies['series'] ?? [], $currentTimeOnPage),
            'failures' => $failures,
        ];
    }

    private function buildSessionsUrl(string $websiteId, string $trace): string
    {
        return $this->apiConfiguration->getAnalyticsApiBaseUrl()
            . '/v2/websites/' . rawurlencode($websiteId)
            . '/analytics/sessions?trace=' . rawurlencode($trace);
    }

    /**
     * Site-wide page view duration graph: the same metric as "Avg. time on page" in the
     * page module, without the pageUrl filter.
     */
    private function buildTimeOnPageUrl(string $websiteId, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        return $this->apiConfiguration->getAnalyticsApiBaseUrl()
            . '/v2/websites/' . rawurlencode($websiteId)
            . '/stats/average-page-view-duration/graph' . $this->buildUtcDayGraphQuery($from, $to);
    }

    /**
     * The analytics and graph endpoints bucket by UTC day. A range with a local offset
     * adds a partial first bucket, so the range uses full UTC days. Totals use the same
     * range, so they match the daily series.
     *
     * @return array{start: string, end: string}
     */
    private function buildUtcDayRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [
            'start' => $from->format('Y-m-d\T00:00:00.000\Z'),
            'end' => $to->format('Y-m-d\T23:59:59.999\Z'),
        ];
    }

    /**
     * Query of a daily graph endpoint over full UTC days, see buildUtcDayRange().
     */
    private function buildUtcDayGraphQuery(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $range = $this->buildUtcDayRange($from, $to);
        return '?' . http_build_query([
            'from' => $range['start'],
            'until' => $range['end'],
            'type' => 'time-series',
            'unit' => 'day',
        ]);
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

    /**
     * @param array{state: string, value?: \Psr\Http\Message\ResponseInterface, reason?: \Throwable} $result
     * @return array<string, mixed>
     * @throws \Throwable
     */
    private function decodeSettledResponse(array $result): array
    {
        if ($result['state'] !== 'fulfilled' || !isset($result['value'])) {
            throw $result['reason'] ?? new \RuntimeException('Unknown error');
        }
        $body = json_decode((string)$result['value']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return is_array($body) ? $body : [];
    }

    /**
     * Without dimensions the payload holds one row with the totals of the range.
     *
     * @param array<string, mixed> $body
     * @return array{visitCount: int, visitorCount: int, bounceRate: float}
     */
    private function parseSessionTotals(array $body): array
    {
        $row = is_array($body['payload'] ?? null) ? ($body['payload'][0] ?? []) : [];
        return $this->parseSessionMetrics(is_array($row) ? $row : []);
    }

    /**
     * @param array<string, mixed> $sessionsBody
     * @param array{dates: list<string>, values: list<int>, average: int}|null $timeOnPage
     * @return array{dates: list<string>, visits: list<int>, visitors: list<int>, bounceRate: list<float>, avgTimeOnPage: list<int>}
     */
    private function buildDailySeries(array $sessionsBody, ?array $timeOnPage): array
    {
        $series = ['dates' => [], 'visits' => [], 'visitors' => [], 'bounceRate' => [], 'avgTimeOnPage' => []];
        // Both endpoints bucket by UTC day, so the date is the common key of the two series.
        $timeOnPageByDate = $timeOnPage !== null ? array_combine($timeOnPage['dates'], $timeOnPage['values']) : [];

        foreach (is_array($sessionsBody['payload'] ?? null) ? $sessionsBody['payload'] : [] as $row) {
            if (!is_array($row) || !is_string($row['date'] ?? null)) {
                continue;
            }
            $date = substr($row['date'], 0, 10);
            $metrics = $this->parseSessionMetrics($row);
            $series['dates'][] = $date;
            $series['visits'][] = $metrics['visitCount'];
            $series['visitors'][] = $metrics['visitorCount'];
            $series['bounceRate'][] = $metrics['bounceRate'];
            $series['avgTimeOnPage'][] = $timeOnPageByDate[$date] ?? 0;
        }
        return $series;
    }

    /**
     * @param array<mixed> $row
     * @return array{visitCount: int, visitorCount: int, bounceRate: float}
     */
    private function parseSessionMetrics(array $row): array
    {
        return [
            'visitCount' => (int)($row['visitCount'] ?? 0),
            'visitorCount' => (int)($row['visitorCount'] ?? 0),
            'bounceRate' => (float)($row['bounceRate'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{dates: list<string>, values: list<int>, average: int}
     */
    private function parseTimeOnPageGraph(array $body): array
    {
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $dataset = is_array($payload['datasets'][0] ?? null) ? $payload['datasets'][0] : [];
        $labels = is_array($payload['labels'] ?? null) ? array_values($payload['labels']) : [];
        $data = is_array($dataset['data'] ?? null) ? array_values($dataset['data']) : [];
        $count = min(count($labels), count($data));
        return [
            'dates' => array_map(static fn (mixed $label): string => substr((string)$label, 0, 10), array_slice($labels, 0, $count)),
            'values' => array_map(static fn (mixed $value): int => (int)round(is_numeric($value) ? (float)$value : 0.0), array_slice($data, 0, $count)),
            'average' => (int)round(is_numeric($dataset['totalAverage'] ?? null) ? (float)$dataset['totalAverage'] : 0.0),
        ];
    }

    /** @return list<int|float> */
    /**
     * @return array{data: list<int|float>, labels: list<string>}
     */
    private function parseTimeSeriesData(string $rawBody): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $datasets = is_array($payload['datasets'] ?? null) ? $payload['datasets'] : [];
        $rawData = is_array($datasets[0]['data'] ?? null) ? $datasets[0]['data'] : [];
        $rawLabels = is_array($payload['labels'] ?? null) ? $payload['labels'] : [];
        return [
            'data' => array_values(array_map(static fn (mixed $v): int|float => is_numeric($v) ? (float)$v : 0, $rawData)),
            'labels' => array_values(array_map(static fn (mixed $v): string => (string)$v, $rawLabels)),
        ];
    }
}
