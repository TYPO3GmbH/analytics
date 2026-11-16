<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Service\AnalyticsDataClient;
use T3G\Analytics\Service\ApiExceptionExtractor;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AnalyticsDataClientTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private MockHandler $mockHandler;
    private array $httpHistory = [];
    private AnalyticsDataClient $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockHandler = new MockHandler();
        $this->httpHistory = [];
        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->httpHistory));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = ['verify' => false, 'handler' => $stack];

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn('');
        $apiConfiguration = new ApiConfiguration($extensionConfiguration);

        $this->subject = new AnalyticsDataClient(
            new RequestFactory(new GuzzleClientFactory()),
            new ApiExceptionExtractor(),
            $apiConfiguration,
            new GuzzleClientFactory(),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']);
        parent::tearDown();
    }

    private function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }

    /** fetchTopPages */

    #[Test]
    public function fetchTopPagesSendsPostToCorrectEndpoint(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchTopPages('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-07'), $this->date('2026-05-25'), $this->date('2026-05-31'));

        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/v2/websites/w-123/analytics/pages', (string)$request->getUri());
    }

    #[Test]
    public function fetchTopPagesSendsXApiKeyHeader(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchTopPages('w-123', 'twpl-test-key', $this->date('2026-06-01'), $this->date('2026-06-07'), $this->date('2026-05-25'), $this->date('2026-05-31'));

        self::assertSame('twpl-test-key', $this->httpHistory[0]['request']->getHeaderLine('X-Api-Key'));
    }

    #[Test]
    public function fetchTopPagesRequestsComparisonMetrics(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchTopPages('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-07'), $this->date('2026-05-25'), $this->date('2026-05-31'));

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertContains('visitCount', $body['metrics']);
        self::assertContains('previousVisitCount', $body['metrics']);
        self::assertContains('visitCountPercentageChange', $body['metrics']);
        self::assertContains('visitPercentOfTotal', $body['metrics']);
    }

    #[Test]
    public function fetchTopPagesRequestsOnlyPageUrlDimension(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchTopPages('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-07'), $this->date('2026-05-25'), $this->date('2026-05-31'));

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertSame(['pageUrl'], $body['dimensions']);
    }

    #[Test]
    public function fetchTopPagesIncludesEmptyWhereAndFilter(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchTopPages('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-07'), $this->date('2026-05-25'), $this->date('2026-05-31'));

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertSame(['and' => []], $body['where']);
    }

    /**
     * @return \Generator<string, array{string, int, string}>
     */
    public static function pageUrlFilterRequestProvider(): \Generator
    {
        yield 'top pages' => ['fetchTopPages', 4, '{"payload":[]}'];
        yield 'visits graph' => ['fetchSiteVisitsGraph', 2, '{"payload":{"labels":[],"datasets":[]}}'];
        yield 'sessions graph' => ['fetchSiteSessionsGraph', 2, '{"payload":{"labels":[],"datasets":[]}}'];
        yield 'visitors graph' => ['fetchSiteVisitorsGraph', 2, '{"payload":{"labels":[],"datasets":[]}}'];
        yield 'traffic share' => ['fetchTrafficShareInDepth', 2, '{"labels":[],"datasets":[]}'];
        yield 'device sessions' => ['fetchDeviceSessions', 2, '{"payload":[]}'];
        yield 'browser sessions' => ['fetchBrowserSessions', 2, '{"payload":[]}'];
        yield 'country sessions' => ['fetchCountrySessions', 2, '{"payload":[]}'];
    }

    #[Test]
    #[DataProvider('pageUrlFilterRequestProvider')]
    public function siteQueriesLimitThePagesToThePageUrlFilter(string $method, int $dateCount, string $responseBody): void
    {
        $this->mockHandler->append(new Response(200, [], $responseBody));
        $dates = array_fill(0, $dateCount, $this->date('2026-06-01'));

        $this->subject->{$method}('w-123', 'api-key', ...$dates, pageUrlFilter: ['://example.com', '://example.ch']);

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertSame(
            ['and' => [['member' => 'pageUrl', 'operator' => 'in', 'values' => ['://example.com', '://example.ch']]]],
            $body['where']
        );
    }

    /**
     * @return \Generator<string, array{string, string}>
     */
    public static function utcDayGraphRequestProvider(): \Generator
    {
        yield 'visits graph' => ['fetchSiteVisitsGraph', '{"payload":{"labels":[],"datasets":[]}}'];
        yield 'sessions graph' => ['fetchSiteSessionsGraph', '{"payload":{"labels":[],"datasets":[]}}'];
        yield 'visitors graph' => ['fetchSiteVisitorsGraph', '{"payload":{"labels":[],"datasets":[]}}'];
        yield 'traffic share' => ['fetchTrafficShareInDepth', '{"labels":[],"datasets":[]}'];
    }

    #[Test]
    #[DataProvider('utcDayGraphRequestProvider')]
    public function graphQueriesUseFullUtcDays(string $method, string $responseBody): void
    {
        $this->mockHandler->append(new Response(200, [], $responseBody));

        $this->subject->{$method}('w-123', 'api-key', $this->date('2026-06-01 23:59:59+02:00'), $this->date('2026-06-07 23:59:59+02:00'));

        parse_str($this->httpHistory[0]['request']->getUri()->getQuery(), $query);
        self::assertSame('2026-06-01T00:00:00.000Z', $query['from']);
        self::assertSame('2026-06-07T23:59:59.999Z', $query['until']);
    }

    /**
     * @return \Generator<string, array{string, int, string}>
     */
    public static function utcDayRangeRequestProvider(): \Generator
    {
        yield 'top pages' => ['fetchTopPages', 4, '{"payload":[]}'];
        yield 'device sessions' => ['fetchDeviceSessions', 4, '{"payload":[]}'];
        yield 'browser sessions' => ['fetchBrowserSessions', 4, '{"payload":[]}'];
        yield 'country sessions' => ['fetchCountrySessions', 4, '{"payload":[]}'];
    }

    #[Test]
    #[DataProvider('utcDayRangeRequestProvider')]
    public function rangeQueriesUseFullUtcDaysForBothPeriods(string $method, int $dateCount, string $responseBody): void
    {
        $this->mockHandler->append(new Response(200, [], $responseBody));
        $dates = array_slice([
            $this->date('2026-06-01 23:59:59+02:00'),
            $this->date('2026-06-07 23:59:59+02:00'),
            $this->date('2026-05-25 23:59:59+02:00'),
            $this->date('2026-05-31 23:59:59+02:00'),
        ], 0, $dateCount);

        $this->subject->{$method}('w-123', 'api-key', ...$dates);

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertSame(['start' => '2026-06-01T00:00:00.000Z', 'end' => '2026-06-07T23:59:59.999Z'], $body['dateRange']);
        self::assertSame(['start' => '2026-05-25T00:00:00.000Z', 'end' => '2026-05-31T23:59:59.999Z'], $body['previousDateRange']);
    }

    #[Test]
    public function fetchSitePerformanceLimitsAllRequestsToThePageUrlFilter(): void
    {
        $this->appendSitePerformanceResponses();

        $this->subject->fetchSitePerformance('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-07'), $this->date('2026-05-25'), $this->date('2026-05-31'), ['://example.com']);

        $expected = ['and' => [['member' => 'pageUrl', 'operator' => 'in', 'values' => ['://example.com']]]];
        self::assertCount(5, $this->httpHistory);
        foreach ($this->httpHistory as $transaction) {
            self::assertSame($expected, json_decode((string)$transaction['request']->getBody(), true)['where']);
        }
    }

    #[Test]
    public function fetchTopPagesIncludesPreviousDateRange(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchTopPages('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-07'), $this->date('2026-05-25'), $this->date('2026-05-31'));

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertArrayHasKey('previousDateRange', $body);
        self::assertStringStartsWith('2026-05-25', $body['previousDateRange']['start']);
        self::assertStringStartsWith('2026-05-31', $body['previousDateRange']['end']);
    }

    #[Test]
    public function fetchTopPagesReturnsAllPayloadRows(): void
    {
        $rows = [
            ['pageUrl' => 'https://example.com/', 'visitCount' => 100, 'previousVisitCount' => 80],
            ['pageUrl' => 'https://example.com/about', 'visitCount' => 50, 'previousVisitCount' => 60],
        ];
        $this->mockHandler->append(new Response(200, [], json_encode(['payload' => $rows])));

        $result = $this->subject->fetchTopPages('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-07'), $this->date('2026-05-25'), $this->date('2026-05-31'));

        self::assertCount(2, $result);
        self::assertSame(100, $result[0]['visitCount']);
        self::assertSame(50, $result[1]['visitCount']);
    }

    #[Test]
    public function fetchTopPagesReturnsEmptyArrayOnEmptyPayload(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $result = $this->subject->fetchTopPages('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-07'), $this->date('2026-05-25'), $this->date('2026-05-31'));

        self::assertSame([], $result);
    }

    #[Test]
    public function fetchTopPagesThrowsOnHttpError(): void
    {
        $this->mockHandler->append(new Response(422, [], '{"error":{"code":"unprocessable_entity"}}'));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->fetchTopPages('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-07'), $this->date('2026-05-25'), $this->date('2026-05-31'));
    }

    #[Test]
    public function fetchTopPagesRespectsCustomLimit(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchTopPages('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-07'), $this->date('2026-05-25'), $this->date('2026-05-31'), 5);

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertSame(5, $body['pagination']['pageSize']);
    }

    /** fetchPageAnalytics */

    #[Test]
    public function fetchPageAnalyticsQueriesFullUtcDays(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchPageAnalytics('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01 23:59:59+02:00'), $this->date('2026-06-07 23:59:59+02:00'));

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertSame(['start' => '2026-06-01T00:00:00.000Z', 'end' => '2026-06-07T23:59:59.999Z'], $body['dateRange']);
    }

    #[Test]
    public function fetchPageAnalyticsSendsPostToCorrectEndpoint(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchPageAnalytics('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertCount(1, $this->httpHistory);
        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/v2/websites/w-123/analytics/pages', (string)$request->getUri());
    }

    #[Test]
    public function fetchPageAnalyticsSendsXApiKeyHeader(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchPageAnalytics('w-123', 'twpl-test-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame('twpl-test-key', $this->httpHistory[0]['request']->getHeaderLine('X-Api-Key'));
    }

    #[Test]
    public function fetchPageAnalyticsReturnsFirstPayloadRow(): void
    {
        $row = ['visitCount' => 42, 'bounceRate' => 55.0, 'averageVisitDuration' => 120.0];
        $this->mockHandler->append(new Response(200, [], json_encode(['payload' => [$row, ['visitCount' => 1]]])));

        $result = $this->subject->fetchPageAnalytics('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame(42, $result['visitCount']);
    }

    #[Test]
    public function fetchPageAnalyticsReturnsNullOnEmptyPayload(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $result = $this->subject->fetchPageAnalytics('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertNull($result);
    }

    #[Test]
    public function fetchPageAnalyticsIncludesWhereFilterForPageUrl(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchPageAnalytics('w-123', 'api-key', 'https://example.com/de/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        $filter = $body['where']['and'][0];
        self::assertSame('pageUrl', $filter['member']);
        self::assertSame('eq', $filter['operator']);
        self::assertSame(['https://example.com/de/'], $filter['values']);
    }

    #[Test]
    public function fetchPageAnalyticsRequestsOnlyRequiredMetrics(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchPageAnalytics('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertSame(['visitCount', 'bounceRate', 'averageVisitDuration'], $body['metrics']);
    }

    #[Test]
    public function fetchPageAnalyticsThrowsOnNetworkError(): void
    {
        $this->mockHandler->append(new \RuntimeException('connection refused'));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->fetchPageAnalytics('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));
    }

    #[Test]
    public function fetchPageAnalyticsThrowsOnHttpError(): void
    {
        $this->mockHandler->append(new Response(401, [], '{"message":"Unauthorized"}'));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->fetchPageAnalytics('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));
    }

    /** fetchVisitsTimeSeries */

    #[Test]
    public function fetchVisitsTimeSeriesSendsPostToVisitsGraphEndpoint(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{"datasets":[{"data":[]}]}}'));

        $this->subject->fetchVisitsTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/v2/websites/w-123/visits/graph', (string)$request->getUri());
    }

    #[Test]
    public function fetchVisitsTimeSeriesExtractsDataFromDatasetsArray(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{"datasets":[{"label":"Overall Visits","data":[1,4,2],"total":7}]}}'));

        $result = $this->subject->fetchVisitsTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame([1.0, 4.0, 2.0], $result);
    }

    #[Test]
    public function fetchVisitsTimeSeriesReturnsEmptyArrayWhenDatasetsAbsent(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{}}'));

        $result = $this->subject->fetchVisitsTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame([], $result);
    }

    #[Test]
    public function fetchVisitsTimeSeriesIncludesTimeSeriesBodyParams(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{"datasets":[{"data":[]}]}}'));

        $this->subject->fetchVisitsTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $request = $this->httpHistory[0]['request'];
        $query = (string)$request->getUri();
        self::assertStringContainsString('type=time-series', $query);
        self::assertStringContainsString('unit=day', $query);
        $body = json_decode((string)$request->getBody(), true);
        self::assertSame('https://example.com/', $body['where']['and'][0]['values'][0]);
    }

    #[Test]
    public function fetchVisitsTimeSeriesThrowsOnHttpError(): void
    {
        $this->mockHandler->append(new Response(403, [], '{}'));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->fetchVisitsTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));
    }

    /** fetchBounceRateTimeSeries */

    #[Test]
    public function fetchBounceRateTimeSeriesSendsPostToBounceRateEndpoint(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{"datasets":[{"data":[]}]}}'));

        $this->subject->fetchBounceRateTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/v2/websites/w-123/stats/bounce-rate/graph', (string)$request->getUri());
    }

    #[Test]
    public function fetchBounceRateTimeSeriesExtractsDataFromDatasetsArray(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{"datasets":[{"label":"Bounce Rate","data":[0,50,25],"totalAverage":25}]}}'));

        $result = $this->subject->fetchBounceRateTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame([0.0, 50.0, 25.0], $result);
    }

    /** fetchAvgDurationTimeSeries */

    #[Test]
    public function fetchAvgDurationTimeSeriesSendsPostToCorrectEndpoint(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{"datasets":[{"data":[]}]}}'));

        $this->subject->fetchAvgDurationTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/v2/websites/w-123/stats/average-page-view-duration/graph', (string)$request->getUri());
    }

    #[Test]
    public function fetchAvgDurationTimeSeriesExtractsDataFromDatasetsArray(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{"datasets":[{"label":"Avg Duration","data":[30,90,60]}]}}'));

        $result = $this->subject->fetchAvgDurationTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame([30.0, 90.0, 60.0], $result);
    }

    /** fetchSitePerformance */

    #[Test]
    public function fetchSitePerformanceSendsFivePostRequests(): void
    {
        $this->appendSitePerformanceResponses();

        $this->subject->fetchSitePerformance('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-07'), $this->date('2026-05-25'), $this->date('2026-05-31'));

        self::assertCount(5, $this->httpHistory);
        foreach ($this->httpHistory as $entry) {
            self::assertSame('POST', $entry['request']->getMethod());
            self::assertSame('api-key', $entry['request']->getHeaderLine('X-Api-Key'));
        }
    }

    #[Test]
    public function fetchSitePerformanceRequestsSessionTotalsWithoutDimensions(): void
    {
        $this->appendSitePerformanceResponses();

        $this->subject->fetchSitePerformance('w-123', 'api-key', $this->date('2026-06-01 23:59:59+02:00'), $this->date('2026-06-07 23:59:59+02:00'), $this->date('2026-05-25 23:59:59+02:00'), $this->date('2026-05-31 23:59:59+02:00'));

        $uri = (string)$this->httpHistory[0]['request']->getUri();
        self::assertStringContainsString('/v2/websites/w-123/analytics/sessions', $uri);
        self::assertStringContainsString('trace=sitePerformanceDashboard', $uri);
        $current = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        $previous = json_decode((string)$this->httpHistory[1]['request']->getBody(), true);
        self::assertSame(['visitCount', 'visitorCount', 'bounceRate'], $current['metrics']);
        self::assertArrayNotHasKey('dimensions', $current);
        self::assertSame(['start' => '2026-06-01T00:00:00.000Z', 'end' => '2026-06-07T23:59:59.999Z'], $current['dateRange']);
        self::assertSame(['start' => '2026-05-25T00:00:00.000Z', 'end' => '2026-05-31T23:59:59.999Z'], $previous['dateRange']);
    }

    #[Test]
    public function fetchSitePerformanceRequestsDailySessionsForCurrentPeriod(): void
    {
        $this->appendSitePerformanceResponses();

        $this->subject->fetchSitePerformance('w-123', 'api-key', $this->date('2026-06-01 23:59:59+02:00'), $this->date('2026-06-07 23:59:59+02:00'), $this->date('2026-05-25 23:59:59+02:00'), $this->date('2026-05-31 23:59:59+02:00'));

        self::assertStringContainsString('trace=sitePerformanceDaily', (string)$this->httpHistory[2]['request']->getUri());
        $body = json_decode((string)$this->httpHistory[2]['request']->getBody(), true);
        self::assertSame(['visitCount', 'visitorCount', 'bounceRate'], $body['metrics']);
        self::assertSame(['date'], $body['dimensions']);
        self::assertSame('day', $body['granularity']);
        self::assertSame(['start' => '2026-06-01T00:00:00.000Z', 'end' => '2026-06-07T23:59:59.999Z'], $body['dateRange']);
    }

    #[Test]
    public function fetchSitePerformanceRequestsPageViewDurationGraphForBothPeriods(): void
    {
        $this->appendSitePerformanceResponses();

        $this->subject->fetchSitePerformance('w-123', 'api-key', $this->date('2026-06-01 23:59:59+02:00'), $this->date('2026-06-07 23:59:59+02:00'), $this->date('2026-05-25 23:59:59+02:00'), $this->date('2026-05-31 23:59:59+02:00'));

        $current = $this->httpHistory[3]['request']->getUri();
        $previous = $this->httpHistory[4]['request']->getUri();
        self::assertStringContainsString('/v2/websites/w-123/stats/average-page-view-duration/graph', (string)$current);
        parse_str($current->getQuery(), $currentQuery);
        parse_str($previous->getQuery(), $previousQuery);
        self::assertSame(['from' => '2026-06-01T00:00:00.000Z', 'until' => '2026-06-07T23:59:59.999Z', 'type' => 'time-series', 'unit' => 'day'], $currentQuery);
        self::assertSame('2026-05-25T00:00:00.000Z', $previousQuery['from']);
        self::assertSame(['where' => ['and' => []]], json_decode((string)$this->httpHistory[3]['request']->getBody(), true));
    }

    #[Test]
    public function fetchSitePerformanceCombinesTotalsSeriesAndTimeOnPage(): void
    {
        $this->appendSitePerformanceResponses(
            current: ['visitCount' => 30, 'visitorCount' => 23, 'bounceRate' => 41.5],
            previous: ['visitCount' => 12, 'visitorCount' => 9, 'bounceRate' => 50],
            daily: [
                ['date' => '2026-06-01T00:00:00Z', 'visitCount' => 12, 'visitorCount' => 10, 'bounceRate' => 41.5],
                ['date' => '2026-06-02T00:00:00Z', 'visitCount' => 18, 'visitorCount' => 13, 'bounceRate' => 0],
            ],
            currentTimeOnPage: $this->timeOnPageGraph(['2026-06-01', '2026-06-02'], [64.6, 3], 79.6),
            previousTimeOnPage: $this->timeOnPageGraph([], [], 64),
        );

        $result = $this->subject->fetchSitePerformance('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-02'), $this->date('2026-05-30'), $this->date('2026-05-31'));

        self::assertSame(['visitCount' => 30, 'visitorCount' => 23, 'bounceRate' => 41.5, 'avgTimeOnPage' => 80], $result['current']);
        self::assertSame(['visitCount' => 12, 'visitorCount' => 9, 'bounceRate' => 50.0, 'avgTimeOnPage' => 64], $result['previous']);
        self::assertSame([
            'dates' => ['2026-06-01', '2026-06-02'],
            'visits' => [12, 18],
            'visitors' => [10, 13],
            'bounceRate' => [41.5, 0.0],
            'avgTimeOnPage' => [65, 3],
        ], $result['series']);
        self::assertSame([], $result['failures']);
    }

    #[Test]
    public function fetchSitePerformanceMatchesTimeOnPageByDate(): void
    {
        $this->appendSitePerformanceResponses(
            daily: [
                ['date' => '2026-06-01T00:00:00Z', 'visitCount' => 1, 'visitorCount' => 1, 'bounceRate' => 0],
                ['date' => '2026-06-02T00:00:00Z', 'visitCount' => 1, 'visitorCount' => 1, 'bounceRate' => 0],
                ['date' => '2026-06-03T00:00:00Z', 'visitCount' => 1, 'visitorCount' => 1, 'bounceRate' => 0],
            ],
            // The graph misses 2026-06-02, which must not shift the other values.
            currentTimeOnPage: $this->timeOnPageGraph(['2026-06-01', '2026-06-03'], [10, 30], 20),
        );

        $result = $this->subject->fetchSitePerformance('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-03'), $this->date('2026-05-29'), $this->date('2026-05-31'));

        self::assertSame([10, 0, 30], $result['series']['avgTimeOnPage']);
    }

    #[Test]
    public function fetchSitePerformanceRecordsFailuresPerRequest(): void
    {
        $this->mockHandler->append(new Response(200, [], (string)json_encode(['payload' => [['visitCount' => 5, 'visitorCount' => 4, 'bounceRate' => 40.0]]])));
        $this->mockHandler->append(new Response(500, [], '{}'));
        $this->mockHandler->append(new Response(500, [], '{}'));
        $this->mockHandler->append(new Response(500, [], '{}'));
        $this->mockHandler->append(new Response(200, [], $this->timeOnPageGraph([], [], 30)));

        $result = $this->subject->fetchSitePerformance('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-07'), $this->date('2026-05-25'), $this->date('2026-05-31'));

        self::assertSame(['visitCount' => 5, 'visitorCount' => 4, 'bounceRate' => 40.0, 'avgTimeOnPage' => 0], $result['current']);
        self::assertSame(['visitCount' => 0, 'visitorCount' => 0, 'bounceRate' => 0.0, 'avgTimeOnPage' => 30], $result['previous']);
        self::assertSame([], $result['series']['dates']);
        self::assertEqualsCanonicalizing(['previous', 'series', 'currentTimeOnPage'], array_keys($result['failures']));
    }

    /**
     * @param array<string, int|float> $current
     * @param array<string, int|float> $previous
     * @param list<array<string, int|float|string>> $daily
     */
    private function appendSitePerformanceResponses(array $current = [], array $previous = [], array $daily = [], ?string $currentTimeOnPage = null, ?string $previousTimeOnPage = null): void
    {
        $this->mockHandler->append(new Response(200, [], (string)json_encode(['payload' => [$current]])));
        $this->mockHandler->append(new Response(200, [], (string)json_encode(['payload' => [$previous]])));
        $this->mockHandler->append(new Response(200, [], (string)json_encode(['payload' => $daily])));
        $this->mockHandler->append(new Response(200, [], $currentTimeOnPage ?? $this->timeOnPageGraph([], [], 0)));
        $this->mockHandler->append(new Response(200, [], $previousTimeOnPage ?? $this->timeOnPageGraph([], [], 0)));
    }

    /**
     * @param list<string> $dates
     * @param list<int|float> $values
     */
    private function timeOnPageGraph(array $dates, array $values, float $average): string
    {
        return (string)json_encode(['payload' => [
            'labels' => array_map(static fn (string $date): string => $date . 'T00:00:00Z', $dates),
            'datasets' => [['label' => 'Average Page View Duration', 'data' => $values, 'totalAverage' => $average]],
        ]]);
    }

    /** fetchAllTimeSeries */

    #[Test]
    public function fetchAllTimeSeriesQueriesFullUtcDays(): void
    {
        $empty = '{"payload":{"labels":[],"datasets":[{"data":[]}]}}';
        for ($i = 0; $i < 3; $i++) {
            $this->mockHandler->append(new Response(200, [], $empty));
        }

        $this->subject->fetchAllTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01 23:59:59+02:00'), $this->date('2026-06-07 23:59:59+02:00'));

        foreach ($this->httpHistory as $entry) {
            parse_str($entry['request']->getUri()->getQuery(), $query);
            self::assertSame('2026-06-01T00:00:00.000Z', $query['from']);
            self::assertSame('2026-06-07T23:59:59.999Z', $query['until']);
        }
    }

    #[Test]
    public function fetchAllTimeSeriesSendsThreeParallelPostRequests(): void
    {
        $empty = '{"payload":{"labels":[],"datasets":[{"data":[]}]}}';
        $this->mockHandler->append(new Response(200, [], $empty));
        $this->mockHandler->append(new Response(200, [], $empty));
        $this->mockHandler->append(new Response(200, [], $empty));

        $this->subject->fetchAllTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertCount(3, $this->httpHistory);
        foreach ($this->httpHistory as $entry) {
            self::assertSame('POST', $entry['request']->getMethod());
        }
    }

    #[Test]
    public function fetchAllTimeSeriesReturnsDataForEachSeries(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{"labels":["2026-06-01","2026-06-02","2026-06-03"],"datasets":[{"data":[1,2,3]}]}}'));
        $this->mockHandler->append(new Response(200, [], '{"payload":{"labels":["2026-06-01","2026-06-02","2026-06-03"],"datasets":[{"data":[10,20,30]}]}}'));
        $this->mockHandler->append(new Response(200, [], '{"payload":{"labels":["2026-06-01","2026-06-02","2026-06-03"],"datasets":[{"data":[60,90,120]}]}}'));

        $result = $this->subject->fetchAllTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame([1.0, 2.0, 3.0], $result['visits']);
        self::assertSame([10.0, 20.0, 30.0], $result['bounceRate']);
        self::assertSame([60.0, 90.0, 120.0], $result['avgDuration']);
        self::assertSame(['2026-06-01', '2026-06-02', '2026-06-03'], $result['dates']);
        self::assertSame([], $result['failures']);
    }

    #[Test]
    public function fetchAllTimeSeriesReturnsEmptyArrayAndRecordsFailureForFailedSeries(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{"labels":["2026-06-01","2026-06-02","2026-06-03"],"datasets":[{"data":[1,2,3]}]}}'));
        $this->mockHandler->append(new Response(500, [], '{}'));
        $this->mockHandler->append(new Response(200, [], '{"payload":{"labels":["2026-06-01","2026-06-02","2026-06-03"],"datasets":[{"data":[60,90,120]}]}}'));

        $result = $this->subject->fetchAllTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame([1.0, 2.0, 3.0], $result['visits']);
        self::assertSame([], $result['bounceRate']);
        self::assertSame([60.0, 90.0, 120.0], $result['avgDuration']);
        self::assertArrayHasKey('bounceRate', $result['failures']);
    }

    /** fetchSiteVisitsGraph */

    #[Test]
    public function fetchSiteVisitsGraphSendsPostToCorrectEndpoint(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{"labels":[],"datasets":[]}}'));

        $this->subject->fetchSiteVisitsGraph('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/v2/websites/w-123/visits/graph', (string)$request->getUri());
    }

    #[Test]
    public function fetchSiteVisitsGraphSendsXApiKeyHeader(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{"labels":[],"datasets":[]}}'));

        $this->subject->fetchSiteVisitsGraph('w-123', 'twpl-test-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame('twpl-test-key', $this->httpHistory[0]['request']->getHeaderLine('X-Api-Key'));
    }

    #[Test]
    public function fetchSiteVisitsGraphIncludesTimeSeriesQueryParams(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{"labels":[],"datasets":[]}}'));

        $this->subject->fetchSiteVisitsGraph('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $uri = (string)$this->httpHistory[0]['request']->getUri();
        self::assertStringContainsString('type=time-series', $uri);
        self::assertStringContainsString('unit=day', $uri);
    }

    #[Test]
    public function fetchSiteVisitsGraphSendsEmptyWhereClause(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{"labels":[],"datasets":[]}}'));

        $this->subject->fetchSiteVisitsGraph('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertSame(['and' => []], $body['where']);
    }

    #[Test]
    public function fetchSiteVisitsGraphReturnsLabelsAndDatasets(): void
    {
        $payload = json_encode(['payload' => [
            'labels' => ['2026-06-01', '2026-06-02'],
            'datasets' => [['label' => 'Overall Visits', 'data' => [12, 34], 'total' => 46]],
        ]]);
        $this->mockHandler->append(new Response(200, [], $payload));

        $result = $this->subject->fetchSiteVisitsGraph('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-02'));

        self::assertSame(['2026-06-01', '2026-06-02'], $result['labels']);
        self::assertCount(1, $result['datasets']);
        self::assertSame([12, 34], $result['datasets'][0]['data']);
    }

    #[Test]
    public function fetchSiteVisitsGraphReturnsEmptyArraysOnEmptyPayload(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{}}'));

        $result = $this->subject->fetchSiteVisitsGraph('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame([], $result['labels']);
        self::assertSame([], $result['datasets']);
    }

    #[Test]
    public function fetchSiteVisitsGraphThrowsOnHttpError(): void
    {
        $this->mockHandler->append(new Response(403, [], '{}'));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->fetchSiteVisitsGraph('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));
    }

    /** fetchTrafficShareInDepth */

    #[Test]
    public function fetchTrafficShareInDepthSendsPostToCorrectEndpoint(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"labels":[],"datasets":[]}'));

        $this->subject->fetchTrafficShareInDepth('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/v2/websites/w-123/traffic-api/traffic-share/in-depth', (string)$request->getUri());
    }

    #[Test]
    public function fetchTrafficShareInDepthSendsXApiKeyHeader(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"labels":[],"datasets":[]}'));

        $this->subject->fetchTrafficShareInDepth('w-123', 'twpl-test-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame('twpl-test-key', $this->httpHistory[0]['request']->getHeaderLine('X-Api-Key'));
    }

    #[Test]
    public function fetchTrafficShareInDepthIncludesDateAndUnitQueryParams(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"labels":[],"datasets":[]}'));

        $this->subject->fetchTrafficShareInDepth('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $uri = (string)$this->httpHistory[0]['request']->getUri();
        self::assertStringContainsString('unit=day', $uri);
        self::assertStringNotContainsString('type=time-series', $uri);
    }

    #[Test]
    public function fetchTrafficShareInDepthSendsEmptyWhereClause(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"labels":[],"datasets":[]}'));

        $this->subject->fetchTrafficShareInDepth('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertSame(['and' => []], $body['where']);
    }

    #[Test]
    public function fetchTrafficShareInDepthReturnsLabelsAndDatasets(): void
    {
        $payload = json_encode([
            'labels' => ['2026-06-01T00:00:00+02:00', '2026-06-02T00:00:00+02:00'],
            'datasets' => [
                ['label' => 'direct', 'data' => [0, 4], 'total' => 4],
                ['label' => 'search', 'data' => [2, 1], 'total' => 3],
            ],
        ]);
        $this->mockHandler->append(new Response(200, [], $payload));

        $result = $this->subject->fetchTrafficShareInDepth('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-02'));

        self::assertCount(2, $result['labels']);
        self::assertCount(2, $result['datasets']);
        self::assertSame('direct', $result['datasets'][0]['label']);
        self::assertSame(4, $result['datasets'][0]['total']);
    }

    #[Test]
    public function fetchTrafficShareInDepthReturnsEmptyArraysOnEmptyResponse(): void
    {
        $this->mockHandler->append(new Response(200, [], '{}'));

        $result = $this->subject->fetchTrafficShareInDepth('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame([], $result['labels']);
        self::assertSame([], $result['datasets']);
    }

    #[Test]
    public function fetchTrafficShareInDepthThrowsOnHttpError(): void
    {
        $this->mockHandler->append(new Response(403, [], '{}'));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->fetchTrafficShareInDepth('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));
    }

    /** fetchDeviceSessions */

    #[Test]
    public function fetchDeviceSessionsSendsPostToCorrectEndpoint(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchDeviceSessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/v2/websites/w-123/analytics/sessions', (string)$request->getUri());
    }

    #[Test]
    public function fetchDeviceSessionsSendsXApiKeyHeader(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchDeviceSessions('w-123', 'twpl-test-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame('twpl-test-key', $this->httpHistory[0]['request']->getHeaderLine('X-Api-Key'));
    }

    #[Test]
    public function fetchDeviceSessionsSendsDateRangeInBody(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchDeviceSessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertArrayHasKey('dateRange', $body);
        self::assertStringContainsString('2026-06-01', $body['dateRange']['start']);
        self::assertStringContainsString('2026-06-08', $body['dateRange']['end']);
    }

    #[Test]
    public function fetchDeviceSessionsSendsDimensionsAndMetricsInBody(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchDeviceSessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertSame(['deviceType'], $body['dimensions']);
        self::assertContains('sessionCount', $body['metrics']);
        self::assertContains('sessionPercentOfTotal', $body['metrics']);
    }

    #[Test]
    public function fetchDeviceSessionsReturnsPayloadRows(): void
    {
        $payload = json_encode(['payload' => [
            ['deviceType' => 'desktop', 'sessionCount' => 5, 'sessionPercentOfTotal' => 100.0],
        ]]);
        $this->mockHandler->append(new Response(200, [], $payload));

        $result = $this->subject->fetchDeviceSessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertCount(1, $result);
        self::assertSame('desktop', $result[0]['deviceType']);
        self::assertSame(5, $result[0]['sessionCount']);
    }

    #[Test]
    public function fetchDeviceSessionsReturnsEmptyArrayOnEmptyPayload(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $result = $this->subject->fetchDeviceSessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame([], $result);
    }

    #[Test]
    public function fetchDeviceSessionsThrowsOnHttpError(): void
    {
        $this->mockHandler->append(new Response(403, [], '{}'));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->fetchDeviceSessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));
    }

    #[Test]
    public function fetchDeviceSessionsIncludesPreviousDateRangeAndMetricWhenProvided(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchDeviceSessions(
            'w-123',
            'api-key',
            $this->date('2026-06-01'),
            $this->date('2026-06-08'),
            $this->date('2026-05-24'),
            $this->date('2026-05-31'),
        );

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertArrayHasKey('previousDateRange', $body);
        self::assertStringContainsString('2026-05-24', $body['previousDateRange']['start']);
        self::assertContains('previousSessionCount', $body['metrics']);
    }

    #[Test]
    public function fetchDeviceSessionsOmitsPreviousDateRangeWhenNotProvided(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchDeviceSessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertArrayNotHasKey('previousDateRange', $body);
        self::assertNotContains('previousSessionCount', $body['metrics']);
    }

    /** fetchBrowserSessions */

    #[Test]
    public function fetchBrowserSessionsSendsPostToCorrectEndpoint(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchBrowserSessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/analytics/sessions?trace=browsers', (string)$request->getUri());
    }

    #[Test]
    public function fetchBrowserSessionsSendsDimensionAndMetrics(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchBrowserSessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertSame(['browserName'], $body['dimensions']);
        self::assertContains('sessionCount', $body['metrics']);
        self::assertContains('sessionPercentOfTotal', $body['metrics']);
    }

    #[Test]
    public function fetchBrowserSessionsIncludesPreviousDateRangeWhenProvided(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchBrowserSessions(
            'w-123',
            'api-key',
            $this->date('2026-06-01'),
            $this->date('2026-06-08'),
            $this->date('2026-05-24'),
            $this->date('2026-05-31'),
        );

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertArrayHasKey('previousDateRange', $body);
        self::assertContains('previousSessionCount', $body['metrics']);
    }

    #[Test]
    public function fetchBrowserSessionsReturnsPayloadRows(): void
    {
        $payload = json_encode(['payload' => [
            ['browserName' => 'Chrome', 'sessionCount' => 3, 'sessionPercentOfTotal' => 100.0],
        ]]);
        $this->mockHandler->append(new Response(200, [], $payload));

        $result = $this->subject->fetchBrowserSessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertCount(1, $result);
        self::assertSame('Chrome', $result[0]['browserName']);
    }

    #[Test]
    public function fetchBrowserSessionsThrowsOnHttpError(): void
    {
        $this->mockHandler->append(new Response(403, [], '{}'));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->fetchBrowserSessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));
    }

    /** fetchCountrySessions */

    #[Test]
    public function fetchCountrySessionsSendsPostToCorrectEndpoint(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchCountrySessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/analytics/sessions?trace=countryCode', (string)$request->getUri());
    }

    #[Test]
    public function fetchCountrySessionsSendsDimensionAndMetrics(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->subject->fetchCountrySessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertSame(['countryCode'], $body['dimensions']);
        self::assertContains('sessionCount', $body['metrics']);
    }

    #[Test]
    public function fetchCountrySessionsReturnsPayloadRows(): void
    {
        $payload = json_encode(['payload' => [
            ['countryCode' => 'DE', 'sessionCount' => 3, 'sessionPercentOfTotal' => 100.0],
        ]]);
        $this->mockHandler->append(new Response(200, [], $payload));

        $result = $this->subject->fetchCountrySessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertCount(1, $result);
        self::assertSame('DE', $result[0]['countryCode']);
    }

    #[Test]
    public function fetchCountrySessionsThrowsOnHttpError(): void
    {
        $this->mockHandler->append(new Response(403, [], '{}'));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->fetchCountrySessions('w-123', 'api-key', $this->date('2026-06-01'), $this->date('2026-06-08'));
    }
}
