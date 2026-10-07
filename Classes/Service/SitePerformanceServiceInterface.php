<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface SitePerformanceServiceInterface
{
    /**
     * @return array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     series: array{dates: list<string>, visits: list<int>, visitors: list<int>}
     * }|null
     */
    public function loadPerformanceData(string $siteIdentifier, int $days): ?array;

    /**
     * @param array{
     *     current: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     previous: array{visitCount: int, visitorCount: int, bounceRate: float, avgDuration: int},
     *     series?: array{dates: list<string>, visits: list<int>, visitors: list<int>}
     * } $data
     * @return list<array{label: string, value: string, tone: string, icon: string, trend: string, trendDirection: string, trendLabel: string, details: list<string>|null, sparkline: string}>
     */
    public function buildMetricItems(array $data, string $visitsLabel, string $visitorsLabel, string $bounceRateLabel, string $avgDurationLabel, string $trendLabel, string $chartLabel = ''): array;
}
