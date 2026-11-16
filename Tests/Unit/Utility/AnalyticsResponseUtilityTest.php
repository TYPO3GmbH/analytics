<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Utility;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Utility\AnalyticsResponseUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AnalyticsResponseUtilityTest extends UnitTestCase
{
    /** decodeSettledResponse */

    #[Test]
    public function decodeSettledResponseReturnsTheDecodedBody(): void
    {
        $result = ['state' => 'fulfilled', 'value' => new Response(200, [], '{"payload":[{"visitCount":3}]}')];

        self::assertSame(['payload' => [['visitCount' => 3]]], AnalyticsResponseUtility::decodeSettledResponse($result));
    }

    #[Test]
    public function decodeSettledResponseThrowsTheReasonOfARejectedRequest(): void
    {
        $reason = new \RuntimeException('Timeout');

        $this->expectExceptionObject($reason);

        AnalyticsResponseUtility::decodeSettledResponse(['state' => 'rejected', 'reason' => $reason]);
    }

    /** sessionTotals */

    #[Test]
    public function sessionTotalsReadsTheFirstRow(): void
    {
        self::assertSame(
            ['visitCount' => 12, 'visitorCount' => 7, 'bounceRate' => 42.5],
            AnalyticsResponseUtility::sessionTotals(['payload' => [['visitCount' => 12, 'visitorCount' => 7, 'bounceRate' => 42.5]]])
        );
    }

    #[Test]
    public function sessionTotalsReturnsZeroWithoutPayload(): void
    {
        self::assertSame(['visitCount' => 0, 'visitorCount' => 0, 'bounceRate' => 0.0], AnalyticsResponseUtility::sessionTotals([]));
    }

    /** dailySeries */

    #[Test]
    public function dailySeriesMatchesTheTimeOnPageByDate(): void
    {
        $sessions = ['payload' => [
            ['date' => '2026-06-01T00:00:00.000', 'visitCount' => 2, 'visitorCount' => 1, 'bounceRate' => 50],
            ['date' => '2026-06-02T00:00:00.000', 'visitCount' => 4, 'visitorCount' => 3, 'bounceRate' => 25],
            ['visitCount' => 9],
        ]];
        $timeOnPage = ['dates' => ['2026-06-02'], 'values' => [30], 'average' => 30];

        self::assertSame(
            [
                'dates' => ['2026-06-01', '2026-06-02'],
                'visits' => [2, 4],
                'visitors' => [1, 3],
                'bounceRate' => [50.0, 25.0],
                'avgTimeOnPage' => [0, 30],
            ],
            AnalyticsResponseUtility::dailySeries($sessions, $timeOnPage)
        );
    }

    /** timeOnPageGraph */

    #[Test]
    public function timeOnPageGraphRoundsTheValuesAndTheAverage(): void
    {
        $body = ['payload' => [
            'labels' => ['2026-06-01T00:00:00Z', '2026-06-02T00:00:00Z', '2026-06-03T00:00:00Z'],
            'datasets' => [['data' => [12.4, 'x', 30.6], 'totalAverage' => 14.5]],
        ]];

        self::assertSame(
            ['dates' => ['2026-06-01', '2026-06-02', '2026-06-03'], 'values' => [12, 0, 31], 'average' => 15],
            AnalyticsResponseUtility::timeOnPageGraph($body)
        );
    }

    /** timeSeriesData */

    #[Test]
    public function timeSeriesDataReadsTheFirstDataset(): void
    {
        $body = ['payload' => ['labels' => ['2026-06-01', '2026-06-02'], 'datasets' => [['data' => [3, '4.5']], ['data' => [9, 9]]]]];

        self::assertSame(
            ['data' => [3.0, 4.5], 'labels' => ['2026-06-01', '2026-06-02']],
            AnalyticsResponseUtility::timeSeriesData($body)
        );
    }

    /** graph */

    #[Test]
    public function graphReadsLabelsAndAllDatasets(): void
    {
        $payload = ['labels' => ['2026-06-01'], 'datasets' => [['label' => 'Visits', 'data' => [3], 'total' => 3]]];

        self::assertSame($payload, AnalyticsResponseUtility::graph($payload));
    }

    #[Test]
    public function graphIsEmptyWithoutPayload(): void
    {
        self::assertSame(['labels' => [], 'datasets' => []], AnalyticsResponseUtility::graph(null));
    }
}
