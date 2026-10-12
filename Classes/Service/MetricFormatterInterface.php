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

    public function formatAbsoluteCountTrend(int $current, int $previous): string;

    public function formatAbsolutePercentPointTrend(float $current, float $previous): string;

    public function formatAbsoluteDurationTrend(int $current, int $previous): string;

    public function trendDirection(float $currentValue, float $previousValue): string;

    public function formatShare(int $value, int $total): string;

    public function formatPercentageChange(int $current, int $previous): ?string;

    public function formatAbsoluteChange(int $current, int $previous): ?string;

    /**
     * Date in the locale of the backend user, for example "12.10.2026" (de) or "Oct 12, 2026" (en).
     */
    public function formatDate(\DateTimeInterface $date): string;

    /**
     * Date and time in the locale of the backend user.
     */
    public function formatDateTime(\DateTimeInterface $date): string;

    /**
     * Day and month without year for chart axes, for example "02.10." (de) or "10/02" (en).
     */
    public function formatDayMonth(\DateTimeInterface $date): string;
}
