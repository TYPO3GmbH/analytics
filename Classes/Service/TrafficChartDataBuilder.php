<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use T3G\Analytics\View\SparklineRenderer;

final readonly class TrafficChartDataBuilder
{
    public function __construct(
        private SparklineRenderer $sparklineRenderer,
    ) {
    }

    /**
     * @param array{labels: list<string>, data: list<int>}|null $graphData
     * @return array{sparkline: string, yLabels: list<array{value: int, label: string}>, xLabels: list<string>}
     */
    public function build(?array $graphData, string $chartLabel): array
    {
        $data = $graphData['data'] ?? [];
        $labels = $graphData['labels'] ?? [];

        $shortLabels = array_map(
            static fn (string $iso): string => (new \DateTimeImmutable($iso))->format('d.m.'),
            $labels
        );
        $fullLabels = array_map(
            static fn (string $iso): string => (new \DateTimeImmutable($iso))->format('d.m.Y'),
            $labels
        );

        $max = $data === [] ? 0 : max($data);
        $scaleMax = $this->calcScaleMax($max);
        $step = intdiv($scaleMax, 3);

        $ticks = [];
        for ($i = 3; $i >= 0; $i--) {
            $value = $step * $i;
            $ticks[] = ['value' => $value, 'label' => $this->formatScaleLabel($value)];
        }

        $pointLabels = [];
        foreach ($data as $index => $value) {
            $date = $fullLabels[$index] ?? '';
            $pointLabels[] = $date !== '' ? $date . ': ' . number_format($value, 0, '.', "\u{202F}") : (string)$value;
        }

        return [
            'sparkline' => $this->sparklineRenderer->render($data, [
                'label' => $chartLabel,
                'class' => 'tx-analytics-traffic-graph-sparkline',
                'yMin' => 0,
                'yMax' => $scaleMax,
                'gridLines' => array_column($ticks, 'value'),
                'showLastPoint' => false,
                'preserveAspectRatio' => 'none',
                'labels' => $pointLabels,
            ]),
            'yLabels' => $ticks,
            'xLabels' => $this->visibleLabels($shortLabels),
        ];
    }

    /**
     * @param array<string, array{labels: list<string>, data: list<int>}|null> $metricData
     * @param array<string, string> $metricLabels
     * @param array<string, string> $metricTones
     * @param array<string, int> $metricAxes  0 = left Y-axis, 1 = right Y-axis
     * @return array{
     *     sparkline: string,
     *     yLabels: list<array{value: int, label: string}>,
     *     yLabelsRight: list<array{value: int, label: string}>,
     *     hasRightAxis: bool,
     *     xLabels: list<string>,
     *     legend: list<array{label: string, tone: string, key: string}>
     * }
     */
    public function buildMulti(array $metricData, array $metricLabels, array $metricTones, array $metricAxes = []): array
    {
        $activeData = [];
        foreach ($metricData as $key => $dataset) {
            if ($dataset !== null) {
                $activeData[$key] = $dataset;
            }
        }

        $emptyResult = [
            'sparkline' => '',
            'yLabels' => [],
            'yLabelsRight' => [],
            'hasRightAxis' => false,
            'xLabels' => [],
            'legend' => [],
        ];

        if ($activeData === []) {
            return $emptyResult;
        }

        // Align all datasets to a shared date axis (union of all dates, 0-fill for missing).
        // Normalize all labels to Y-m-d: different API endpoints may return different ISO formats
        // (e.g. "2026-06-16" vs "2026-06-16T00:00:00.000+02:00") for the same calendar day.
        $normalizeDate = static fn (string $iso): string => (new \DateTimeImmutable($iso))->format('Y-m-d');

        $allIsoDates = [];
        foreach ($activeData as $dataset) {
            foreach ($dataset['labels'] as $isoDate) {
                $allIsoDates[$normalizeDate($isoDate)] = true;
            }
        }
        ksort($allIsoDates);
        $sortedIsoDates = array_keys($allIsoDates);

        $shortLabels = array_map(
            static fn (string $ymd): string => (new \DateTimeImmutable($ymd))->format('d.m.'),
            $sortedIsoDates
        );
        $formattedLabels = array_map(
            static fn (string $ymd): string => (new \DateTimeImmutable($ymd))->format('d.m.Y'),
            $sortedIsoDates
        );

        $alignedValues = [];
        foreach ($activeData as $key => $dataset) {
            $lookup = [];
            foreach ($dataset['labels'] as $i => $rawLabel) {
                $lookup[$normalizeDate($rawLabel)] = $dataset['data'][$i];
            }
            $aligned = [];
            foreach ($sortedIsoDates as $ymd) {
                $aligned[] = $lookup[$ymd] ?? 0;
            }
            $alignedValues[$key] = $aligned;
        }

        // Compute separate Y-axis scales for axis 0 (left) and axis 1 (right).
        $axis0Values = [];
        $axis1Values = [];
        foreach ($activeData as $key => $dataset) {
            $axis = $metricAxes[$key] ?? 0;
            if ($axis === 1) {
                $axis1Values = array_merge($axis1Values, $alignedValues[$key]);
            } else {
                $axis0Values = array_merge($axis0Values, $alignedValues[$key]);
            }
        }

        // Fall back to a combined single axis when only one axis has data.
        $hasAxis0 = $axis0Values !== [];
        $hasAxis1 = $axis1Values !== [];
        $hasRightAxis = $hasAxis0 && $hasAxis1;

        if (!$hasRightAxis) {
            $allValues = array_merge($axis0Values, $axis1Values);
            $scaleMax0 = $this->calcScaleMax($allValues !== [] ? max($allValues) : 0);
            $scaleMax1 = $scaleMax0;
        } else {
            $scaleMax0 = $this->calcScaleMax(max($axis0Values));
            $scaleMax1 = $this->calcScaleMax(max($axis1Values));
        }

        $ticks0 = $this->buildTicks($scaleMax0);
        $ticks1 = $this->buildTicks($scaleMax1);

        $datasets = [];
        $legend = [];
        foreach ($activeData as $key => $dataset) {
            $tone = $metricTones[$key] ?? 'series-1';
            $axis = $metricAxes[$key] ?? 0;
            // When there is no right axis, treat everything as axis 0.
            $effectiveAxis = $hasRightAxis ? $axis : 0;
            $datasets[] = [
                'values' => $alignedValues[$key],
                'label' => $metricLabels[$key] ?? $key,
                'tone' => $tone,
                'axis' => $effectiveAxis,
                'key' => $key,
            ];
            $legend[] = ['label' => $metricLabels[$key] ?? $key, 'tone' => $tone, 'key' => $key];
        }

        $pointTooltips = [];
        foreach ($sortedIsoDates as $i => $isoDate) {
            $metrics = [];
            foreach ($activeData as $key => $dataset) {
                $value = $alignedValues[$key][$i] ?? 0;
                $metrics[] = [
                    'key' => $key,
                    'label' => $metricLabels[$key] ?? $key,
                    'value' => number_format($value, 0, '.', "\u{202F}"),
                    'tone' => $metricTones[$key] ?? 'series-1',
                ];
            }
            $pointTooltips[] = (string)json_encode([
                'date' => $formattedLabels[$i] ?? '',
                'metrics' => $metrics,
            ]);
        }

        $sparkline = $this->sparklineRenderer->renderMultiLine($datasets, [
            'class' => 'tx-analytics-traffic-graph-sparkline',
            'yMin' => 0,
            'yMax' => $scaleMax0,
            'yMaxRight' => $scaleMax1,
            'gridLines' => array_column($ticks0, 'value'),
            'preserveAspectRatio' => 'none',
            'pointTooltips' => $pointTooltips,
            'smooth' => true,
        ]);

        return [
            'sparkline' => $sparkline,
            'yLabels' => $ticks0,
            'yLabelsRight' => $ticks1,
            'hasRightAxis' => $hasRightAxis,
            'xLabels' => $this->visibleLabels($shortLabels),
            'legend' => $legend,
        ];
    }

    /**
     * Returns the y-axis maximum for a chart whose data peak is `$max`.
     *
     * Uses a {1,2,3,5}×10^n step series so that:
     * - scaleMax is always divisible by 3 (y-labels align exactly with SVG grid lines)
     * - the data peak lands between the 2nd and 3rd y-label from the top
     */
    public function calcScaleMax(int $max): int
    {
        if ($max <= 0) {
            return 9;
        }
        $half = max(1, (int) ceil($max / 2));
        $magnitude = 10 ** (int) floor(log10($half));
        foreach ([1, 2, 3, 5, 10] as $factor) {
            $step = $factor * $magnitude;
            if ($step >= $half) {
                return $step * 3;
            }
        }
        return 10 * $magnitude * 3;
    }

    /** @return list<array{value: int, label: string}> */
    private function buildTicks(int $scaleMax): array
    {
        $step = intdiv($scaleMax, 3);
        $ticks = [];
        for ($i = 3; $i >= 0; $i--) {
            $value = $step * $i;
            $ticks[] = ['value' => $value, 'label' => $this->formatScaleLabel($value)];
        }
        return $ticks;
    }

    private function formatScaleLabel(int $value): string
    {
        if ($value === 0) {
            return '0';
        }
        if ($value >= 1000) {
            return number_format($value / 1000, 1, '.', '') . 'k';
        }
        return (string) $value;
    }

    /**
     * Returns a reduced set of evenly spaced labels suitable for the x-axis.
     * Short windows (< 10 data points) get 4 labels; longer ones get 5.
     *
     * @param list<string> $labels
     * @return list<string>
     */
    private function visibleLabels(array $labels): array
    {
        if ($labels === []) {
            return [];
        }
        $lastIndex = count($labels) - 1;
        $targetCount = $lastIndex >= 10 ? 5 : 4;
        $slots = min($targetCount, $lastIndex + 1);
        if ($slots <= 1) {
            return array_values(array_slice($labels, 0, $slots));
        }
        $visibleIndexes = [];
        for ($i = 0; $i < $slots; $i++) {
            $visibleIndexes[] = (int) round($i * $lastIndex / ($slots - 1));
        }
        return array_values(array_intersect_key($labels, array_flip(array_unique($visibleIndexes))));
    }
}
