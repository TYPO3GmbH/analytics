<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard;

final class DashboardPeriods
{
    /** @var list<int> */
    private const FALLBACK_PERIODS = [7, 14, 30];
    private const FALLBACK_DEFAULT = 7;

    /** @return list<int> */
    public static function periods(): array
    {
        $raw = trim((string)(($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics'] ?? [])['dashboardPeriods'] ?? ''));
        if ($raw !== '') {
            $periods = array_values(array_filter(
                array_map('intval', array_map('trim', explode(',', $raw))),
                static fn (int $p) => $p > 0
            ));
            if ($periods !== []) {
                return $periods;
            }
        }
        return self::FALLBACK_PERIODS;
    }

    public static function sanitizePeriod(int $days): int
    {
        return in_array($days, self::periods(), true) ? $days : self::defaultPeriod();
    }

    public static function defaultPeriod(): int
    {
        $configured = (int)(($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics'] ?? [])['dashboardDefaultPeriod'] ?? 0);
        if ($configured > 0 && in_array($configured, self::periods(), true)) {
            return $configured;
        }
        return self::FALLBACK_DEFAULT;
    }
}
