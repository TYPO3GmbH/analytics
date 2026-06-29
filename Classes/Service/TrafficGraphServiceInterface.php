<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface TrafficGraphServiceInterface
{
    /**
     * @return array{labels: list<string>, data: list<int>}|null
     */
    public function loadGraphData(string $siteIdentifier, int $days): ?array;

    /**
     * @return array{labels: list<string>, data: list<int>}|null
     */
    public function loadSessionsData(string $siteIdentifier, int $days): ?array;

    /**
     * @return array{labels: list<string>, data: list<int>}|null
     */
    public function loadVisitorsData(string $siteIdentifier, int $days): ?array;

    /**
     * Returns all three visitor datasets from a single API call.
     *
     * @return array{
     *     new: array{labels: list<string>, data: list<int>},
     *     returning: array{labels: list<string>, data: list<int>},
     *     overall: array{labels: list<string>, data: list<int>}
     * }|null
     */
    public function loadVisitorsBreakdownData(string $siteIdentifier, int $days): ?array;
}
