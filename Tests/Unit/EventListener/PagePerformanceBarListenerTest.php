<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\EventListener;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\EventListener\PagePerformanceBarListener;
use T3G\Analytics\Service\AnalyticsDataClient;
use T3G\Analytics\Service\ApiExceptionExtractor;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\View\SparklineRenderer;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PagePerformanceBarListenerTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private MockHandler $mockHandler;
    private FrontendInterface&MockObject $cache;
    private string $encryptedApiKey;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);
        $this->mockHandler = new MockHandler();
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = ['verify' => false, 'handler' => HandlerStack::create($this->mockHandler)];
        $this->cache = $this->createMock(FrontendInterface::class);
        $this->encryptedApiKey = (new CipherService())->encrypt('test-api-key');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP'], $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
        parent::tearDown();
    }

    private function buildAnalyticsClient(): AnalyticsDataClient
    {
        $extConf = $this->createMock(ExtensionConfiguration::class);
        $extConf->method('get')->willReturn('');

        return new AnalyticsDataClient(
            new RequestFactory(new GuzzleClientFactory()),
            new ApiExceptionExtractor(),
            new ApiConfiguration($extConf),
            new GuzzleClientFactory(),
        );
    }

    private function buildSubject(SiteFinder $siteFinder): PagePerformanceBarListener
    {
        return new PagePerformanceBarListener(
            $this->createMock(PageRenderer::class),
            $this->createMock(AssetCollector::class),
            new SparklineRenderer(),
            $siteFinder,
            $this->createMock(UriBuilder::class),
            $this->buildAnalyticsClient(),
            new CipherService(),
            $this->createMock(LoggerInterface::class),
            $this->cache,
        );
    }

    private function buildSite(): Site
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('generateUri')->willReturn(new Uri('https://example.com/page'));

        $site = $this->createMock(Site::class);
        $site->method('getSettings')->willReturn(new SiteSettings(new Settings([
            'externalWebsiteId' => 'test-website-id',
            'apiKey' => $this->encryptedApiKey,
        ]), [], []));
        $site->method('getRouter')->willReturn($router);
        $site->method('getIdentifier')->willReturn('test-site');

        return $site;
    }

    private function callLoadPageData(PagePerformanceBarListener $subject, int $pageId = 1, int $days = 7): mixed
    {
        $site = (new \ReflectionMethod($subject, 'trySite'))->invoke($subject, $pageId);
        return (new \ReflectionMethod($subject, 'loadPageData'))->invoke($subject, $pageId, $site, null, $days);
    }

    private function siteFinder(): SiteFinder
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($this->buildSite());
        return $siteFinder;
    }

    #[Test]
    public function loadPageDataDoesNotCacheOnApiError(): void
    {
        $this->mockHandler->append(new Response(401, [], '{"error":"AUTHENTICATION_INVALID_API_KEY"}'));

        $this->cache->method('get')->willReturn(false);
        $this->cache->expects(self::never())->method('set');

        self::assertNull($this->callLoadPageData($this->buildSubject($this->siteFinder())));
    }

    #[Test]
    public function loadPageDataCachesNullWhenPageHasNoData(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"payload":[]}'));

        $this->cache->method('get')->willReturn(false);
        $this->cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('page_'),
            null,
        );

        self::assertNull($this->callLoadPageData($this->buildSubject($this->siteFinder())));
    }

    #[Test]
    public function loadPageDataReturnsCachedNullWithoutApiCall(): void
    {
        $this->cache->method('get')->willReturn(null);

        self::assertNull($this->callLoadPageData($this->buildSubject($this->siteFinder())));
        self::assertCount(0, $this->mockHandler);
    }

    #[Test]
    public function loadPageDataCachesAndReturnsDataOnSuccess(): void
    {
        $pageRow = ['visitCount' => 42, 'bounceRate' => 0.5, 'averageVisitDuration' => 120];
        $emptySeries = '{"payload":{"datasets":[{"data":[]}]}}';
        $this->mockHandler->append(
            new Response(200, [], json_encode(['payload' => [$pageRow]])),
            new Response(200, [], $emptySeries),
            new Response(200, [], $emptySeries),
            new Response(200, [], $emptySeries),
        );

        $this->cache->method('get')->willReturn(false);
        $this->cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('page_'),
            self::isArray(),
        );

        $result = $this->callLoadPageData($this->buildSubject($this->siteFinder()));

        self::assertIsArray($result);
        self::assertSame($pageRow, $result['page']);
    }
}
