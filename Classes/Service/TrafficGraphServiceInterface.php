<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface TrafficGraphServiceInterface
{
    /**
     * @return array{labels: list<string>, data: list<int>}|null
     */
    public function loadGraphData(string $siteIdentifier, int $days): ?array;
}
