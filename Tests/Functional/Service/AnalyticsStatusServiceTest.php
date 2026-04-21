<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use T3G\Analytics\Service\AnalyticsStatusService;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
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

    private FrontendInterface $cache;
    /** @var RequestFactory&MockObject */
    private RequestFactory $requestFactory;
    private CipherService $cipherService;
    private AnalyticsStatusService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = $this->get(CacheManager::class)->getCache('tx_analytics_status');
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->cipherService = new CipherService();

        $this->subject = new AnalyticsStatusService(
            $this->cache,
            $this->requestFactory,
            $this->cipherService,
            new \Psr\Log\NullLogger(),
            $this->createMock(SiteSettingsService::class),
            $this->createMock(SiteSettingsFactory::class),
        );
    }

    #[Test]
    public function getStatusFetchesFromApiOnCacheMissAndStoresResult(): void
    {
        $site = $this->buildRegisteredSite('main', 'w-123', 'i-456');

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturn($this->buildApiResponse('{"status":"active","packageName":"Pro"}'));

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

        // Only one API call despite two getStatus() invocations
        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturn($this->buildApiResponse('{"status":"active"}'));

        $first = $this->subject->getStatus($site);
        $second = $this->subject->getStatus($site);

        self::assertSame($first, $second);
    }

    #[Test]
    public function getStatusWithForceRefreshBypassesCache(): void
    {
        $site = $this->buildRegisteredSite('main', 'w-123', 'i-456');

        // Once for initial fetch, once for force refresh
        $this->requestFactory
            ->expects(self::exactly(2))
            ->method('request')
            ->willReturn($this->buildApiResponse('{"status":"active"}'));

        $this->subject->getStatus($site);
        $this->subject->getStatus($site, forceRefresh: true);
    }

    #[Test]
    public function clearCacheRemovesCachedValue(): void
    {
        $site = $this->buildRegisteredSite('main', 'w-123', 'i-456');

        // Fetch, then re-fetch after clear
        $this->requestFactory
            ->expects(self::exactly(2))
            ->method('request')
            ->willReturn($this->buildApiResponse('{"status":"active"}'));

        // Fills cache
        $this->subject->getStatus($site);
        // Removes from cache
        $this->subject->clearCache($site);
        // Must hit API again
        $this->subject->getStatus($site);
    }

    #[Test]
    public function getStatusReturnsNullWhenMissingCredentials(): void
    {
        $site = $this->buildUnregisteredSite('empty');

        $this->requestFactory->expects(self::never())->method('request');

        self::assertNull($this->subject->getStatus($site));
    }

    #[Test]
    public function getDashboardUrlReturnsUrlFromApiResponse(): void
    {
        $site = $this->buildRegisteredSite('main', 'w-123', 'i-456');
        $expectedUrl = 'https://app-3as.visitor-analytics.io?intpc_token=jwt&externalWebsiteId=w-123';

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('/dashboard-url/w-123'),
                'GET',
                self::callback(static function (array $opts): bool {
                    return isset($opts['headers']['Authorization'])
                        && str_starts_with($opts['headers']['Authorization'], 'HMAC i-456:');
                })
            )
            ->willReturn($this->buildApiResponse(json_encode(['dashboardUrl' => $expectedUrl])));

        self::assertSame($expectedUrl, $this->subject->getDashboardUrl($site));
    }

    #[Test]
    public function getDashboardUrlReturnsNullOnApiFailure(): void
    {
        $site = $this->buildRegisteredSite('main', 'w-123', 'i-456');

        $this->requestFactory
            ->method('request')
            ->willThrowException(new \RuntimeException('network error'));

        self::assertNull($this->subject->getDashboardUrl($site));
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

    private function buildApiResponse(string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        return $response;
    }
}
