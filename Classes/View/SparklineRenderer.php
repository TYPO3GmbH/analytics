<?php

declare(strict_types=1);

namespace T3G\Analytics\View;

final class SparklineRenderer
{
    private const VIEW_BOX_WIDTH = 100;
    private const VIEW_BOX_HEIGHT = 32;
    private const PADDING = 2;

    private static int $clipIdCounter = 0;

    /**
     * @param list<int|float|string> $values
     * @param array{
     *     label?: string,
     *     class?: string,
     *     tone?: string,
     *     showLastPoint?: bool,
     *     fill?: bool,
     *     yMin?: float,
     *     yMax?: float,
     *     gridLines?: list<int|float>,
     *     preserveAspectRatio?: string,
     *     labels?: list<string>,
     *     smooth?: bool
     * } $options
     */
    public function render(array $values, array $options = []): string
    {
        $numericValues = $this->toNumeric($values);
        if ($numericValues === []) {
            return '';
        }

        $yMin = isset($options['yMin']) ? (float)$options['yMin'] : min($numericValues);
        $yMax = isset($options['yMax']) ? (float)$options['yMax'] : max($numericValues);
        $points = $this->buildPoints($numericValues, $yMin, $yMax);
        if ($points === []) {
            return '';
        }

        $label = trim((string)($options['label'] ?? ''));
        $class = trim('tx-analytics-sparkline ' . ($options['class'] ?? ''));
        $tone = $this->normalizeTone((string)($options['tone'] ?? 'series-1'));
        $smooth = (bool)($options['smooth'] ?? false);
        $linePath = $smooth ? $this->buildSmoothLinePath($points) : $this->buildLinePath($points);
        $lastPoint = $points[array_key_last($points)];
        $showLastPoint = (bool)($options['showLastPoint'] ?? true);
        $showFill = (bool)($options['fill'] ?? true) && count($points) > 1;
        $gridLines = (array)($options['gridLines'] ?? []);
        $preserveAspectRatio = trim((string)($options['preserveAspectRatio'] ?? ''));
        $pointLabels = (array)($options['labels'] ?? []);

        $html = '<div class="' . $this->escape($class) . '" data-sparkline-tone="' . $this->escape($tone) . '">';
        $html .= '<svg class="tx-analytics-sparkline-svg" viewBox="0 0 ' . self::VIEW_BOX_WIDTH . ' ' . self::VIEW_BOX_HEIGHT . '"';
        if ($preserveAspectRatio !== '') {
            $html .= ' preserveAspectRatio="' . $this->escape($preserveAspectRatio) . '"';
        }
        $html .= ' role="img"';
        if ($label !== '') {
            $html .= ' aria-label="' . $this->escape($label) . '"';
        } else {
            $html .= ' aria-hidden="true"';
        }
        $html .= ' focusable="false">';

        foreach ($gridLines as $gridValue) {
            $y = $this->valueToY((float)$gridValue, $yMin, $yMax);
            $html .= '<line class="tx-analytics-sparkline-grid-line" x1="0" y1="' . $this->formatNumber($y) . '" x2="' . self::VIEW_BOX_WIDTH . '" y2="' . $this->formatNumber($y) . '"/>';
        }

        if ($showFill) {
            $html .= '<path class="tx-analytics-sparkline-fill" d="' . $this->buildFillPath($points, $linePath) . '"></path>';
        }
        $html .= '<path class="tx-analytics-sparkline-line" d="' . $linePath . '"></path>';
        if ($pointLabels !== []) {
            foreach ($points as $index => [$x, $y]) {
                $pointLabel = (string)($pointLabels[$index] ?? '');
                if ($pointLabel === '') {
                    continue;
                }
                $html .= '<circle class="tx-analytics-sparkline-point-tooltip" cx="' . $this->formatNumber($x) . '" cy="' . $this->formatNumber($y) . '" r="4" fill="transparent"><title>' . $this->escape($pointLabel) . '</title></circle>';
            }
        }
        if ($showLastPoint) {
            $html .= '<circle class="tx-analytics-sparkline-point" cx="' . $this->formatNumber($lastPoint[0]) . '" cy="' . $this->formatNumber($lastPoint[1]) . '" r="1.9"></circle>';
        }
        $html .= '</svg></div>';

        return $html;
    }

    /**
     * @param list<array{values: list<int|float>, label?: string, tone?: string, axis?: int, key?: string}> $datasets
     * @param array{
     *     yMin?: float,
     *     yMax?: float,
     *     yMaxRight?: float,
     *     gridLines?: list<int|float>,
     *     preserveAspectRatio?: string,
     *     class?: string,
     *     pointTooltips?: list<string>,
     *     smooth?: bool
     * } $options
     */
    public function renderMultiLine(array $datasets, array $options = []): string
    {
        $active = [];
        foreach ($datasets as $dataset) {
            $numeric = $this->toNumeric($dataset['values'] ?? []);
            if ($numeric !== []) {
                $active[] = [
                    'numeric' => $numeric,
                    'tone' => $this->normalizeTone((string)($dataset['tone'] ?? 'series-1')),
                    'axis' => (int)($dataset['axis'] ?? 0),
                    'key' => (string)($dataset['key'] ?? ''),
                ];
            }
        }

        if ($active === []) {
            return '';
        }

        $yMin = isset($options['yMin']) ? (float)$options['yMin'] : 0.0;
        if (isset($options['yMax'])) {
            $yMaxLeft = (float)$options['yMax'];
        } else {
            $allValues = array_merge(...array_column($active, 'numeric'));
            $yMaxLeft = $allValues !== [] ? (float)max($allValues) : 1.0;
        }
        $yMaxRight = isset($options['yMaxRight']) ? (float)$options['yMaxRight'] : $yMaxLeft;

        $class = trim('tx-analytics-sparkline ' . ($options['class'] ?? ''));
        $gridLines = (array)($options['gridLines'] ?? []);
        $preserveAspectRatio = trim((string)($options['preserveAspectRatio'] ?? ''));
        $pointTooltips = (array)($options['pointTooltips'] ?? []);
        $smooth = (bool)($options['smooth'] ?? false);

        // Unique IDs prevent collisions when multiple dashboard widgets render on the same page.
        $idSuffix = (string)(++self::$clipIdCounter);
        $clipId = 'tgc-' . $idSuffix;
        $baseline = self::VIEW_BOX_HEIGHT - self::PADDING;

        // Build all point sets first so tones are known when emitting the SVG opening tag.
        $pointSets = [];
        foreach ($active as $index => $ds) {
            $dsYMax = $ds['axis'] === 1 ? $yMaxRight : $yMaxLeft;
            $points = $this->buildPoints($ds['numeric'], $yMin, $dsYMax);
            if ($points === []) {
                continue;
            }
            $linePath = $smooth ? $this->buildSmoothLinePath($points) : $this->buildLinePath($points);
            $pointSets[] = [
                'points' => $points,
                'tone' => $ds['tone'],
                'key' => $ds['key'],
                'linePath' => $linePath,
                'fillPath' => $this->buildFillPath($points, $linePath),
                'gradientId' => 'tgc-fill-' . $idSuffix . '-' . $index . '-' . $ds['tone'],
            ];
        }

        // data-tones / data-keys let JS create correctly coloured HTML hover-dots and respect toggle state.
        $tones = implode(',', array_column($pointSets, 'tone'));
        $keys = implode(',', array_column($pointSets, 'key'));
        $html = '<div class="' . $this->escape($class) . '">';
        $html .= '<svg class="tx-analytics-sparkline-svg" viewBox="0 0 ' . self::VIEW_BOX_WIDTH . ' ' . self::VIEW_BOX_HEIGHT . '"';
        if ($preserveAspectRatio !== '') {
            $html .= ' preserveAspectRatio="' . $this->escape($preserveAspectRatio) . '"';
        }
        $html .= ' role="img" aria-hidden="true" focusable="false" data-tones="' . $this->escape($tones) . '" data-keys="' . $this->escape($keys) . '">';

        // Clip path: prevent fills/lines from going below the 0-axis (y > baseline).
        $html .= '<defs><clipPath id="' . $clipId . '">';
        $html .= '<rect x="0" y="0" width="' . self::VIEW_BOX_WIDTH . '" height="' . $baseline . '"/>';
        $html .= '</clipPath>';
        foreach ($pointSets as $ps) {
            $html .= '<linearGradient id="' . $this->escape($ps['gradientId']) . '" x1="0" y1="' . self::PADDING . '" x2="0" y2="' . $baseline . '" gradientUnits="userSpaceOnUse">';
            $html .= '<stop offset="0%" style="stop-color:color-mix(in srgb, var(' . $this->toneToCssVariable($ps['tone']) . '), white 38%);stop-opacity:.68"/>';
            $html .= '<stop offset="24%" style="stop-color:color-mix(in srgb, var(' . $this->toneToCssVariable($ps['tone']) . '), white 58%);stop-opacity:.26"/>';
            $html .= '<stop offset="100%" style="stop-color:var(' . $this->toneToCssVariable($ps['tone']) . ');stop-opacity:0"/>';
            $html .= '</linearGradient>';
        }
        $html .= '</defs>';

        foreach ($gridLines as $gridValue) {
            $y = $this->valueToY((float)$gridValue, $yMin, $yMaxLeft);
            $html .= '<line class="tx-analytics-sparkline-grid-line" x1="0" y1="' . $this->formatNumber($y) . '" x2="' . self::VIEW_BOX_WIDTH . '" y2="' . $this->formatNumber($y) . '"/>';
        }

        // Fills and lines wrapped in clip group so smooth curves cannot dip below the 0-axis.
        $html .= '<g clip-path="url(#' . $clipId . ')">';

        foreach ($pointSets as $ps) {
            $attrs = ' class="tx-analytics-sparkline-fill" data-tone="' . $this->escape($ps['tone']) . '" style="fill:url(#' . $this->escape($ps['gradientId']) . ')"';
            if ($ps['key'] !== '') {
                $attrs .= ' data-dataset-key="' . $this->escape($ps['key']) . '"';
            }
            $html .= '<path' . $attrs . ' d="' . $ps['fillPath'] . '"/>';
        }

        foreach ($pointSets as $ps) {
            $attrs = ' class="tx-analytics-sparkline-line" data-tone="' . $this->escape($ps['tone']) . '"';
            if ($ps['key'] !== '') {
                $attrs .= ' data-dataset-key="' . $this->escape($ps['key']) . '"';
            }
            $html .= '<path' . $attrs . ' d="' . $ps['linePath'] . '"/>';
        }

        // Hover indicator: vertical line only. Dots are rendered as HTML elements by JS
        // (SVG circles distort to ellipses under preserveAspectRatio="none").
        $html .= '<line class="tx-analytics-sparkline-hover-line" x1="-1" y1="' . self::PADDING . '" x2="-1" y2="' . $baseline . '" visibility="hidden" pointer-events="none"/>';

        $html .= '</g>';

        // Tooltip rects outside the clip group — span full SVG height so they are easy to hover
        // even when data values are near zero. No <title> to avoid the native browser tooltip.
        if ($pointTooltips !== []) {
            $firstPoints = $pointSets[0]['points'];
            $count = count($firstPoints);
            $step = $count > 1 ? self::VIEW_BOX_WIDTH / ($count - 1) : (float)self::VIEW_BOX_WIDTH;
            foreach ($firstPoints as $index => [$x]) {
                $tooltipJson = (string)($pointTooltips[$index] ?? '');
                if ($tooltipJson === '') {
                    continue;
                }
                $rectX = max(0.0, $x - $step / 2);
                $rectW = min((float)self::VIEW_BOX_WIDTH - $rectX, $step);
                // Collect per-dataset y values for the hover dots, e.g. data-y-0="11.33" data-y-1="20.67"
                $yAttrs = '';
                foreach ($pointSets as $psIdx => $ps) {
                    $yAttrs .= ' data-y-' . $psIdx . '="' . $this->formatNumber($ps['points'][$index][1]) . '"';
                }
                $html .= '<rect class="tx-analytics-sparkline-point-tooltip" x="' . $this->formatNumber($rectX) . '" y="0" width="' . $this->formatNumber($rectW) . '" height="' . self::VIEW_BOX_HEIGHT . '" fill="transparent" data-tooltip="' . $this->escape($tooltipJson) . '" data-x="' . $this->formatNumber($x) . '"' . $yAttrs . '/>';
            }
        }

        $html .= '</svg></div>';

        return $html;
    }

    /**
     * @param list<int|float|string> $values
     * @return list<float>
     */
    private function toNumeric(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $result[] = (float)$value;
            }
        }
        return $result;
    }

    /**
     * @param list<float> $numericValues
     * @return list<array{0: float, 1: float}>
     */
    private function buildPoints(array $numericValues, float $yMin, float $yMax): array
    {
        $count = count($numericValues);
        if ($count === 0) {
            return [];
        }

        $range = $yMax - $yMin;
        $usableHeight = self::VIEW_BOX_HEIGHT - (self::PADDING * 2);
        $step = $count > 1 ? self::VIEW_BOX_WIDTH / ($count - 1) : 0.0;
        $centerX = self::VIEW_BOX_WIDTH / 2;
        $centerY = self::VIEW_BOX_HEIGHT / 2;

        $points = [];
        foreach ($numericValues as $index => $value) {
            $x = $count > 1 ? $index * $step : $centerX;
            $y = $range > 0.0
                ? self::PADDING + (($yMax - $value) / $range) * $usableHeight
                : $centerY;
            $points[] = [$x, $y];
        }

        return $points;
    }

    private function valueToY(float $value, float $yMin, float $yMax): float
    {
        $range = $yMax - $yMin;
        if ($range <= 0.0) {
            return self::VIEW_BOX_HEIGHT / 2;
        }
        $usableHeight = self::VIEW_BOX_HEIGHT - (self::PADDING * 2);
        return self::PADDING + (($yMax - $value) / $range) * $usableHeight;
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     */
    private function buildLinePath(array $points): string
    {
        $path = [];
        foreach ($points as $index => [$x, $y]) {
            $path[] = ($index === 0 ? 'M' : 'L') . $this->formatNumber($x) . ' ' . $this->formatNumber($y);
        }
        return implode(' ', $path);
    }

    /**
     * Builds a smooth cubic bezier path through all points using Catmull-Rom → bezier conversion.
     *
     * @param list<array{0: float, 1: float}> $points
     */
    private function buildSmoothLinePath(array $points): string
    {
        $count = count($points);
        if ($count < 3) {
            return $this->buildLinePath($points);
        }

        $path = 'M' . $this->formatNumber($points[0][0]) . ' ' . $this->formatNumber($points[0][1]);

        for ($i = 0; $i < $count - 1; $i++) {
            $p0 = $i > 0 ? $points[$i - 1] : $points[0];
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $p3 = $i + 2 < $count ? $points[$i + 2] : $points[$count - 1];

            $cp1x = $p1[0] + ($p2[0] - $p0[0]) / 6;
            $cp1y = $p1[1] + ($p2[1] - $p0[1]) / 6;
            $cp2x = $p2[0] - ($p3[0] - $p1[0]) / 6;
            $cp2y = $p2[1] - ($p3[1] - $p1[1]) / 6;

            // Clamp control-point y to the drawable area so the bezier never visually
            // overshoots the top (y < PADDING) or the zero-baseline (y > HEIGHT - PADDING).
            $yLow = (float)self::PADDING;
            $yHigh = (float)(self::VIEW_BOX_HEIGHT - self::PADDING);
            $cp1y = max($yLow, min($yHigh, $cp1y));
            $cp2y = max($yLow, min($yHigh, $cp2y));

            $path .= ' C' . $this->formatNumber($cp1x) . ' ' . $this->formatNumber($cp1y)
                   . ',' . $this->formatNumber($cp2x) . ' ' . $this->formatNumber($cp2y)
                   . ',' . $this->formatNumber($p2[0]) . ' ' . $this->formatNumber($p2[1]);
        }

        return $path;
    }

    /**
     * @param non-empty-list<array{0: float, 1: float}> $points
     */
    private function buildFillPath(array $points, string $linePath): string
    {
        $firstPoint = $points[0];
        $lastPoint = $points[array_key_last($points)];
        $baseline = self::VIEW_BOX_HEIGHT - self::PADDING;

        return $linePath
            . ' L' . $this->formatNumber($lastPoint[0]) . ' ' . $baseline
            . ' L' . $this->formatNumber($firstPoint[0]) . ' ' . $baseline
            . ' Z';
    }

    private function normalizeTone(string $tone): string
    {
        return in_array($tone, [
            'visits',
            'visitors',
            'visitors-new',
            'visitors-returning',
            'sessions',
            'bounce-rate',
            'avg-duration',
            'continuation-rate',
            'source-direct',
            'source-search',
            'source-social',
            'source-email',
            'source-paid',
            'source-ai',
            'source-unknown',
            'source-other',
            'device-desktop',
            'device-mobile',
            'device-tablet',
            'device-phone',
            'device-unknown',
            'series-1',
            'series-2',
            'series-3',
            'series-4',
            'series-5',
            'series-6',
            'series-7',
            'series-8',
            'series-other',
        ], true) ? $tone : 'series-1';
    }

    private function toneToCssVariable(string $tone): string
    {
        return '--tx-analytics-color-' . $this->normalizeTone($tone);
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
