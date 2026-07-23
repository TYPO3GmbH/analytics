<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Service\AnalyticsApiClient;
use T3G\Analytics\Service\ApiExceptionExtractor;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\HmacSigner;
use T3G\Analytics\Service\InstanceRegistrationService;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;

/**
 * Functional tests for InstanceRegistrationService.
 *
 * Uses the real CipherService (requiring the TYPO3 encryption key) to verify
 * that credentials are properly encrypted before being written to site settings.
 */
final class InstanceRegistrationServiceTest extends FunctionalTestCase
{
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        ],
    ];

    private MockHandler $mockHandler;
    private array $httpHistory = [];
    private SiteSettingsService&MockObject $siteSettingsService;
    private SiteSettingsFactory&MockObject $siteSettingsFactory;
    private CipherService $cipherService;
    private InstanceRegistrationService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockHandler = new MockHandler();
        $this->httpHistory = [];
        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->httpHistory));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = $stack;

        $this->siteSettingsService = $this->createMock(SiteSettingsService::class);
        $this->siteSettingsFactory = $this->createMock(SiteSettingsFactory::class);
        $this->cipherService = new CipherService();

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['verifySsl'] = '0';
        $apiClient = new AnalyticsApiClient(
            new RequestFactory(new GuzzleClientFactory()),
            new ApiConfiguration(),
            new HmacSigner(),
            new ApiExceptionExtractor(),
        );

        $this->subject = new InstanceRegistrationService(
            $apiClient,
            $this->cipherService,
            $this->siteSettingsService,
            $this->siteSettingsFactory,
            new \Psr\Log\NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']);
        parent::tearDown();
    }

    #[Test]
    public function registerWritesEncryptedCredentialsToSiteSettings(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"plain-secret"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);

        $writtenSettings = [];
        $this->siteSettingsService
            ->expects(self::once())
            ->method('writeSettings')
            ->willReturnCallback(function (Site $site, array $settings) use (&$writtenSettings): void {
                $writtenSettings = $settings;
            });

        $this->subject->register($this->buildSite(), 'user@example.com');

        self::assertSame('w-123', $writtenSettings['websiteId']);
        self::assertSame('i-456', $writtenSettings['instanceId']);
        // Secret must be stored encrypted, not as plain text
        self::assertNotSame('plain-secret', $writtenSettings['instanceSecret']);
        // But must be decryptable back to the original value
        self::assertSame('plain-secret', $this->cipherService->decrypt($writtenSettings['instanceSecret']));
    }

    #[Test]
    public function registerMergesWithExistingSettings(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"secret"}'));
        $this->siteSettingsFactory
            ->method('loadLocalSettings')
            ->willReturn(['existingKey' => 'existingValue']);

        $writtenSettings = [];
        $this->siteSettingsService
            ->method('writeSettings')
            ->willReturnCallback(function (Site $site, array $settings) use (&$writtenSettings): void {
                $writtenSettings = $settings;
            });

        $this->subject->register($this->buildSite(), 'user@example.com');

        self::assertSame('existingValue', $writtenSettings['existingKey']);
        self::assertSame('w-123', $writtenSettings['websiteId']);
    }

    #[Test]
    public function registerThrowsWhenApiCallFails(): void
    {
        $this->mockHandler->append(new \RuntimeException('connection refused'));

        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $this->expectException(\RuntimeException::class);
        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    #[Test]
    public function registerThrowsWhenApiResponseIsMissingWebsiteId(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"instanceId":"i-456","instanceSecret":"secret"}'));

        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $this->expectException(\RuntimeException::class);
        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    #[Test]
    public function registerCallsApiWithCorrectPayload(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"secret"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);
        $this->siteSettingsService->method('writeSettings');

        $this->subject->register($this->buildSite(), 'user@example.com');

        self::assertCount(1, $this->httpHistory);
        self::assertStringContainsString('/auth/register/instance', (string)$this->httpHistory[0]['request']->getUri());
        self::assertSame('POST', $this->httpHistory[0]['request']->getMethod());
        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertNotEmpty($body['intpId'] ?? '');
        self::assertSame('https://example.com', $body['domain']);
        self::assertSame('user@example.com', $body['email']);
    }

    /** Helpers */

    private function buildSite(): Site
    {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn('main');
        $site->method('getBase')->willReturn(new Uri('https://example.com'));
        $site->method('getSettings')->willReturn(new SiteSettings(new Settings([]), [], []));
        return $site;
    }
}
