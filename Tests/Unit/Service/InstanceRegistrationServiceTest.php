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
use T3G\Analytics\Exception\SiteAlreadyRegisteredException;
use T3G\Analytics\Exception\SiteSettingsLockedException;
use T3G\Analytics\Service\AnalyticsApiClient;
use T3G\Analytics\Service\ApiExceptionExtractor;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\HmacSigner;
use T3G\Analytics\Service\InstanceRegistrationService;
use T3G\Analytics\Service\SiteSettingsLock;
use T3G\Analytics\Service\SiteSettingsWriteVerifierInterface;
use T3G\Analytics\Tests\Fixtures\AnalyticsIds;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
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
    /** @var list<string> */
    private array $lockEvents = [];
    private bool $lockAvailable = true;

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
            new SiteSettingsLock($this->buildRecordingLockFactory()),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']);
        $this->lockAvailable = true;
        $this->lockEvents = [];
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

    #[Test]
    public function registerCallsApiAndWritesSettingsWhileHoldingLock(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['websiteId' => AnalyticsIds::WEBSITE_ID, 'instanceId' => AnalyticsIds::INSTANCE_ID], JSON_THROW_ON_ERROR)));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturnCallback(function (): array {
            $this->lockEvents[] = 'load after ' . count($this->httpHistory) . ' requests';
            return [];
        });
        $this->siteSettingsService->method('writeSettings')->willReturnCallback(function (): void {
            $this->lockEvents[] = 'write after ' . count($this->httpHistory) . ' requests';
        });

        $this->subject->register($this->buildSite(), 'user@example.com');

        self::assertSame(
            ['create tx_analytics_site_settings_main', 'acquire', 'load after 0 requests', 'write after 1 requests', 'release'],
            $this->lockEvents
        );
    }

    #[Test]
    public function registerDoesNotCallApiWhenSettingsAreLocked(): void
    {
        $this->lockAvailable = false;
        $this->mockHandler->append(new Response(200, [], json_encode(['websiteId' => AnalyticsIds::WEBSITE_ID, 'instanceId' => AnalyticsIds::INSTANCE_ID], JSON_THROW_ON_ERROR)));
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        try {
            $this->subject->register($this->buildSite(), 'user@example.com');
            self::fail('SiteSettingsLockedException expected.');
        } catch (SiteSettingsLockedException) {
        }

        self::assertCount(0, $this->httpHistory);
    }

    #[Test]
    public function registerDoesNotCallApiWhenSiteWasRegisteredByAnotherRequest(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['websiteId' => AnalyticsIds::WEBSITE_ID, 'instanceId' => AnalyticsIds::INSTANCE_ID], JSON_THROW_ON_ERROR)));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn(['websiteId' => AnalyticsIds::WEBSITE_ID]);
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        try {
            $this->subject->register($this->buildSite(), 'user@example.com');
            self::fail('SiteAlreadyRegisteredException expected.');
        } catch (SiteAlreadyRegisteredException) {
        }

        self::assertCount(0, $this->httpHistory);
        self::assertSame(['create tx_analytics_site_settings_main', 'acquire', 'release'], $this->lockEvents);
    }

    /** Helpers */

    private function buildRecordingLockFactory(): LockFactory
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturnCallback(function (): bool {
            if (!$this->lockAvailable) {
                throw new LockAcquireWouldBlockException('Locked by another request.', 1760521300);
            }
            $this->lockEvents[] = 'acquire';
            return true;
        });
        $locker->method('release')->willReturnCallback(function (): bool {
            $this->lockEvents[] = 'release';
            return true;
        });
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturnCallback(function (string $id) use ($locker): LockingStrategyInterface {
            $this->lockEvents[] = 'create ' . $id;
            return $locker;
        });
        return $lockFactory;
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
