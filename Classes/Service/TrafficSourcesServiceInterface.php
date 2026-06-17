<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface TrafficSourcesServiceInterface
{
    /**
     * @return array<string, int>|null keyed by channel label (direct, search, …), null on error or site not found
     */
    public function loadTrafficSources(string $siteIdentifier, int $days): ?array;

    /**
     * @return list<array{deviceType: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}>|null
     */
    public function loadDeviceData(string $siteIdentifier, int $days): ?array;

    /**
     * @return list<array{browserName: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}>|null
     */
    public function loadBrowserData(string $siteIdentifier, int $days): ?array;

    /**
     * @return list<array{countryCode: string, sessionCount: int, sessionPercentOfTotal: float, previousSessionCount?: int}>|null
     */
    public function loadCountryData(string $siteIdentifier, int $days): ?array;
}
