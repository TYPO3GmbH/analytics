<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface MetricFormatterInterface
{
    public function formatNumber(int $value): string;

    public function formatSignedNumber(float $value): string;

    public function formatPercentage(float $value): string;

    public function formatPercentageWidth(float $value): string;

    public function formatDuration(int $seconds): string;

    public function formatRelativeTrend(float $currentValue, float $previousValue): string;

    public function formatInvertedRelativeTrend(float $currentValue, float $previousValue): string;

    public function trendDirection(float $currentValue, float $previousValue): string;

    public function formatShare(int $value, int $total): string;

    public function formatPercentageChange(int $current, int $previous): ?string;

    public function formatAbsoluteChange(int $current, int $previous): ?string;
}
