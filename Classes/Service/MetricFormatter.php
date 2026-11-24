<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Localization\LanguageService;

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

    public function formatAbsoluteCountTrend(int $current, int $previous): string
    {
        $diff = $current - $previous;
        if ($diff === 0) {
            return '±0';
        }
        return ($diff > 0 ? '+' : '') . number_format($diff, 0, '.', '.');
    }

    public function formatAbsolutePercentPointTrend(float $current, float $previous): string
    {
        $diff = $current - $previous;
        if (round($diff, 2) === 0.0) {
            return '±0.00%';
        }
        return ($diff > 0 ? '+' : '') . number_format($diff, 2) . '%';
    }

    public function formatAbsoluteDurationTrend(int $current, int $previous): string
    {
        $diff = $current - $previous;
        if ($diff === 0) {
            return '±0s';
        }
        $abs = abs($diff);
        $sign = $diff > 0 ? '+' : '-';
        return $abs < 60
            ? $sign . $abs . 's'
            : $sign . intdiv($abs, 60) . ':' . str_pad((string)($abs % 60), 2, '0', STR_PAD_LEFT);
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

    public function formatAbsoluteChange(int $current, int $previous): ?string
    {
        $diff = $current - $previous;
        if ($diff === 0) {
            return null;
        }
        return ($diff > 0 ? '+' : '') . $diff;
    }

    public function formatDate(\DateTimeInterface $date): string
    {
        return $this->formatWithIntl($date, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);
    }

    public function formatDateTime(\DateTimeInterface $date): string
    {
        return $this->formatWithIntl($date, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT);
    }

    public function formatDayMonth(\DateTimeInterface $date): string
    {
        $locale = $this->locale();
        $pattern = (new \IntlDatePatternGenerator($locale))->getBestPattern('ddMM');
        return $this->formatWithIntl($date, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $pattern === false ? 'dd.MM.' : $pattern);
    }

    /**
     * Formats in the timezone of the date itself, so the day stays the one the date was created for.
     */
    private function formatWithIntl(\DateTimeInterface $date, int $dateType, int $timeType, ?string $pattern = null): string
    {
        $formatter = new \IntlDateFormatter($this->locale(), $dateType, $timeType, $this->intlTimeZone($date), null, $pattern);
        $formatted = $formatter->format($date);
        return $formatted === false ? $date->format('Y-m-d') : $formatted;
    }

    /**
     * ICU does not accept offset zones like "Z" or "+02:00" from ext/date, so they become "GMT+02:00".
     */
    private function intlTimeZone(\DateTimeInterface $date): string
    {
        $name = $date->getTimezone()->getName();
        if ($name === 'Z' || preg_match('/^[+-]\d{2}:\d{2}$/', $name) === 1) {
            $offset = $date->getOffset();
            return sprintf('GMT%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60));
        }
        return $name;
    }

    private function locale(): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $locale = $languageService instanceof LanguageService ? $languageService->getLocale() : null;
        return $locale !== null ? (string)$locale : 'en';
    }
}
