<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
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

    /** fetchAllTimeSeries */

    #[Test]
    public function fetchAllTimeSeriesSendsThreeParallelPostRequests(): void
    {
        $empty = '{"payload":{"datasets":[{"data":[]}]}}';
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
        $this->mockHandler->append(new Response(200, [], '{"payload":{"datasets":[{"data":[1,2,3]}]}}'));
        $this->mockHandler->append(new Response(200, [], '{"payload":{"datasets":[{"data":[10,20,30]}]}}'));
        $this->mockHandler->append(new Response(200, [], '{"payload":{"datasets":[{"data":[60,90,120]}]}}'));

        $result = $this->subject->fetchAllTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame([1.0, 2.0, 3.0], $result['visits']);
        self::assertSame([10.0, 20.0, 30.0], $result['bounceRate']);
        self::assertSame([60.0, 90.0, 120.0], $result['avgDuration']);
        self::assertSame([], $result['failures']);
    }

    #[Test]
    public function fetchAllTimeSeriesReturnsEmptyArrayAndRecordsFailureForFailedSeries(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":{"datasets":[{"data":[1,2,3]}]}}'));
        $this->mockHandler->append(new Response(500, [], '{}'));
        $this->mockHandler->append(new Response(200, [], '{"payload":{"datasets":[{"data":[60,90,120]}]}}'));

        $result = $this->subject->fetchAllTimeSeries('w-123', 'api-key', 'https://example.com/', $this->date('2026-06-01'), $this->date('2026-06-08'));

        self::assertSame([1.0, 2.0, 3.0], $result['visits']);
        self::assertSame([], $result['bounceRate']);
        self::assertSame([60.0, 90.0, 120.0], $result['avgDuration']);
        self::assertArrayHasKey('bounceRate', $result['failures']);
    }
}
