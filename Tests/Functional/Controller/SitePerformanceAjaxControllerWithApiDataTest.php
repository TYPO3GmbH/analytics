<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Controller;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Controller\SitePerformanceAjaxController;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Tests\Fixtures\AnalyticsIds;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use T3G\Analytics\Tests\Functional\Fixtures\WidgetAccessUsers;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Runs the site performance widget through its AJAX route with the real service chain from the DI container:
 * SitePerformanceAjaxController, SitePerformanceService, AnalyticsSiteProvider, AnalyticsDataClient, the cache
 * and the Fluid partial with its view helpers. Only the HTTP transport to the analytics API is replaced.
 *
 * The site is a real site configuration with page 1 as root page (Fixtures/site_performance.csv).
 */
final class SitePerformanceAjaxControllerWithApiDataTest extends FunctionalTestCase
{
    private const API_KEY = 'test-analytics-api-key';

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        ],
    ];

    private MockHandler $mockHandler;
    /** @var array<int, array<string, mixed>> */
    private array $httpHistory = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockHandler = new MockHandler();
        $this->httpHistory = [];
        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->httpHistory));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = $stack;

        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');

        $this->importCSVDataSet(WidgetAccessUsers::CSV);
        $this->importCSVDataSet(__DIR__ . '/Fixtures/site_performance.csv');

        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write('main', [
            'rootPageId' => 1,
            'base' => 'https://main.example.com/',
            'dependencies' => ['t3g/analytics'],
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
            ],
        ]);
        $siteWriter->writeSettings('main', [
            'websiteId' => AnalyticsIds::WEBSITE_ID,
            'externalWebsiteId' => AnalyticsIds::EXTERNAL_WEBSITE_ID,
            'apiKey' => (new CipherService())->encrypt(self::API_KEY),
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']);
        parent::tearDown();
    }

    #[Test]
    public function widgetRendersTheMetricsFromTheApi(): void
    {
        $this->setUpBackendUser(WidgetAccessUsers::ADMIN);
        $this->appendSitePerformanceResponses();

        $body = $this->callWidget();

        self::assertSame('ok', $body['status']);
        $xpath = $this->parse($body['html']);
        self::assertSame(
            ['30', '23', '41.50%', '1:20'],
            $this->texts($xpath, '//strong[contains(@class, "tx-analytics-site-performance-value")]'),
        );
        // Trends against the previous period: 30 - 12 visits, 23 - 9 visitors.
        self::assertStringContainsString('+18', $this->texts($xpath, '//li[1]//span[contains(@class, "tx-analytics-site-performance-trend")]')[0]);
        self::assertStringContainsString('+14', $this->texts($xpath, '//li[2]//span[contains(@class, "tx-analytics-site-performance-trend")]')[0]);
        // Today, yesterday and peak of the daily visits.
        self::assertSame(['18', '12', '18'], $this->texts($xpath, '//li[1]//dl[contains(@class, "tx-analytics-site-performance-details-data")]//dd'));
        self::assertSame(4, $xpath->query('//div[contains(@class, "tx-analytics-site-performance-sparkline")]/svg')->length);
        self::assertStringContainsString('main', $body['showAllUrl']);
    }

    #[Test]
    public function widgetRequestsTheDataOfTheSiteWithItsDecryptedApiKey(): void
    {
        $this->setUpBackendUser(WidgetAccessUsers::ADMIN);
        $this->appendSitePerformanceResponses();

        $this->callWidget();

        self::assertCount(5, $this->httpHistory);
        foreach ($this->httpHistory as $entry) {
            self::assertStringContainsString('/v2/websites/' . AnalyticsIds::EXTERNAL_WEBSITE_ID . '/', (string)$entry['request']->getUri());
            self::assertSame(self::API_KEY, $entry['request']->getHeaderLine('X-Api-Key'));
        }
    }

    #[Test]
    public function widgetUsesTheCacheUntilThePageCachesAreFlushed(): void
    {
        $this->setUpBackendUser(WidgetAccessUsers::ADMIN);
        $this->appendSitePerformanceResponses();

        $first = $this->callWidget();
        $second = $this->callWidget();

        self::assertCount(5, $this->httpHistory, 'The second call must use the cache');
        self::assertSame($first['html'], $second['html']);

        $this->get(CacheManager::class)->flushCachesInGroup('pages');
        $this->appendSitePerformanceResponses();
        $this->callWidget();

        self::assertCount(10, $this->httpHistory, 'Flushing the page caches must also flush the site performance cache');
    }

    #[Test]
    public function widgetShowsNoDataForASiteOutsideThePageMountsOfTheUser(): void
    {
        // The user may use the widget, but has no page mount on the root page of the site.
        $this->setUpBackendUser(WidgetAccessUsers::V13_WIDGETS);
        $this->appendSitePerformanceResponses();

        $body = $this->callWidget();

        self::assertSame('ok', $body['status']);
        self::assertSame(0, $this->parse($body['html'])->query('//strong[contains(@class, "tx-analytics-site-performance-value")]')->length);
        self::assertCount(0, $this->httpHistory, 'No API request must be made for an inaccessible site');
    }

    /**
     * @return array<string, mixed>
     */
    private function callWidget(): array
    {
        $request = (new ServerRequest(new Uri('https://example.com/ajax'), 'GET'))
            ->withQueryParams(['site' => 'main', 'days' => '7'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $response = $this->get(SitePerformanceAjaxController::class)->handle($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return $body;
    }

    /**
     * Queues the five API responses in the order AnalyticsDataClient::fetchSitePerformance() sends the requests:
     * totals of the current and the previous period, daily sessions, time on page of the current and the previous period.
     */
    private function appendSitePerformanceResponses(): void
    {
        $this->mockHandler->append(
            $this->jsonResponse(['payload' => [['visitCount' => 30, 'visitorCount' => 23, 'bounceRate' => 41.5]]]),
            $this->jsonResponse(['payload' => [['visitCount' => 12, 'visitorCount' => 9, 'bounceRate' => 50]]]),
            $this->jsonResponse(['payload' => [
                ['date' => '2026-06-01T00:00:00Z', 'visitCount' => 12, 'visitorCount' => 10, 'bounceRate' => 41.5],
                ['date' => '2026-06-02T00:00:00Z', 'visitCount' => 18, 'visitorCount' => 13, 'bounceRate' => 0],
            ]]),
            $this->timeOnPageResponse(['2026-06-01', '2026-06-02'], [65, 95], 80),
            $this->timeOnPageResponse([], [], 64),
        );
    }

    /**
     * @param list<string> $dates
     * @param list<int|float> $values
     */
    private function timeOnPageResponse(array $dates, array $values, float $average): Response
    {
        return $this->jsonResponse(['payload' => [
            'labels' => array_map(static fn (string $date): string => $date . 'T00:00:00Z', $dates),
            'datasets' => [['label' => 'Average Page View Duration', 'data' => $values, 'totalAverage' => $average]],
        ]]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data): Response
    {
        return new Response(200, [], json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function parse(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new \DOMXPath($document);
    }

    /**
     * @return list<string>
     */
    private function texts(\DOMXPath $xpath, string $query): array
    {
        return array_values(array_map(
            static fn (\DOMNode $node): string => trim(preg_replace('/\s+/', ' ', $node->textContent) ?? ''),
            iterator_to_array($xpath->query($query)),
        ));
    }
}
