<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

trait TrafficSourcesItemsTrait
{
    /**
     * @param array<string, array{current: int, previous: int}> $sources channel label => {current, previous} visit counts
     * @return list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}>
     */
    private function buildTrafficSourceItems(array $sources): array
    {
        $totalVisitCount = array_sum(array_map(static fn (array $d): int => $d['current'], $sources));
        if ($totalVisitCount === 0) {
            return [];
        }
        $tones = [
            'direct' => 'source-direct',
            'search' => 'source-search',
            'social' => 'source-social',
            'email' => 'source-email',
            'paid' => 'source-paid',
            'unknown' => 'source-unknown',
            'ai_traffic' => 'source-ai',
        ];

        uasort($sources, static fn (array $a, array $b): int => $b['current'] <=> $a['current']);

        $items = [];
        foreach ($sources as $channel => $data) {
            $current = $data['current'];
            $previous = $data['previous'];
            $items[] = [
                'label' => $this->translate('dashboardWidget.trafficSources.source.' . $channel),
                'value' => $this->formatter->formatShare($current, $totalVisitCount),
                'tone' => $tones[$channel] ?? 'source-other',
                'icon' => '',
                'count' => $current,
                'change' => $this->formatter->formatAbsoluteChange($current, $previous),
                'changeTone' => ($current === 0 && $previous === 0) ? '' : ($current >= $previous ? 'positive' : 'negative'),
            ];
        }

        return $items;
    }

    /**
     * @param list<array{deviceType: string, sessionCount: int, sessionPercentOfTotal: int|float, previousSessionCount?: int}> $payload
     * @return list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}>
     */
    private function buildDeviceItems(array $payload): array
    {
        $tones = ['desktop' => 'device-desktop', 'mobile' => 'device-mobile', 'tablet' => 'device-tablet', 'phone' => 'device-phone', 'undefined' => 'device-unknown'];
        $icons = ['desktop' => 'display', 'mobile' => 'mobile', 'tablet' => 'tablet', 'phone' => 'mobile', 'undefined' => ''];

        $totalSessions = array_sum(array_column($payload, 'sessionCount'));

        $items = [];
        foreach ($payload as $item) {
            $deviceType = $item['deviceType'];
            $current = $item['sessionCount'];
            $previous = $item['previousSessionCount'] ?? 0;
            $items[] = [
                'label' => $this->translate('dashboardWidget.trafficSources.device.' . $deviceType),
                'value' => $this->formatter->formatShare($current, $totalSessions),
                'tone' => $tones[$deviceType] ?? 'device-unknown',
                'icon' => $icons[$deviceType] ?? '',
                'count' => $current,
                'change' => $this->formatter->formatAbsoluteChange($current, $previous),
                'changeTone' => ($current === 0 && $previous === 0) ? '' : ($current >= $previous ? 'positive' : 'negative'),
            ];
        }

        return $items;
    }

    /**
     * @param list<array{browserName: string, sessionCount: int, sessionPercentOfTotal: int|float, previousSessionCount?: int}> $payload
     * @return list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}>
     */
    private function buildBrowserItems(array $payload, int $limit = 6): array
    {
        if ($payload === []) {
            return [];
        }
        $palette = ['series-1', 'series-2', 'series-3', 'series-4', 'series-5', 'series-6', 'series-7', 'series-8'];

        $top = array_slice($payload, 0, $limit);
        $items = [];
        $shownPercent = 0.0;

        foreach ($top as $index => $item) {
            $current = (int)($item['sessionCount'] ?? 0);
            $previous = (int)($item['previousSessionCount'] ?? 0);
            $pct = (float)($item['sessionPercentOfTotal'] ?? 0.0);
            $shownPercent += $pct;
            $items[] = [
                'label' => (string)($item['browserName'] ?? ''),
                'value' => number_format($pct, 2, '.', ''),
                'tone' => $palette[$index % count($palette)],
                'icon' => '',
                'count' => $current,
                'change' => $this->formatter->formatAbsoluteChange($current, $previous),
                'changeTone' => ($current === 0 && $previous === 0) ? '' : ($current >= $previous ? 'positive' : 'negative'),
            ];
        }

        $othersPercent = max(0.0, 100.0 - $shownPercent);
        if ($othersPercent > 0.05) {
            $items[] = [
                'label' => $this->translate('dashboardWidget.trafficSources.other'),
                'value' => number_format($othersPercent, 2, '.', ''),
                'tone' => 'series-other',
                'icon' => '',
                'change' => null,
                'changeTone' => '',
            ];
        }

        return $items;
    }

    /**
     * Back-calculates the true site session total from the API's sessionPercentOfTotal field.
     * The largest entry is used as it has the least rounding error.
     *
     * @param list<array{sessionCount: int, sessionPercentOfTotal: int|float, ...}> $payload
     */
    private function trueSessionTotal(array $payload): int
    {
        foreach ($payload as $item) {
            $pct = (float)($item['sessionPercentOfTotal'] ?? 0.0);
            $count = (int)($item['sessionCount'] ?? 0);
            if ($pct > 0.0 && $count > 0) {
                return (int)round($count / ($pct / 100.0));
            }
        }
        return (int)array_sum(array_column($payload, 'sessionCount'));
    }

    /**
     * @param list<array{countryCode: string, sessionCount: int, sessionPercentOfTotal: int|float, previousSessionCount?: int}> $payload
     * @return list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}>
     */
    private function buildCountryItems(array $payload, int $limit = 6): array
    {
        if ($payload === []) {
            return [];
        }
        $palette = ['series-1', 'series-2', 'series-3', 'series-4', 'series-5', 'series-6', 'series-7', 'series-8'];

        $top = array_slice($payload, 0, $limit);
        $items = [];
        $shownPercent = 0.0;

        foreach ($top as $index => $item) {
            $current = (int)($item['sessionCount'] ?? 0);
            $previous = (int)($item['previousSessionCount'] ?? 0);
            $pct = (float)($item['sessionPercentOfTotal'] ?? 0.0);
            $shownPercent += $pct;
            $items[] = [
                'label' => $this->countryName((string)($item['countryCode'] ?? '')),
                'value' => number_format($pct, 2, '.', ''),
                'tone' => $palette[$index % count($palette)],
                'icon' => '',
                'count' => $current,
                'change' => $this->formatter->formatAbsoluteChange($current, $previous),
                'changeTone' => ($current === 0 && $previous === 0) ? '' : ($current >= $previous ? 'positive' : 'negative'),
            ];
        }

        // Remaining sessions: returned countries beyond the limit + countries not returned by API at all.
        // Since sessionPercentOfTotal values reflect the true site total, 100 - shownPercent covers both.
        $othersPercent = max(0.0, 100.0 - $shownPercent);
        if ($othersPercent > 0.05) {
            $items[] = [
                'label' => $this->translate('dashboardWidget.trafficSources.other'),
                'value' => number_format($othersPercent, 2, '.', ''),
                'tone' => 'series-other',
                'icon' => '',
                'change' => null,
                'changeTone' => '',
            ];
        }

        return $items;
    }

    /**
     * @param list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}> $items
     * @return array{icon: string, title: string, showSiteSelect: bool, isDonut: bool, chartSvg: string, items: list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}>}
     */
    private function asSectionData(string $icon, string $title, array $items, bool $isDonut = false, int $total = 0): array
    {
        if ($isDonut) {
            $limited = $this->limitItemsForDonut($items);
            return [
                'icon' => $icon,
                'title' => $title,
                'showSiteSelect' => false,
                'isDonut' => true,
                'chartSvg' => $this->buildDonut($limited),
                'items' => $limited,
                'total' => $total,
            ];
        }
        return [
            'icon' => $icon,
            'title' => $title,
            'showSiteSelect' => false,
            'isDonut' => false,
            'chartSvg' => '',
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * @param list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}> $items
     * @return list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}>
     */
    private function limitItemsForDonut(array $items, int $limit = 5): array
    {
        if (count($items) <= $limit) {
            return $items;
        }
        $top = array_slice($items, 0, $limit);
        $rest = array_slice($items, $limit);
        $restValue = array_sum(array_map(static fn (array $i): float => (float)$i['value'], $rest));
        $top[] = [
            'label' => $this->translate('dashboardWidget.trafficSources.other'),
            'value' => number_format($restValue, 2, '.', ''),
            'tone' => 'series-other',
            'icon' => '',
            'change' => null,
            'changeTone' => '',
        ];
        return $top;
    }

    /**
     * @param list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}> $items
     */
    private function buildDonut(array $items): string
    {
        $r = 40;
        $sw = 18;
        $C = 2.0 * M_PI * $r;

        $parts = [
            '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">',
            sprintf('<circle cx="50" cy="50" r="%d" fill="none" class="tx-analytics-traffic-sources-donut-track" stroke-width="%d"/>', $r, $sw),
        ];

        $cumulative = 0.0;
        foreach ($items as $item) {
            $value = (float)$item['value'];
            if ($value <= 0.0) {
                continue;
            }
            $segLen = ($value / 100.0) * $C;
            $rotation = -90.0 + ($cumulative / 100.0) * 360.0;
            $parts[] = sprintf(
                '<circle cx="50" cy="50" r="%d" fill="none" class="tx-analytics-traffic-sources-donut-segment tx-analytics-traffic-sources-tone-%s" stroke-width="%d" stroke-dasharray="%.3f %.3f" transform="rotate(%.3f 50 50)" data-ts-label="%s" data-ts-value="%s" data-ts-tone="%s" data-ts-count="%d" data-ts-change="%s" data-ts-change-tone="%s" aria-label="%s: %s%%"/>',
                $r,
                $item['tone'],
                $sw,
                $segLen,
                $C - $segLen,
                $rotation,
                htmlspecialchars($item['label'], ENT_XML1 | ENT_QUOTES),
                htmlspecialchars($item['value'], ENT_XML1 | ENT_QUOTES),
                htmlspecialchars($item['tone'], ENT_XML1 | ENT_QUOTES),
                (int)($item['count'] ?? 0),
                htmlspecialchars((string)($item['change'] ?? ''), ENT_XML1 | ENT_QUOTES),
                htmlspecialchars((string)($item['changeTone'] ?? ''), ENT_XML1 | ENT_QUOTES),
                htmlspecialchars($item['label'], ENT_XML1 | ENT_QUOTES),
                htmlspecialchars($item['value'], ENT_XML1 | ENT_QUOTES),
            );
            $cumulative += $value;
        }

        $parts[] = '</svg>';
        return implode('', $parts);
    }

    private function countryName(string $countryCode): string
    {
        $code = strtoupper($countryCode);
        if (extension_loaded('intl')) {
            $name = \Locale::getDisplayRegion('und_' . $code, 'en');
            if ($name !== false && $name !== '' && $name !== $code) {
                return $name;
            }
        }
        return $code;
    }
}
