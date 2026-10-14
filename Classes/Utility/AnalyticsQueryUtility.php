<?php

declare(strict_types=1);

namespace T3G\Analytics\Utility;

/**
 * Builds the parts of an analytics API query: date ranges, graph queries and filters.
 */
final class AnalyticsQueryUtility
{
    /**
     * The analytics and graph endpoints bucket by UTC day. A range with a local offset
     * adds a partial first bucket, so the range uses full UTC days. Totals use the same
     * range, so they match the daily series.
     *
     * @return array{start: string, end: string}
     */
    public static function utcDayRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [
            'start' => $from->format('Y-m-d\T00:00:00.000\Z'),
            'end' => $to->format('Y-m-d\T23:59:59.999\Z'),
        ];
    }

    /**
     * Query of a daily graph endpoint over full UTC days, see utcDayRange().
     */
    public static function utcDayGraphQuery(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $range = self::utcDayRange($from, $to);
        return '?' . http_build_query([
            'from' => $range['start'],
            'until' => $range['end'],
            'type' => 'time-series',
            'unit' => 'day',
        ]);
    }

    /**
     * Limits a query to the pages of one site when several sites track into the same website.
     * The API compares each value of the "in" operator as a part of the page URL, so
     * "://example.com" matches all pages on that host, for http and https.
     *
     * @param list<string> $pageUrlFilter
     * @return array{and: list<array{member: string, operator: string, values: list<string>}>}
     */
    public static function pageUrlWhere(array $pageUrlFilter): array
    {
        if ($pageUrlFilter === []) {
            return ['and' => []];
        }
        return ['and' => [['member' => 'pageUrl', 'operator' => 'in', 'values' => $pageUrlFilter]]];
    }
}
