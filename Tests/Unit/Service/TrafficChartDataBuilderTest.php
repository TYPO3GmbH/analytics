<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Service\TrafficChartDataBuilder;
use T3G\Analytics\View\SparklineRenderer;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TrafficChartDataBuilderTest extends UnitTestCase
{
    private TrafficChartDataBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new TrafficChartDataBuilder(new SparklineRenderer());
    }

    /** calcScaleMax */

    /** @return array<string, array{int, int}> */
    public static function calcScaleMaxProvider(): array
    {
        return [
            'zero returns 9' => [0, 9],
            'negative returns 9' => [-5, 9],
            'one returns 3 (regression: was 0 before)' => [1, 3],
            'two returns 3' => [2, 3],
            'three returns 6' => [3, 6],
            'six returns 9' => [6, 9],
            'seven returns 15' => [7, 15],
            'ten returns 15' => [10, 15],
            'eleven rolls over to 30' => [11, 30],
            'hundred returns 150' => [100, 150],
            'thousand returns 1500' => [1000, 1500],
        ];
    }

    #[Test]
    #[DataProvider('calcScaleMaxProvider')]
    public function calcScaleMaxReturnsExpectedValue(int $max, int $expected): void
    {
        self::assertSame($expected, $this->subject->calcScaleMax($max));
    }

    #[Test]
    public function calcScaleMaxResultIsAlwaysDivisibleByThree(): void
    {
        foreach ([0, 1, 3, 7, 11, 50, 100, 999, 1000, 9999] as $max) {
            self::assertSame(0, $this->subject->calcScaleMax($max) % 3, "calcScaleMax($max) not divisible by 3");
        }
    }

    /** build — structure */

    #[Test]
    public function buildReturnsRequiredKeys(): void
    {
        $result = $this->subject->build(null, 'label');

        self::assertArrayHasKey('sparkline', $result);
        self::assertArrayHasKey('yLabels', $result);
        self::assertArrayHasKey('xLabels', $result);
    }

    #[Test]
    public function buildProducesFourYLabels(): void
    {
        $result = $this->subject->build(['labels' => ['2024-01-01'], 'data' => [6]], 'label');

        self::assertCount(4, $result['yLabels']);
    }

    #[Test]
    public function buildYLabelsDescendFromScaleMaxToZero(): void
    {
        // max=6 → calcScaleMax=9 → step=3 → labels top-to-bottom: 9, 6, 3, 0
        $result = $this->subject->build(['labels' => ['2024-01-01'], 'data' => [6]], 'label');

        self::assertSame(9, $result['yLabels'][0]['value']);
        self::assertSame(6, $result['yLabels'][1]['value']);
        self::assertSame(3, $result['yLabels'][2]['value']);
        self::assertSame(0, $result['yLabels'][3]['value']);
    }

    #[Test]
    public function buildFormatsLargeYLabelWithKSuffix(): void
    {
        // max=1000 → calcScaleMax=1500 → step=500 → top label: 1500 → "1.5k"
        $result = $this->subject->build(['labels' => ['2024-01-01'], 'data' => [1000]], 'label');

        self::assertSame('1.5k', $result['yLabels'][0]['label']);
        self::assertSame('1.0k', $result['yLabels'][1]['label']);
        self::assertSame('500', $result['yLabels'][2]['label']);
        self::assertSame('0', $result['yLabels'][3]['label']);
    }

    #[Test]
    public function buildUsesNineAsScaleMaxForEmptyData(): void
    {
        $result = $this->subject->build(['labels' => [], 'data' => []], 'label');

        self::assertSame(9, $result['yLabels'][0]['value']);
        self::assertSame(0, $result['yLabels'][3]['value']);
    }

    /** build — x-labels / visibleLabels */

    #[Test]
    public function buildReturnsFourEvenlySpacedXLabelsForSevenDayWindow(): void
    {
        $labels = ['2024-01-01', '2024-01-02', '2024-01-03', '2024-01-04', '2024-01-05', '2024-01-06', '2024-01-07'];
        $result = $this->subject->build(['labels' => $labels, 'data' => array_fill(0, 7, 1)], 'label');

        // lastIndex=6, slots=4 → indexes 0,2,4,6
        self::assertCount(4, $result['xLabels']);
        self::assertSame('01.01.2024', $result['xLabels'][0]);
        self::assertSame('03.01.2024', $result['xLabels'][1]);
        self::assertSame('05.01.2024', $result['xLabels'][2]);
        self::assertSame('07.01.2024', $result['xLabels'][3]);
    }

    #[Test]
    public function buildReturnsFiveXLabelsForThirtyDayWindow(): void
    {
        $labels = array_map(
            static fn (int $d): string => (new \DateTimeImmutable('2024-01-01'))->modify("+$d days")->format('Y-m-d'),
            range(0, 29)
        );
        $result = $this->subject->build(['labels' => $labels, 'data' => array_fill(0, 30, 1)], 'label');

        self::assertCount(5, $result['xLabels']);
    }

    #[Test]
    public function buildFormatsXLabelsAsDayAndMonth(): void
    {
        $result = $this->subject->build(['labels' => ['2024-03-15'], 'data' => [1]], 'label');

        self::assertSame(['15.03.2024'], $result['xLabels']);
    }
}
