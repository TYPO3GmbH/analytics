<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Controller;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Controller\PagePerformanceAjaxController;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Tests\Fixtures\AnalyticsIds;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use T3G\Analytics\Tests\Functional\Fixtures\WidgetAccessUsers;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Runs the page performance bar through its AJAX route with the real service chain from the DI container:
 * PagePerformanceAjaxController, PagePerformanceDataProvider, AnalyticsDataClient, the cache and the
 * Fluid templates of PagePerformanceBarRenderer. Only the HTTP transport to the analytics API is replaced.
 *
 * The site is a real site configuration with page 1 as root page (Fixtures/site_performance.csv).
 */
final class PagePerformanceAjaxControllerTest extends FunctionalTestCase
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
        $this->setUpBackendUser(WidgetAccessUsers::ADMIN);

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
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function barRendersTheMetricsFromTheApi(): void
    {
        $this->appendPagePerformanceResponses();

        $xpath = $this->parse($this->callBar());

        self::assertSame(
            ['30', '40.00%', '1:20', '60.00%'],
            $this->texts($xpath, '//span[@class="tx-analytics-performance-value"]'),
        );
        self::assertCount(5, $this->httpHistory);
        foreach ($this->httpHistory as $entry) {
            self::assertStringContainsString('/v2/websites/' . AnalyticsIds::EXTERNAL_WEBSITE_ID . '/', (string)$entry['request']->getUri());
            self::assertSame(self::API_KEY, $entry['request']->getHeaderLine('X-Api-Key'));
        }
    }

    #[Test]
    public function barNamesPercentagePointsInTheTrendContextOfTheRates(): void
    {
        $this->appendPagePerformanceResponses();

        $xpath = $this->parse($this->callBar());

        self::assertSame(
            [
                'Compared to previous period',
                'Percentage points compared to previous period',
                'Compared to previous period',
                'Percentage points compared to previous period',
            ],
            $this->texts($xpath, '//span[contains(@class, "tx-analytics-performance-trend ")]/span[@class="visually-hidden"]'),
        );
    }

    #[Test]
    public function barLabelsTheChartsForScreenReaders(): void
    {
        $this->appendPagePerformanceResponses();

        $xpath = $this->parse($this->callBar());

        $labels = array_map(
            static fn (\DOMElement $svg): string => $svg->getAttribute('aria-label'),
            iterator_to_array($xpath->query('//*[@id="tx-analytics-performance-details-1"]//*[local-name()="svg"]')),
        );
        self::assertSame(
            [
                'Trend chart: Views',
                'Trend chart: Bounce rate',
                'Trend chart: Avg. time on page',
                'Trend chart: Continuation rate',
            ],
            array_values($labels),
        );
    }

    private function callBar(): string
    {
        $request = (new ServerRequest(new Uri('https://example.com/typo3/ajax/analytics/page-performance'), 'GET'))
            ->withQueryParams(['pageId' => '1', 'days' => '7'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $response = $this->get(PagePerformanceAjaxController::class)->handle($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array{html: string} $body */
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return $body['html'];
    }

    /**
     * Queues the five API responses in the order PagePerformanceDataProvider sends the requests:
     * the page in the current and in the previous period, then the daily visits, bounce rate and time on page.
     */
    private function appendPagePerformanceResponses(): void
    {
        $dates = ['2026-06-01', '2026-06-02'];
        $this->mockHandler->append(
            $this->jsonResponse(['payload' => [['pageUrl' => 'https://main.example.com', 'visitCount' => 30, 'bounceRate' => 40, 'averageVisitDuration' => 80]]]),
            $this->jsonResponse(['payload' => [['pageUrl' => 'https://main.example.com', 'visitCount' => 12, 'bounceRate' => 50, 'averageVisitDuration' => 64]]]),
            $this->graphResponse($dates, [12, 18]),
            $this->graphResponse($dates, [50, 30]),
            $this->graphResponse($dates, [65, 95]),
        );
    }

    /**
     * @param list<string> $dates
     * @param list<int|float> $values
     */
    private function graphResponse(array $dates, array $values): Response
    {
        return $this->jsonResponse(['payload' => [
            'labels' => array_map(static fn (string $date): string => $date . 'T00:00:00Z', $dates),
            'datasets' => [['label' => 'Series', 'data' => $values]],
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
