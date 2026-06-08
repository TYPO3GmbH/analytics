<?php

declare(strict_types=1);

namespace T3G\Analytics\View;

final class SparklineRenderer
{
    private const VIEW_BOX_WIDTH = 100;
    private const VIEW_BOX_HEIGHT = 32;
    private const PADDING = 2;

    /**
     * @param list<int|float|string> $values
     * @param array{
     *     label?: string,
     *     class?: string,
     *     tone?: string,
     *     showLastPoint?: bool,
     *     fill?: bool
     * } $options
     */
    public function render(array $values, array $options = []): string
    {
        $points = $this->buildPoints($values);
        if ($points === []) {
            return '';
        }

        $label = trim((string)($options['label'] ?? ''));
        $class = trim('tx-analytics-sparkline ' . (string)($options['class'] ?? ''));
        $tone = $this->normalizeTone((string)($options['tone'] ?? 'primary'));
        $linePath = $this->buildLinePath($points);
        $fillPath = $this->buildFillPath($points);
        $lastPoint = $points[array_key_last($points)];
        $showLastPoint = (bool)($options['showLastPoint'] ?? true);
        $showFill = (bool)($options['fill'] ?? true);

        $html = '<div class="' . $this->escape($class) . '" data-sparkline-tone="' . $this->escape($tone) . '">';
        $html .= '<svg class="tx-analytics-sparkline-svg" viewBox="0 0 ' . self::VIEW_BOX_WIDTH . ' ' . self::VIEW_BOX_HEIGHT . '" role="img"';
        if ($label !== '') {
            $html .= ' aria-label="' . $this->escape($label) . '"';
        } else {
            $html .= ' aria-hidden="true"';
        }
        $html .= ' focusable="false">';
        if ($showFill) {
            $html .= '<path class="tx-analytics-sparkline-fill" d="' . $this->escape($fillPath) . '"></path>';
        }
        $html .= '<path class="tx-analytics-sparkline-line" d="' . $this->escape($linePath) . '"></path>';
        if ($showLastPoint) {
            $html .= '<circle class="tx-analytics-sparkline-point" cx="' . $this->formatNumber($lastPoint[0]) . '" cy="' . $this->formatNumber($lastPoint[1]) . '" r="1.9"></circle>';
        }
        $html .= '</svg></div>';

        return $html;
    }

    /**
     * @param list<int|float|string> $values
     * @return list<array{0: float, 1: float}>
     */
    private function buildPoints(array $values): array
    {
        $numericValues = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $numericValues[] = (float)$value;
            }
        }

        $count = count($numericValues);
        if ($count === 0) {
            return [];
        }

        $min = min($numericValues);
        $max = max($numericValues);
        $range = $max - $min;
        $usableHeight = self::VIEW_BOX_HEIGHT - (self::PADDING * 2);
        $step = $count > 1 ? self::VIEW_BOX_WIDTH / ($count - 1) : 0.0;
        $centerX = self::VIEW_BOX_WIDTH / 2;
        $centerY = self::VIEW_BOX_HEIGHT / 2;

        $points = [];
        foreach ($numericValues as $index => $value) {
            $x = $count > 1 ? $index * $step : $centerX;
            $y = $range > 0.0
                ? self::PADDING + (($max - $value) / $range) * $usableHeight
                : $centerY;
            $points[] = [$x, $y];
        }

        return $points;
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
     * @param list<array{0: float, 1: float}> $points
     */
    private function buildFillPath(array $points): string
    {
        $linePath = $this->buildLinePath($points);
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
        return in_array($tone, ['primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'primary';
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
