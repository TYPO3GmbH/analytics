<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Service\AnalyticsApiClient;
use T3G\Analytics\Service\AnalyticsStatusService;
use T3G\Analytics\Service\ApiExceptionExtractor;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\HmacSigner;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;

/**
 * Tests caching behaviour of AnalyticsStatusService using the real
 * TYPO3 database cache backend (tx_analytics_status).
 */
final class AnalyticsStatusServiceTest extends FunctionalTestCase
{
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        ],
    ];

    private MockHandler $mockHandler;
    private array $httpHistory = [];
    private FrontendInterface $cache;
    private CipherService $cipherService;
    private AnalyticsStatusService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockHandler = new MockHandler();
        $this->httpHistory = [];
        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->httpHistory));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = $stack;

        $this->cache = $this->get(CacheManager::class)->getCache('tx_analytics_status');
        $this->cipherService = new CipherService();

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['verifySsl'] = '0';
        $apiClient = new AnalyticsApiClient(
            new RequestFactory(new GuzzleClientFactory()),
            new ApiConfiguration(),
            new HmacSigner(),
            new ApiExceptionExtractor(),
        );

        $this->subject = new AnalyticsStatusService(
            $this->cache,
            $apiClient,
            $this->cipherService,
            new \Psr\Log\NullLogger(),
            $this->createMock(SiteSettingsService::class),
            $this->createMock(SiteSettingsFactory::class),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']);
        parent::tearDown();
    }

    #[Test]
    public function getStatusFetchesFromApiOnCacheMissAndStoresResult(): void
    {
        $site = $this->buildRegisteredSite('main', 'w-123', 'i-456');
        $this->mockHandler->append(new Response(200, [], '{"status":"active","packageName":"Pro"}'));

        $status = $this->subject->getStatus($site);

        self::assertIsArray($status);
        self::assertSame('active', $status['status']);
        self::assertSame('Pro', $status['packageName']);
        self::assertArrayHasKey('_fetchedAt', $status);
    }

    #[Test]
    public function getStatusReturnsCachedValueWithoutApiCall(): void
    {
        $site = $this->buildRegisteredSite('main', 'w-123', 'i-456');
        $this->mockHandler->append(new Response(200, [], '{"status":"active"}'));

        $first = $this->subject->getStatus($site);
        $second = $this->subject->getStatus($site);

        self::assertSame($first, $second);
        self::assertCount(1, $this->httpHistory);
    }

    #[Test]
    public function getStatusWithForceRefreshBypassesCache(): void
    {
        $site = $this->buildRegisteredSite('main', 'w-123', 'i-456');
        $this->mockHandler->append(new Response(200, [], '{"status":"active"}'));
        $this->mockHandler->append(new Response(200, [], '{"status":"active"}'));

        $this->subject->getStatus($site);
        $this->subject->getStatus($site, forceRefresh: true);

        self::assertCount(2, $this->httpHistory);
    }

    #[Test]
    public function clearCacheRemovesCachedValue(): void
    {
        $site = $this->buildRegisteredSite('main', 'w-123', 'i-456');
        $this->mockHandler->append(new Response(200, [], '{"status":"active"}'));
        $this->mockHandler->append(new Response(200, [], '{"status":"active"}'));

        $this->subject->getStatus($site);
        $this->subject->clearCache($site);
        $this->subject->getStatus($site);

        self::assertCount(2, $this->httpHistory);
    }

    #[Test]
    public function getStatusReturnsNullWhenMissingCredentials(): void
    {
        $site = $this->buildUnregisteredSite('empty');

        self::assertNull($this->subject->getStatus($site));
        self::assertEmpty($this->httpHistory);
    }

    #[Test]
    public function getDashboardUrlReturnsUrlFromApiResponse(): void
    {
        $site = $this->buildRegisteredSite('main', 'w-123', 'i-456');
        $expectedUrl = 'https://app-3as.visitor-analytics.io?intpc_token=jwt&externalWebsiteId=w-123';
        $this->mockHandler->append(new Response(200, [], json_encode(['dashboardUrl' => $expectedUrl])));

        $result = $this->subject->getDashboardUrl($site);

        self::assertSame($expectedUrl, $result);
        self::assertCount(1, $this->httpHistory);
        self::assertStringContainsString('/dashboard-url/w-123', (string)$this->httpHistory[0]['request']->getUri());
        self::assertSame('GET', $this->httpHistory[0]['request']->getMethod());
        self::assertStringStartsWith('HMAC i-456:', $this->httpHistory[0]['request']->getHeaderLine('Authorization'));
    }

    #[Test]
    public function getDashboardUrlReturnsNullOnApiFailure(): void
    {
        $site = $this->buildRegisteredSite('main', 'w-123', 'i-456');
        $this->mockHandler->append(new \RuntimeException('network error'));

        self::assertNull($this->subject->getDashboardUrl($site));
    }

    #[Test]
    public function getManagePlanUrlReturnsUrlFromApiResponse(): void
    {
        $site = $this->buildRegisteredSite('main', 'w-123', 'i-456');
        $expectedUrl = 'https://checkout.visitor-analytics.io/plan?token=abc123';
        $this->mockHandler->append(new Response(200, [], json_encode(['checkoutUrl' => $expectedUrl])));

        $result = $this->subject->getManagePlanUrl($site);

        self::assertSame($expectedUrl, $result);
        self::assertCount(1, $this->httpHistory);
        self::assertStringContainsString('/checkout-url/w-123', (string)$this->httpHistory[0]['request']->getUri());
        self::assertSame('GET', $this->httpHistory[0]['request']->getMethod());
        self::assertStringStartsWith('HMAC i-456:', $this->httpHistory[0]['request']->getHeaderLine('Authorization'));
    }

    #[Test]
    public function getManagePlanUrlReturnsNullOnApiFailure(): void
    {
        $site = $this->buildRegisteredSite('main', 'w-123', 'i-456');
        $this->mockHandler->append(new \RuntimeException('network error'));

        self::assertNull($this->subject->getManagePlanUrl($site));
    }

    #[Test]
    public function getManagePlanUrlReturnsNullWhenMissingCredentials(): void
    {
        $site = $this->buildUnregisteredSite('empty');

        self::assertNull($this->subject->getManagePlanUrl($site));
        self::assertEmpty($this->httpHistory);
    }

    /** Helpers */

    private function buildRegisteredSite(string $identifier, string $websiteId, string $instanceId): Site
    {
        $instanceSecret = 'plain-secret-for-test';
        $encryptedSecret = $this->cipherService->encrypt($instanceSecret);

        $siteSettings = new SiteSettings(
            new Settings([]),
            [
                'websiteId' => $websiteId,
                'instanceId' => $instanceId,
                'instanceSecret' => $encryptedSecret,
            ],
            []
        );

        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getSettings')->willReturn($siteSettings);
        return $site;
    }

    private function buildUnregisteredSite(string $identifier): Site
    {
        $siteSettings = new SiteSettings(new Settings([]), [], []);

        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getSettings')->willReturn($siteSettings);
        return $site;
    }
}
