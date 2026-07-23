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
