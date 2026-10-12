<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface SitePerformanceServiceInterface
{
    /**
     * @return array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgTimeOnPage: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgTimeOnPage: int}|null,
     *     series: array{dates: list<string>, visits: list<int>, visitors: list<int>, bounceRate: list<float>, avgTimeOnPage: list<int>}
     * }|null
     */
    public function loadPerformanceData(string $siteIdentifier, int $days): ?array;

    /**
     * @param array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgTimeOnPage: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgTimeOnPage: int}|null,
     *     series?: array{dates: list<string>, visits: list<int>, visitors: list<int>, bounceRate: list<float>, avgTimeOnPage: list<int>}
     * } $data
     * @return list<array{label: string, value: string, tone: string, icon: string, trend: string, trendDirection: string, trendLabel: string, trendNote: string, details: list<string>|null, chart: array{values: list<int|float>, labels: list<string>, label: string}|null}>
     */
    public function buildMetricItems(array $data, string $visitsLabel, string $visitorsLabel, string $bounceRateLabel, string $avgTimeOnPageLabel, string $trendLabel, string $chartLabel = '', string $percentagePointTrendLabel = ''): array;
}
