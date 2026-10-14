<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Utility\AnalyticsQueryUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AnalyticsQueryUtilityTest extends UnitTestCase
{
    #[Test]
    public function utcDayRangeUsesFullUtcDaysAndIgnoresTheLocalOffset(): void
    {
        $range = AnalyticsQueryUtility::utcDayRange(
            new \DateTimeImmutable('2026-06-01 23:59:59+02:00'),
            new \DateTimeImmutable('2026-06-07 08:00:00+02:00')
        );

        self::assertSame(['start' => '2026-06-01T00:00:00.000Z', 'end' => '2026-06-07T23:59:59.999Z'], $range);
    }

    #[Test]
    public function utcDayGraphQueryRequestsADailyTimeSeries(): void
    {
        $query = AnalyticsQueryUtility::utcDayGraphQuery(new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-07'));

        parse_str(ltrim($query, '?'), $parameters);
        self::assertSame(
            ['from' => '2026-06-01T00:00:00.000Z', 'until' => '2026-06-07T23:59:59.999Z', 'type' => 'time-series', 'unit' => 'day'],
            $parameters
        );
    }

    #[Test]
    public function pageUrlWhereIsEmptyWithoutFilter(): void
    {
        self::assertSame(['and' => []], AnalyticsQueryUtility::pageUrlWhere([]));
    }

    #[Test]
    public function pageUrlWhereUsesTheInOperatorWithAllValues(): void
    {
        self::assertSame(
            ['and' => [['member' => 'pageUrl', 'operator' => 'in', 'values' => ['://example.com', '://example.ch']]]],
            AnalyticsQueryUtility::pageUrlWhere(['://example.com', '://example.ch'])
        );
    }
}
