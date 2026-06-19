<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

trait TrafficSourcesItemsTrait
{
    /**
     * @param array<string, int> $sources channel label => total visits
     * @return list<array{label: string, value: string, tone: string, icon: string, change: null, changeTone: string}>
     */
    private function buildTrafficSourceItems(array $sources): array
    {
        $tones = [
            'direct' => 'green',
            'search' => 'blue',
            'social' => 'orange',
            'email' => 'gray',
            'paid' => 'purple',
            'unknown' => 'gray',
            'ai_traffic' => 'purple',
        ];

        $totalVisitCount = array_sum($sources);

        $items = [];
        foreach ($sources as $channel => $visitCount) {
            $items[] = [
                'label' => $this->translate('dashboardWidget.trafficSources.source.' . $channel),
                'value' => $this->formatter->formatShare($visitCount, $totalVisitCount),
                'tone' => $tones[$channel] ?? 'gray',
                'icon' => '',
                'change' => null,
                'changeTone' => '',
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
        $tones = ['desktop' => 'blue', 'mobile' => 'green', 'tablet' => 'orange', 'phone' => 'purple', 'undefined' => 'gray'];
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
                'tone' => $tones[$deviceType] ?? 'gray',
                'icon' => $icons[$deviceType] ?? '',
                'change' => $this->formatter->formatPercentageChange($current, $previous),
                'changeTone' => $previous > 0 ? ($current >= $previous ? 'positive' : 'negative') : '',
            ];
        }

        return $items;
    }

    /**
     * @param list<array{browserName: string, sessionCount: int, sessionPercentOfTotal: int|float, previousSessionCount?: int}> $payload
     * @return list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}>
     */
    private function buildBrowserItems(array $payload): array
    {
        $palette = ['blue', 'green', 'orange', 'purple', 'teal'];
        $totalSessions = array_sum(array_column($payload, 'sessionCount'));

        $items = [];
        foreach ($payload as $index => $item) {
            $current = $item['sessionCount'];
            $previous = $item['previousSessionCount'] ?? 0;
            $items[] = [
                'label' => (string)($item['browserName'] ?? ''),
                'value' => $this->formatter->formatShare($current, $totalSessions),
                'tone' => $palette[$index % count($palette)],
                'icon' => '',
                'change' => $this->formatter->formatPercentageChange($current, $previous),
                'changeTone' => $previous > 0 ? ($current >= $previous ? 'positive' : 'negative') : '',
            ];
        }

        return $items;
    }

    /**
     * @param list<array{countryCode: string, sessionCount: int, sessionPercentOfTotal: int|float, previousSessionCount?: int}> $payload
     * @return list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}>
     */
    private function buildCountryItems(array $payload): array
    {
        $palette = ['blue', 'green', 'orange', 'purple', 'teal'];
        $totalSessions = array_sum(array_column($payload, 'sessionCount'));

        $items = [];
        foreach ($payload as $index => $item) {
            $current = $item['sessionCount'];
            $previous = $item['previousSessionCount'] ?? 0;
            $items[] = [
                'label' => $this->countryName((string)($item['countryCode'] ?? '')),
                'value' => $this->formatter->formatShare($current, $totalSessions),
                'tone' => $palette[$index % count($palette)],
                'icon' => '',
                'change' => $this->formatter->formatPercentageChange($current, $previous),
                'changeTone' => $previous > 0 ? ($current >= $previous ? 'positive' : 'negative') : '',
            ];
        }

        return $items;
    }

    /**
     * @param list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}> $items
     * @return array{icon: string, title: string, showSiteSelect: bool, isDonut: bool, chartSvg: string, items: list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}>}
     */
    private function asSectionData(string $icon, string $title, array $items, bool $isDonut = false): array
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
            ];
        }
        return [
            'icon' => $icon,
            'title' => $title,
            'showSiteSelect' => false,
            'isDonut' => false,
            'chartSvg' => '',
            'items' => $items,
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
            'tone' => 'gray',
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
                '<circle cx="50" cy="50" r="%d" fill="none" class="tx-analytics-traffic-sources-donut-segment tx-analytics-traffic-sources-tone-%s" stroke-width="%d" stroke-dasharray="%.3f %.3f" transform="rotate(%.3f 50 50)"><title>%s: %s%%</title></circle>',
                $r,
                $item['tone'],
                $sw,
                $segLen,
                $C - $segLen,
                $rotation,
                htmlspecialchars($item['label'], ENT_XML1),
                $item['value'],
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
