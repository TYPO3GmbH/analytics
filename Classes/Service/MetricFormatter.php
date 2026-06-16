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
        $formattedValue = rtrim(rtrim(number_format(abs($value), 1, '.', ''), '0'), '.');
        if ($formattedValue === '0') {
            return '±0';
        }
        return ($value >= 0 ? '+' : '-') . $formattedValue;
    }

    public function formatPercentage(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') . '%';
    }

    public function formatPercentageWidth(float $value): string
    {
        return rtrim(rtrim(number_format(max(0.0, min(100.0, $value)), 1, '.', ''), '0'), '.');
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
}
