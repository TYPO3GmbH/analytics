<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use T3G\Analytics\Service\AnalyticsStatusService;
use T3G\Analytics\Service\CipherService;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AnalyticsStatusServiceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private MockHandler $mockHandler;
    private array $httpHistory = [];
    private SiteSettingsService&MockObject $siteSettingsService;
    private SiteSettingsFactory&MockObject $siteSettingsFactory;

    private VariableFrontend $cache;
    private string $encryptedTestSecret;

    private AnalyticsStatusService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);

        $this->mockHandler = new MockHandler();
        $this->httpHistory = [];
        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->httpHistory));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = ['verify' => false, 'handler' => $stack];

        $this->siteSettingsService = $this->createMock(SiteSettingsService::class);
        $this->siteSettingsFactory = $this->createMock(SiteSettingsFactory::class);
        $this->cache = new VariableFrontend('analytics_status', new TransientMemoryBackend('production'));

        $cipherService = new CipherService();
        $this->encryptedTestSecret = $cipherService->encrypt('plain-secret');

        $this->subject = new AnalyticsStatusService(
            $this->cache,
            new RequestFactory(new GuzzleClientFactory()),
            $cipherService,
            new NullLogger(),
            $this->siteSettingsService,
            $this->siteSettingsFactory,
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']);
        parent::tearDown();
    }

    /** getManagePlanUrl */

    #[Test]
    public function getManagePlanUrlReturnsUrlFromApiResponse(): void
    {
        $site = $this->buildSite('main', 'w-123', 'i-456');
        $this->mockHandler->append(new Response(200, [], '{"checkoutUrl":"https://checkout.visitor-analytics.io/plan?token=jwt"}'));

        self::assertSame('https://checkout.visitor-analytics.io/plan?token=jwt', $this->subject->getManagePlanUrl($site));
    }

    #[Test]
    public function getManagePlanUrlReturnsNullWhenApiResponseHasNoUrl(): void
    {
        $site = $this->buildSite('main', 'w-123', 'i-456');
        $this->mockHandler->append(new Response(200, [], '{}'));

        self::assertNull($this->subject->getManagePlanUrl($site));
    }

    #[Test]
    public function getManagePlanUrlReturnsNullWhenApiCallFails(): void
    {
        $site = $this->buildSite('main', 'w-123', 'i-456');
        $this->mockHandler->append(new \RuntimeException('connection refused'));

        self::assertNull($this->subject->getManagePlanUrl($site));
    }

    #[Test]
    public function getManagePlanUrlReturnsNullWhenSiteHasNoCredentials(): void
    {
        self::assertNull($this->subject->getManagePlanUrl($this->buildSite('main', '', '')));
        self::assertEmpty($this->httpHistory);
    }

    /** getStatus */

    #[Test]
    public function persistsTrackingCodeAndStatusWhenApiResponseContainsThem(): void
    {
        $site = $this->buildSite('main', 'w-123', 'i-456');
        $this->mockHandler->append(new Response(200, [], '{"status":"active","maxPrivacyModeTrackingCode":"tc-abc"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn(['websiteId' => 'w-123']);

        $this->siteSettingsService
            ->expects(self::once())
            ->method('writeSettings')
            ->with(
                $site,
                self::callback(static function (array $settings): bool {
                    return $settings['trackingCode'] === 'tc-abc'
                        && $settings['status'] === 'active'
                        && $settings['websiteId'] === 'w-123';
                })
            );

        $this->subject->getStatus($site, forceRefresh: true);
    }

    #[Test]
    public function skipsWriteSettingsWhenValuesUnchanged(): void
    {
        $site = $this->buildSite('main', 'w-123', 'i-456', ['trackingCode' => 'tc-abc', 'status' => 'active']);
        $this->mockHandler->append(new Response(200, [], '{"status":"active","maxPrivacyModeTrackingCode":"tc-abc"}'));

        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $this->subject->getStatus($site, forceRefresh: true);
    }

    #[Test]
    public function skipsWriteSettingsWhenApiResponseHasNoTrackingIdOrStatus(): void
    {
        $site = $this->buildSite('main', 'w-123', 'i-456');
        $this->mockHandler->append(new Response(200, [], '{"packageName":"Pro"}'));

        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $this->subject->getStatus($site, forceRefresh: true);
    }

    #[Test]
    public function skipsApiCallOnCacheHit(): void
    {
        $site = $this->buildSite('main', 'w-123', 'i-456');
        $this->cache->set('status_' . sha1('main'), ['status' => 'active', '_fetchedAt' => time()], [], 86400);

        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $this->subject->getStatus($site);

        self::assertEmpty($this->httpHistory);
    }

    /** Helpers */

    private function buildSite(string $identifier, string $websiteId, string $instanceId, array $extraSettings = []): Site
    {
        $instanceSecret = ($websiteId !== '' && $instanceId !== '') ? $this->encryptedTestSecret : '';
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getBase')->willReturn(new Uri('https://example.com'));
        $site->method('getSettings')->willReturn(new SiteSettings(
            new Settings(array_merge(['websiteId' => $websiteId, 'instanceId' => $instanceId, 'instanceSecret' => $instanceSecret], $extraSettings)),
            [],
            []
        ));
        return $site;
    }
}
