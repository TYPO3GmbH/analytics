<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

readonly class MetricFormatter implements MetricFormatterInterface
{
    public function formatNumber(int $value): string
    {
        return number_format($value, 0, '.', '.');
    }

    public function formatSignedNumber(float $value): string
    {
        $formattedValue = number_format(abs($value), 2, '.', '');
        if ($formattedValue === '0.00') {
            return '±0';
        }
        return ($value >= 0 ? '+' : '-') . $formattedValue;
    }

    public function formatPercentage(float $value): string
    {
        return number_format($value, 2, '.', '') . '%';
    }

    public function formatPercentageWidth(float $value): string
    {
        return number_format(max(0.0, min(100.0, $value)), 2, '.', '');
    }

    public function formatDuration(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public function formatRelativeTrend(float $currentValue, float $previousValue): string
    {
        if ($previousValue === 0.0) {
            return '';
        }
        $formatted = $this->formatSignedNumber((($currentValue - $previousValue) / $previousValue) * 100);
        return $formatted === '±0' ? '' : $formatted . '%';
    }

    public function trendDirection(float $currentValue, float $previousValue): string
    {
        if ($currentValue === $previousValue) {
            return 'neutral';
        }
        return $currentValue > $previousValue ? 'up' : 'down';
    }

    public function formatShare(int $value, int $total): string
    {
        if ($total <= 0) {
            return '0.00';
        }
        return number_format(max(0.0, min(100.0, $value / $total * 100)), 2, '.', '');
    }

    public function formatPercentageChange(int $current, int $previous): ?string
    {
        if ($previous === 0) {
            return $current > 0 ? '+∞%' : null;
        }
        $change = ($current - $previous) / $previous * 100;
        return ($change >= 0.0 ? '+' : '') . number_format($change, 2, '.', '') . '%';
    }
}
