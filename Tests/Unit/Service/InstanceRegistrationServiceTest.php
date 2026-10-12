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
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\HmacSigner;
use T3G\Analytics\Service\InstanceRegistrationService;
use T3G\Analytics\Service\SiteSettingsWriteVerifierInterface;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class InstanceRegistrationServiceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private MockHandler $mockHandler;
    private array $httpHistory = [];
    private SiteSettingsService&MockObject $siteSettingsService;
    private SiteSettingsFactory&MockObject $siteSettingsFactory;
    private SiteSettingsWriteVerifierInterface&MockObject $writeGuard;

    private CipherService $cipherService;
    private InstanceRegistrationService $subject;

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
        $this->writeGuard = $this->createMock(SiteSettingsWriteVerifierInterface::class);
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
    public function registerThrowsWhenApiCallFails(): void
    {
        $this->mockHandler->append(new \RuntimeException('connection refused'));

        $this->expectException(AnalyticsApiException::class);
        $this->expectExceptionMessage('connection refused');

        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    #[Test]
    public function registerThrowsWhenApiResponseMissesWebsiteId(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"instanceId":"i-456","instanceSecret":"s3cr3t"}'));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    #[Test]
    public function registerThrowsWhenApiResponseMissesInstanceId(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"websiteId":"w-123","instanceSecret":"s3cr3t"}'));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    #[Test]
    public function registerWritesEncryptedSecretAndMergesExistingSettings(): void
    {
        $site = $this->buildSite();
        $this->mockHandler->append(new Response(200, [], '{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"s3cr3t"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn(['existingKey' => 'val']);

        $cipherService = $this->cipherService;
        $this->siteSettingsService
            ->expects(self::once())
            ->method('writeSettings')
            ->with(
                $site,
                self::callback(static function (array $settings) use ($cipherService): bool {
                    return $settings['websiteId'] === 'w-123'
                        && $settings['instanceId'] === 'i-456'
                        && $settings['instanceSecret'] !== ''
                        && $cipherService->decrypt($settings['instanceSecret']) === 's3cr3t'
                        && $settings['existingKey'] === 'val'
                        && !array_key_exists('checkoutUrl', $settings);
                })
            );

        $this->subject->register($site, 'user@example.com');
    }

    #[Test]
    public function registerSendsCorrectDomainAndEmail(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"websiteId":"w-123","instanceId":"i-456"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);

        $this->subject->register($this->buildSite(), 'user@example.com');

        self::assertCount(1, $this->httpHistory);
        self::assertStringContainsString('/auth/register/instance', (string)$this->httpHistory[0]['request']->getUri());
        self::assertSame('POST', $this->httpHistory[0]['request']->getMethod());
        $body = json_decode((string)$this->httpHistory[0]['request']->getBody(), true);
        self::assertSame('https://example.com', $body['domain']);
        self::assertSame('user@example.com', $body['email']);
    }

    #[Test]
    public function registerStoresEmptySecretWhenApiResponseOmitsIt(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"websiteId":"w-123","instanceId":"i-456"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);

        $this->siteSettingsService
            ->expects(self::once())
            ->method('writeSettings')
            ->with($this->buildSite(), self::callback(static fn (array $s): bool => $s['instanceSecret'] === ''));

        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    #[Test]
    public function registerThrowsBeforeApiCallWhenDirectoryIsNotWritable(): void
    {
        $this->writeGuard
            ->method('assertDirectoryWritable')
            ->willThrowException(new AnalyticsApiException('Cannot write to config/sites/main/.', 0));

        $this->expectException(AnalyticsApiException::class);
        $this->expectExceptionMessage('Cannot write to config/sites/main/.');

        $this->subject->register($this->buildSite(), 'user@example.com');

        self::assertCount(0, $this->httpHistory, 'API must not be called when directory is not writable.');
    }

    #[Test]
    public function registerThrowsAnalyticsApiExceptionWhenWriteSettingsThrows(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"s3cr3t"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);
        $this->siteSettingsService
            ->method('writeSettings')
            ->willThrowException(new SiteConfigurationWriteException('disk full', 1590487411));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    #[Test]
    public function registerThrowsWhenSettingsCouldNotBePersisted(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"s3cr3t"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);
        $this->writeGuard
            ->method('assertSettingsPersisted')
            ->willThrowException(new AnalyticsApiException('Credentials could not be written.', 0));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    /** Helpers */

    /** attach */

    #[Test]
    public function attachWhitelistsDomainAndCopiesCredentialsOfTheTarget(): void
    {
        $targetSettings = [
            'websiteId' => 'w-1',
            'instanceId' => 'i-1',
            'instanceSecret' => $this->cipherService->encrypt('target-secret'),
            'externalWebsiteId' => 'ext-1',
            'apiKeyId' => 'k-1',
            'apiKey' => 'encrypted-key',
            'trackingCode' => '<script></script>',
            'status' => 'active',
            'unrelated' => 'not copied',
        ];
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturnMap([
            ['target', $targetSettings],
            ['second', ['own' => 'kept']],
        ]);
        $this->mockHandler->append(new Response(200, [], '{"domains":["second.example.com"]}'));

        $this->siteSettingsService->expects(self::once())->method('writeSettings')->with(
            self::anything(),
            ['own' => 'kept', 'websiteId' => 'w-1', 'instanceId' => 'i-1', 'instanceSecret' => $targetSettings['instanceSecret'], 'externalWebsiteId' => 'ext-1', 'apiKeyId' => 'k-1', 'apiKey' => 'encrypted-key', 'trackingCode' => '<script></script>', 'status' => 'active', 'attachedTo' => 'target']
        );

        $this->subject->attach($this->buildNamedSite('second', 'https://second.example.com'), $this->buildNamedSite('target', 'https://example.com'));

        $request = $this->httpHistory[0]['request'];
        self::assertStringEndsWith('/website/w-1/whitelisted-domains', $request->getUri()->getPath());
        self::assertSame(['domain' => 'https://second.example.com'], json_decode((string)$request->getBody(), true));
        self::assertStringStartsWith('HMAC i-1:', $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function attachWritesNothingWhenTheApiRejectsTheDomain(): void
    {
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([
            'websiteId' => 'w-1', 'instanceId' => 'i-1', 'instanceSecret' => $this->cipherService->encrypt('s'),
        ]);
        $this->mockHandler->append(new Response(400, [], '{"detail":"The domain is the primary domain of the website."}'));
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $this->expectException(AnalyticsApiException::class);

        $this->subject->attach($this->buildNamedSite('second', 'https://second.example.com'), $this->buildNamedSite('target', 'https://example.com'));
    }

    #[Test]
    public function attachFailsWithoutCredentialsOfTheTarget(): void
    {
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn(['websiteId' => 'w-1']);
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $this->expectException(AnalyticsApiException::class);

        $this->subject->attach($this->buildNamedSite('second', 'https://second.example.com'), $this->buildNamedSite('target', 'https://example.com'));
    }

    /** detach */

    #[Test]
    public function detachRemovesDomainAndCopiedCredentials(): void
    {
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([
            'own' => 'kept',
            'websiteId' => 'w-1',
            'instanceId' => 'i-1',
            'instanceSecret' => $this->cipherService->encrypt('target-secret'),
            'externalWebsiteId' => 'ext-1',
            'apiKeyId' => 'k-1',
            'apiKey' => 'encrypted-key',
            'trackingCode' => '<script></script>',
            'status' => 'active',
            'attachedTo' => 'target',
        ]);
        $this->mockHandler->append(new Response(204));

        $this->siteSettingsService->expects(self::once())->method('writeSettings')->with(self::anything(), ['own' => 'kept']);

        $this->subject->detach($this->buildNamedSite('second', 'https://second.example.com/'));

        $request = $this->httpHistory[0]['request'];
        self::assertSame('DELETE', $request->getMethod());
        self::assertStringEndsWith('/website/w-1/whitelisted-domains/second.example.com', $request->getUri()->getPath());
        self::assertStringStartsWith('HMAC i-1:', $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function detachFailsForSiteThatIsNotAttached(): void
    {
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([
            'websiteId' => 'w-1', 'instanceId' => 'i-1', 'instanceSecret' => $this->cipherService->encrypt('s'),
        ]);
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $this->expectException(AnalyticsApiException::class);

        $this->subject->detach($this->buildNamedSite('main', 'https://example.com'));
    }

    #[Test]
    public function detachKeepsSettingsWhenTheApiFails(): void
    {
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([
            'websiteId' => 'w-1', 'instanceId' => 'i-1', 'instanceSecret' => $this->cipherService->encrypt('s'), 'attachedTo' => 'target',
        ]);
        $this->mockHandler->append(new Response(400, [], '{"detail":"error"}'));
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $this->expectException(AnalyticsApiException::class);

        $this->subject->detach($this->buildNamedSite('second', 'https://second.example.com'));
    }

    private function buildNamedSite(string $identifier, string $base): Site
    {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getBase')->willReturn(new Uri($base));
        $site->method('getSettings')->willReturn(new SiteSettings(new Settings([]), [], []));
        return $site;
    }

    private function buildSite(): Site
    {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn('main');
        $site->method('getBase')->willReturn(new Uri('https://example.com'));
        $site->method('getSettings')->willReturn(new SiteSettings(new Settings([]), [], []));
        return $site;
    }
}
