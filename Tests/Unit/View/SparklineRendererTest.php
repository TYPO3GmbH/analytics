<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
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

    // (a) Two values: step = WIDTH / ($count - 1) = 100/1 = 100 → x-coords 0 and 100
    #[Test]
    public function renderTwoValuesPlacesPointsAtBothEdges(): void
    {
        $subject = new SparklineRenderer();

        // min=10, max=20, range=10, usableHeight=28
        // x[0]=0, y[0]=2+(20-10)/10*28=30  →  x[1]=100, y[1]=2+(20-20)/10*28=2
        $html = $subject->render([10, 20]);

        self::assertStringContainsString('d="M0 30 L100 2"', $html);
        self::assertStringContainsString('cx="100" cy="2"', $html);
    }

    // (b) Single value: point placed at centerX=50, centerY=16; fill suppressed (zero-area shape)
    #[Test]
    public function renderSingleValuePlacesPointAtCenter(): void
    {
        $subject = new SparklineRenderer();

        $html = $subject->render([42]);

        self::assertStringContainsString('d="M50 16"', $html);
        self::assertStringContainsString('cx="50" cy="16"', $html);
        self::assertStringNotContainsString('tx-analytics-sparkline-fill', $html);
    }

    // (c) Fill path baseline closes at y=30 (VIEW_BOX_HEIGHT - PADDING = 32 - 2)
    #[Test]
    public function renderFillPathClosesAtBaseline(): void
    {
        $subject = new SparklineRenderer();

        // line path: M0 30 L50 2 L100 16 → fill closes: L100 30 L0 30 Z
        $html = $subject->render([10, 20, 15]);

        self::assertStringContainsString('d="M0 30 L50 2 L100 16 L100 30 L0 30 Z"', $html);
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

    // (d) showLastPoint and fill flags tested independently
    #[Test]
    public function renderHidesCircleWhenShowLastPointIsFalse(): void
    {
        $subject = new SparklineRenderer();

        $html = $subject->render([10, 20, 15], ['showLastPoint' => false]);

        self::assertStringNotContainsString('<circle', $html);
        self::assertStringContainsString('tx-analytics-sparkline-fill', $html);
    }

    #[Test]
    public function renderHidesFillWhenFillIsFalse(): void
    {
        $subject = new SparklineRenderer();

        $html = $subject->render([10, 20, 15], ['fill' => false]);

        self::assertStringNotContainsString('tx-analytics-sparkline-fill', $html);
        self::assertStringContainsString('<circle', $html);
    }

    // (e) All five valid tone values set the correct data attribute
    /** @return array<string, array{string}> */
    public static function toneProvider(): array
    {
        return [
            'visits' => ['visits'],
            'visitors' => ['visitors'],
            'sessions' => ['sessions'],
            'bounce-rate' => ['bounce-rate'],
            'avg-duration' => ['avg-duration'],
            'continuation-rate' => ['continuation-rate'],
        ];
    }

    #[Test]
    #[DataProvider('toneProvider')]
    public function renderSetsValidToneOnDataAttribute(string $tone): void
    {
        $subject = new SparklineRenderer();

        $html = $subject->render([1, 2], ['tone' => $tone]);

        self::assertStringContainsString('data-sparkline-tone="' . $tone . '"', $html);
    }

    #[Test]
    public function renderFallsBackToPrimaryForUnknownTone(): void
    {
        $subject = new SparklineRenderer();

        $html = $subject->render([1], ['tone' => 'unknown']);

        self::assertStringContainsString('data-sparkline-tone="series-1"', $html);
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
        self::assertStringContainsString('data-sparkline-tone="series-1"', $html);
    }
}
