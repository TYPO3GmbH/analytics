<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\View;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\View\SparklineRenderer;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SparklineRendererTest extends UnitTestCase
{
    #[Test]
    public function renderReturnsEmptyStringWithoutNumericValues(): void
    {
        $subject = new SparklineRenderer();

        self::assertSame('', $subject->render(['foo', 'bar']));
    }

    #[Test]
    public function renderNormalizesValuesIntoSvgPath(): void
    {
        $subject = new SparklineRenderer();

        $html = $subject->render([10, 20, 15], ['label' => 'Visitors']);

        self::assertStringContainsString('role="img"', $html);
        self::assertStringContainsString('aria-label="Visitors"', $html);
        self::assertStringContainsString('viewBox="0 0 100 32"', $html);
        self::assertStringContainsString('d="M0 30 L50 2 L100 16"', $html);
        self::assertStringContainsString('cx="100" cy="16"', $html);
    }

    #[Test]
    public function renderCentersFlatValues(): void
    {
        $subject = new SparklineRenderer();

        $html = $subject->render([5, 5, 5], ['fill' => false, 'showLastPoint' => false]);

        self::assertStringContainsString('d="M0 16 L50 16 L100 16"', $html);
        self::assertStringNotContainsString('tx-analytics-sparkline-fill', $html);
        self::assertStringNotContainsString('<circle', $html);
    }

    #[Test]
    public function renderEscapesLabelsAndClasses(): void
    {
        $subject = new SparklineRenderer();

        $html = $subject->render([1], [
            'label' => 'Visitors "today"',
            'class' => 'custom-class" onclick="alert(1)',
            'tone' => 'unknown',
        ]);

        self::assertStringContainsString('aria-label="Visitors &quot;today&quot;"', $html);
        self::assertStringContainsString('class="tx-analytics-sparkline custom-class&quot; onclick=&quot;alert(1)"', $html);
        self::assertStringContainsString('data-sparkline-tone="primary"', $html);
    }
}
