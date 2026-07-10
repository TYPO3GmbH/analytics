<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class DemoAnalyticsDataClient implements AnalyticsDataClientInterface
{
    public function __construct(private SiteFinder $siteFinder)
    {
    }

    public function fetchPageAnalytics(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $isPrevious = $to < new \DateTimeImmutable('today 00:00:00');
        return [
            'visitCount' => $isPrevious ? 890 : 1280,
            'bounceRate' => $isPrevious ? 46.5 : 38.2,
            'averageVisitDuration' => $isPrevious ? 74 : 96,
        ];
    }

    public function fetchVisitsTimeSeries(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->series($from, $to, 80, 18);
    }

    public function fetchBounceRateTimeSeries(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->series($from, $to, 42, -2, 24, 58);
    }

    public function fetchAvgDurationTimeSeries(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->series($from, $to, 70, 9, 35, 150);
    }

    public function fetchTopPages(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $previousFrom,
        \DateTimeImmutable $previousTo,
        int $limit = 10,
    ): array {
        $base = $this->siteBaseForWebsiteId($websiteId);
        return array_slice([
            ['pageUrl' => $base . '/', 'pageTitle' => 'Home', 'visitCount' => 1840, 'previousVisitCount' => 1510, 'visitPercentOfTotal' => 38.5, 'visitCountPercentageChange' => 21.85],
            ['pageUrl' => $base . '/products', 'pageTitle' => 'Products', 'visitCount' => 980, 'previousVisitCount' => 1120, 'visitPercentOfTotal' => 20.5, 'visitCountPercentageChange' => -12.50],
            ['pageUrl' => $base . '/pricing', 'pageTitle' => 'Pricing', 'visitCount' => 740, 'previousVisitCount' => 540, 'visitPercentOfTotal' => 15.5, 'visitCountPercentageChange' => 37.04],
            ['pageUrl' => $base . '/blog', 'pageTitle' => 'Blog', 'visitCount' => 520, 'previousVisitCount' => 510, 'visitPercentOfTotal' => 10.9, 'visitCountPercentageChange' => 1.96],
            ['pageUrl' => $base . '/contact', 'pageTitle' => 'Contact', 'visitCount' => 310, 'previousVisitCount' => 420, 'visitPercentOfTotal' => 6.5, 'visitCountPercentageChange' => -26.19],
            ['pageUrl' => $base . '/docs', 'pageTitle' => 'Docs', 'visitCount' => 260, 'previousVisitCount' => 120, 'visitPercentOfTotal' => 5.4, 'visitCountPercentageChange' => 116.67],
        ], 0, max(1, $limit));
    }

    private function siteBaseForWebsiteId(string $websiteId): string
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ((string)$site->getSettings()->get('externalWebsiteId', '') === $websiteId) {
                return rtrim((string)$site->getBase(), '/');
            }
        }
        return '';
    }

    public function fetchAllTimeSeries(
        string $websiteId,
        string $apiKey,
        string $pageUrl,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return [
            'visits' => $this->fetchVisitsTimeSeries($websiteId, $apiKey, $pageUrl, $from, $to),
            'bounceRate' => $this->fetchBounceRateTimeSeries($websiteId, $apiKey, $pageUrl, $from, $to),
            'avgDuration' => $this->fetchAvgDurationTimeSeries($websiteId, $apiKey, $pageUrl, $from, $to),
            'dates' => $this->labels($from, $to),
            'failures' => [],
        ];
    }

    public function fetchSiteVisitsGraph(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->graphPayload('Visits', $from, $to, $this->series($from, $to, 420, 60));
    }

    public function fetchSiteSessionsGraph(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->graphPayload('Sessions', $from, $to, $this->series($from, $to, 310, 42));
    }

    public function fetchSiteVisitorsGraph(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $newVisitors = $this->series($from, $to, 160, 22);
        $returningVisitors = $this->series($from, $to, 42, 18, 5);
        $overall = array_map(static fn (int $n, int $r): int => $n + $r, $newVisitors, $returningVisitors);
        $labels = $this->labels($from, $to);
        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => 'New Visitors', 'data' => $newVisitors, 'total' => array_sum($newVisitors)],
                ['label' => 'Returning Visitors', 'data' => $returningVisitors, 'total' => array_sum($returningVisitors)],
                ['label' => 'Visitors', 'data' => $overall, 'total' => array_sum($overall)],
            ],
        ];
    }

    public function fetchTrafficShareInDepth(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $isPrevious = $to < new \DateTimeImmutable('today 00:00:00');
        $totals = $isPrevious
            ? ['direct' => 380, 'search' => 560, 'social' => 210, 'email' => 95, 'paid' => 150, 'ai_traffic' => 40, 'unknown' => 75]
            : ['direct' => 460, 'search' => 720, 'social' => 180, 'email' => 130, 'paid' => 220, 'ai_traffic' => 96, 'unknown' => 54];

        return [
            'labels' => array_keys($totals),
            'datasets' => array_map(
                static fn (string $label, int $total): array => ['label' => $label, 'data' => [$total], 'total' => $total],
                array_keys($totals),
                array_values($totals),
            ),
        ];
    }

    public function fetchDeviceSessions(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?\DateTimeImmutable $previousFrom = null,
        ?\DateTimeImmutable $previousTo = null,
    ): array {
        return [
            ['deviceType' => 'desktop', 'sessionCount' => 920, 'sessionPercentOfTotal' => 54.1, 'previousSessionCount' => 760],
            ['deviceType' => 'mobile', 'sessionCount' => 650, 'sessionPercentOfTotal' => 38.2, 'previousSessionCount' => 710],
            ['deviceType' => 'tablet', 'sessionCount' => 130, 'sessionPercentOfTotal' => 7.7, 'previousSessionCount' => 90],
        ];
    }

    public function fetchBrowserSessions(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?\DateTimeImmutable $previousFrom = null,
        ?\DateTimeImmutable $previousTo = null,
    ): array {
        return [
            ['browserName' => 'Chrome', 'sessionCount' => 780, 'sessionPercentOfTotal' => 45.9, 'previousSessionCount' => 690],
            ['browserName' => 'Safari', 'sessionCount' => 410, 'sessionPercentOfTotal' => 24.1, 'previousSessionCount' => 460],
            ['browserName' => 'Firefox', 'sessionCount' => 240, 'sessionPercentOfTotal' => 14.1, 'previousSessionCount' => 190],
            ['browserName' => 'Edge', 'sessionCount' => 170, 'sessionPercentOfTotal' => 10.0, 'previousSessionCount' => 120],
            ['browserName' => 'Other', 'sessionCount' => 100, 'sessionPercentOfTotal' => 5.9, 'previousSessionCount' => 80],
        ];
    }

    public function fetchCountrySessions(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?\DateTimeImmutable $previousFrom = null,
        ?\DateTimeImmutable $previousTo = null,
    ): array {
        return [
            ['countryCode' => 'DE', 'sessionCount' => 620, 'sessionPercentOfTotal' => 36.5, 'previousSessionCount' => 510],
            ['countryCode' => 'US', 'sessionCount' => 360, 'sessionPercentOfTotal' => 21.2, 'previousSessionCount' => 420],
            ['countryCode' => 'AT', 'sessionCount' => 210, 'sessionPercentOfTotal' => 12.4, 'previousSessionCount' => 150],
            ['countryCode' => 'CH', 'sessionCount' => 190, 'sessionPercentOfTotal' => 11.2, 'previousSessionCount' => 120],
            ['countryCode' => 'GB', 'sessionCount' => 170, 'sessionPercentOfTotal' => 10.0, 'previousSessionCount' => 140],
            ['countryCode' => 'FR', 'sessionCount' => 150, 'sessionPercentOfTotal' => 8.8, 'previousSessionCount' => 90],
        ];
    }

    public function fetchSitePerformance(
        string $websiteId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $previousFrom,
        \DateTimeImmutable $previousTo,
    ): array {
        return [
            'current' => ['visitCount' => 4860, 'visitorCount' => 3120, 'bounceRate' => 37.4, 'avgDuration' => 68],
            'previous' => ['visitCount' => 3920, 'visitorCount' => 3360, 'bounceRate' => 44.8, 'avgDuration' => 92],
            'failures' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function labels(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $labels = [];
        foreach ($this->dateRange($from, $to) as $date) {
            $labels[] = $date->format('Y-m-d');
        }
        return $labels;
    }

    /**
     * @return list<int>
     */
    private function series(\DateTimeImmutable $from, \DateTimeImmutable $to, int $base, int $step, int $min = 0, int $max = 9999): array
    {
        $values = [];
        foreach ($this->dateRange($from, $to) as $index => $date) {
            $wave = (($index % 5) - 2) * $step;
            $weekdayBoost = in_array($date->format('N'), ['2', '3', '4'], true) ? $step : 0;
            $values[] = max($min, min($max, $base + $wave + $weekdayBoost + ($index * 3)));
        }
        return $values;
    }

    /**
     * @param list<int> $data
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, total: int}>}
     */
    private function graphPayload(string $label, \DateTimeImmutable $from, \DateTimeImmutable $to, array $data): array
    {
        return [
            'labels' => $this->labels($from, $to),
            'datasets' => [
                [
                    'label' => $label,
                    'data' => $data,
                    'total' => array_sum($data),
                ],
            ],
        ];
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function dateRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $dates = [];
        $current = $from->setTime(0, 0);
        $end = $to->setTime(0, 0);
        while ($current <= $end) {
            $dates[] = $current;
            $current = $current->modify('+1 day');
        }
        return $dates;
    }
}
