<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use T3G\Analytics\Exception\AnalyticsApiException;

interface AnalyticsDataClientInterface
{
    /**
     * @return array<string, mixed>|null
     * @throws AnalyticsApiException
     */
    public function fetchPageAnalytics(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): ?array;

    /**
     * @return list<int|float>
     * @throws AnalyticsApiException
     */
    public function fetchVisitsTimeSeries(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array;

    /**
     * @return list<int|float>
     * @throws AnalyticsApiException
     */
    public function fetchBounceRateTimeSeries(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array;

    /**
     * @return list<int|float>
     * @throws AnalyticsApiException
     */
    public function fetchAvgDurationTimeSeries(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array;

    /**
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
    ): array;

    /**
     * @return array{visits: list<int|float>, bounceRate: list<int|float>, avgDuration: list<int|float>, dates: list<string>, failures: array<string, string>}
     */
    public function fetchAllTimeSeries(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array;

    /**
     * Fetches site-wide daily visit counts as a time-series (POST, no page filter).
     *
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, total: int}>}
     * @throws AnalyticsApiException
     */
    public function fetchSiteVisitsGraph(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array;

    /**
     * Fetches traffic share by channel for a date range.
     *
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, total: int}>}
     * @throws AnalyticsApiException
     */
    public function fetchTrafficShareInDepth(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array;

    /**
     * Fetches device session breakdown (desktop/mobile/tablet).
     * When previousFrom/previousTo are provided, the response includes previousSessionCount per item.
     *
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
    ): array;

    /**
     * Fetches browser session breakdown (Chrome, Safari, Firefox, …).
     * When previousFrom/previousTo are provided, the response includes previousSessionCount per item.
     *
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
    ): array;

    /**
     * Fetches country session breakdown by ISO 3166-1 alpha-2 country code.
     * When previousFrom/previousTo are provided, the response includes previousSessionCount per item.
     *
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
    ): array;

    /**
     * Fetches site-wide performance metrics for two date ranges via analytics/pages (2 parallel requests).
     *
     * @return array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
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
    ): array;
}
