<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\ViewHelpers;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\View\SparklineRenderer;
use T3G\Analytics\ViewHelpers\SparklineViewHelper;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SparklineViewHelperTest extends UnitTestCase
{
    #[Test]
    public function rendersTheSparklineWithTheGivenOptions(): void
    {
        $subject = new SparklineViewHelper(new SparklineRenderer());
        $subject->setArguments([
            'values' => [0, 0, 0],
            'options' => ['label' => 'Trend: Visits', 'yMin' => 0, 'showLastPoint' => 0, 'preserveAspectRatio' => 'none'],
        ]);

        $html = $subject->render();

        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('Trend: Visits', $html);
        self::assertStringContainsString('preserveAspectRatio="none"', $html);
        // yMin 0 from Fluid arrives as int and still puts the flat line on the baseline.
        self::assertStringContainsString('d="M0 30 L50 30 L100 30"', $html);
        self::assertStringNotContainsString('tx-analytics-sparkline-point"', $html);
    }
}
