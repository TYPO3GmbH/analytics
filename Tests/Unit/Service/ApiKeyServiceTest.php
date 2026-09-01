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
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Service\AnalyticsApiClient;
use T3G\Analytics\Service\ApiExceptionExtractor;
use T3G\Analytics\Service\ApiKeyService;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\HmacSigner;
use T3G\Analytics\Service\SiteSettingsWriteVerifierInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ApiKeyServiceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private MockHandler $mockHandler;
    private array $httpHistory = [];
    private SiteSettingsService&MockObject $siteSettingsService;
    private SiteSettingsFactory&MockObject $siteSettingsFactory;
    private SiteSettingsWriteVerifierInterface&MockObject $writeGuard;
    private CipherService $cipherService;
    private ApiKeyService $subject;

    private string $encryptedSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);

        $this->mockHandler = new MockHandler();
        $this->httpHistory = [];
        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->httpHistory));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = ['verify' => false, 'handler' => $stack];

        $this->cipherService = new CipherService();
        $this->encryptedSecret = $this->cipherService->encrypt('plain-secret');

        $this->siteSettingsService = $this->createMock(SiteSettingsService::class);
        $this->siteSettingsFactory = $this->createMock(SiteSettingsFactory::class);
        $this->writeGuard = $this->createMock(SiteSettingsWriteVerifierInterface::class);

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['verifySsl'] = '0';
        $apiClient = new AnalyticsApiClient(
            new RequestFactory(new GuzzleClientFactory()),
            new ApiConfiguration(),
            new HmacSigner(),
            new ApiExceptionExtractor(),
        );

        $this->subject = new ApiKeyService(
            $apiClient,
            $this->cipherService,
            $this->siteSettingsService,
            $this->siteSettingsFactory,
            $this->writeGuard,
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']);
        parent::tearDown();
    }

    #[Test]
    public function provisionIfNeededDoesNothingWhenStatusIsNotActive(): void
    {
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $site = $this->buildSite('w-123', 'i-456', $this->encryptedSecret);
        $this->subject->provisionIfNeeded($site, ['status' => 'pending']);

        self::assertCount(0, $this->httpHistory);
    }

    #[Test]
    public function provisionIfNeededDoesNothingWhenWebsiteIdIsEmpty(): void
    {
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $site = $this->buildSite('', 'i-456', $this->encryptedSecret);
        $this->subject->provisionIfNeeded($site, ['status' => 'active']);

        self::assertCount(0, $this->httpHistory);
    }

    #[Test]
    public function provisionIfNeededDoesNothingWhenInstanceIdIsEmpty(): void
    {
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $site = $this->buildSite('w-123', '', $this->encryptedSecret);
        $this->subject->provisionIfNeeded($site, ['status' => 'active']);

        self::assertCount(0, $this->httpHistory);
    }

    #[Test]
    public function provisionIfNeededDoesNothingWhenInstanceSecretIsEmpty(): void
    {
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $site = $this->buildSite('w-123', 'i-456', '');
        $this->subject->provisionIfNeeded($site, ['status' => 'active']);

        self::assertCount(0, $this->httpHistory);
    }

    #[Test]
    public function provisionIfNeededDoesNothingWhenApiKeyIdAlreadySet(): void
    {
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $site = $this->buildSite('w-123', 'i-456', $this->encryptedSecret, apiKeyId: 'existing-key-id');
        $this->subject->provisionIfNeeded($site, ['status' => 'active']);

        self::assertCount(0, $this->httpHistory);
    }

    #[Test]
    public function provisionIfNeededCallsApiAndStoresEncryptedKey(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"apiKeyId":"new-key-uuid","apiKey":"new-api-key"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn(['existingKey' => 'keep-me']);

        $cipherService = $this->cipherService;
        $this->siteSettingsService
            ->expects(self::once())
            ->method('writeSettings')
            ->with(
                self::anything(),
                self::callback(static function (array $settings) use ($cipherService): bool {
                    return $settings['apiKeyId'] === 'new-key-uuid'
                        && $settings['apiKey'] !== ''
                        && $cipherService->decrypt($settings['apiKey']) === 'new-api-key'
                        && $settings['existingKey'] === 'keep-me';
                })
            );

        $site = $this->buildSite('w-123', 'i-456', $this->encryptedSecret);
        $this->subject->provisionIfNeeded($site, ['status' => 'active']);
    }

    #[Test]
    public function provisionIfNeededSendsPostToCorrectEndpointWithHmacHeaders(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"apiKeyId":"k","apiKey":"v"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);

        $site = $this->buildSite('w-123', 'i-456', $this->encryptedSecret);
        $this->subject->provisionIfNeeded($site, ['status' => 'active']);

        self::assertCount(1, $this->httpHistory);
        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/api-keys/w-123', (string)$request->getUri());
        self::assertStringStartsWith('HMAC i-456:', $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function provisionIfNeededDoesNotWriteWhenApiCallFails(): void
    {
        $this->mockHandler->append(new \RuntimeException('connection refused'));
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $site = $this->buildSite('w-123', 'i-456', $this->encryptedSecret);
        $this->subject->provisionIfNeeded($site, ['status' => 'active']);
    }

    #[Test]
    public function provisionIfNeededDoesNotWriteWhenResponseIsIncomplete(): void
    {
        $this->mockHandler->append(new Response(200, [], '{}'));
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $site = $this->buildSite('w-123', 'i-456', $this->encryptedSecret);
        $this->subject->provisionIfNeeded($site, ['status' => 'active']);
    }

    #[Test]
    public function provisionIfNeededReturnsWhenWriteSettingsThrows(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"apiKeyId":"new-key-uuid","apiKey":"new-api-key"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);
        $this->siteSettingsService
            ->method('writeSettings')
            ->willThrowException(new \TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException('disk full', 1590487411));

        $site = $this->buildSite('w-123', 'i-456', $this->encryptedSecret);
        $this->subject->provisionIfNeeded($site, ['status' => 'active']);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function provisionIfNeededLogsErrorWhenApiKeyCouldNotBePersisted(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"apiKeyId":"new-key-uuid","apiKey":"new-api-key"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);
        $this->writeGuard
            ->method('assertSettingsPersisted')
            ->willThrowException(new AnalyticsApiException('Credentials could not be written.', 0));

        $site = $this->buildSite('w-123', 'i-456', $this->encryptedSecret);
        $this->subject->provisionIfNeeded($site, ['status' => 'active']);

        // No exception thrown — the service returns silently and logs an error.
        $this->expectNotToPerformAssertions();
    }

    /** Helpers */

    private function buildSite(
        string $websiteId,
        string $instanceId,
        string $instanceSecret,
        string $apiKeyId = '',
    ): Site {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn('main');
        $site->method('getSettings')->willReturn(new SiteSettings(
            new Settings([
                'websiteId' => $websiteId,
                'instanceId' => $instanceId,
                'instanceSecret' => $instanceSecret,
                'apiKeyId' => $apiKeyId,
            ]),
            [],
            []
        ));
        return $site;
    }
}
