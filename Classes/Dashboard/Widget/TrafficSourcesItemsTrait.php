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
        $tones = ['desktop' => 'blue', 'mobile' => 'green', 'tablet' => 'gray'];
        $icons = ['desktop' => 'display', 'mobile' => 'mobile', 'tablet' => 'tablet'];

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
        $totalSessions = array_sum(array_column($payload, 'sessionCount'));

        $items = [];
        foreach ($payload as $item) {
            $current = $item['sessionCount'];
            $previous = $item['previousSessionCount'] ?? 0;
            $items[] = [
                'label' => (string)($item['browserName'] ?? ''),
                'value' => $this->formatter->formatShare($current, $totalSessions),
                'tone' => 'blue',
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
        $totalSessions = array_sum(array_column($payload, 'sessionCount'));

        $items = [];
        foreach ($payload as $item) {
            $current = $item['sessionCount'];
            $previous = $item['previousSessionCount'] ?? 0;
            $items[] = [
                'label' => $this->countryName((string)($item['countryCode'] ?? '')),
                'value' => $this->formatter->formatShare($current, $totalSessions),
                'tone' => 'blue',
                'icon' => '',
                'change' => $this->formatter->formatPercentageChange($current, $previous),
                'changeTone' => $previous > 0 ? ($current >= $previous ? 'positive' : 'negative') : '',
            ];
        }

        return $items;
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
