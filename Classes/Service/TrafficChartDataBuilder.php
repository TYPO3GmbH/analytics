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

        $formattedLabels = array_map(
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
            $date = $formattedLabels[$index] ?? '';
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
            'xLabels' => $this->visibleLabels($formattedLabels),
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
