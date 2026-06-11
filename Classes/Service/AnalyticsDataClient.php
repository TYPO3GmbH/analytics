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
            'dateRange' => [
                'start' => $from->format('Y-m-d\T00:00:00.000P'),
                'end' => $to->format('Y-m-d\T23:59:59.999P'),
            ],
            'segments' => [],
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
        $query = '?' . http_build_query([
            'from' => $from->format('Y-m-d\T00:00:00.000P'),
            'until' => $to->format('Y-m-d\T23:59:59.999P'),
            'type' => 'time-series',
            'unit' => 'day',
        ]);
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
                $data = $this->parseTimeSeriesData((string)$result['value']->getBody());
                match ($key) {
                    'visits' => $visits = $data,
                    'bounceRate' => $bounceRate = $data,
                    'avgDuration' => $avgDuration = $data,
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
        $query = '?' . http_build_query([
            'from' => $from->format('Y-m-d\T00:00:00.000P'),
            'until' => $to->format('Y-m-d\T23:59:59.999P'),
            'type' => 'time-series',
            'unit' => 'day',
        ]);
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
            return $this->parseTimeSeriesData((string)$response->getBody());
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
    }

    /** @return list<int|float> */
    private function parseTimeSeriesData(string $rawBody): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $datasets = is_array($payload['datasets'] ?? null) ? $payload['datasets'] : [];
        $data = is_array($datasets[0]['data'] ?? null) ? $datasets[0]['data'] : [];
        return array_values(array_map(static fn (mixed $v): int|float => is_numeric($v) ? (float)$v : 0, $data));
    }
}
