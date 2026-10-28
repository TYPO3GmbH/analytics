<?php

declare(strict_types=1);

namespace T3G\Analytics\ViewHelpers;

use T3G\Analytics\View\SparklineRenderer;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Draws a sparkline with the SparklineRenderer. The renderer escapes all values,
 * so the SVG is returned unescaped.
 *
 * Usage: <analytics:sparkline values="{chart.values}" options="{label: chart.label, labels: chart.labels, axes: 1}" />
 */
final class SparklineViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(
        private readonly SparklineRenderer $sparklineRenderer,
    ) {
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('values', 'array', 'Numeric values of the line', true);
        $this->registerArgument('options', 'array', 'Options of SparklineRenderer::render()', false, []);
    }

    public function render(): string
    {
        /** @var list<int|float|string> $values */
        $values = array_values((array)$this->arguments['values']);
        /** @var array<string, mixed> $options */
        $options = (array)$this->arguments['options'];
        return $this->sparklineRenderer->render($values, $options);
    }
}
