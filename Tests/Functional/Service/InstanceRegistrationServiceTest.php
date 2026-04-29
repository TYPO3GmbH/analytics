<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\InstanceRegistrationService;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
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

    /** @var RequestFactory&MockObject */
    private RequestFactory $requestFactory;
    /** @var SiteSettingsService&MockObject */
    private SiteSettingsService $siteSettingsService;
    /** @var SiteSettingsFactory&MockObject */
    private SiteSettingsFactory $siteSettingsFactory;
    private CipherService $cipherService;
    private InstanceRegistrationService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->siteSettingsService = $this->createMock(SiteSettingsService::class);
        $this->siteSettingsFactory = $this->createMock(SiteSettingsFactory::class);
        $this->cipherService = new CipherService();

        $this->subject = new InstanceRegistrationService(
            $this->requestFactory,
            $this->cipherService,
            $this->siteSettingsService,
            $this->siteSettingsFactory,
            new \Psr\Log\NullLogger(),
        );
    }

    #[Test]
    public function registerWritesEncryptedCredentialsToSiteSettings(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->buildApiResponse(
                '{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"plain-secret"}'
            ));
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
        $this->requestFactory
            ->method('request')
            ->willReturn($this->buildApiResponse(
                '{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"secret"}'
            ));
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
        $this->requestFactory
            ->method('request')
            ->willThrowException(new \RuntimeException('connection refused'));

        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $this->expectException(\RuntimeException::class);
        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    #[Test]
    public function registerThrowsWhenApiResponseIsMissingWebsiteId(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->buildApiResponse('{"instanceId":"i-456","instanceSecret":"secret"}'));

        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $this->expectException(\RuntimeException::class);
        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    #[Test]
    public function registerCallsApiWithCorrectPayload(): void
    {
        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('/auth/register/instance'),
                'POST',
                self::callback(static function (array $opts): bool {
                    return ($opts['json']['intpId'] ?? '') !== ''
                        && ($opts['json']['domain'] ?? '') === 'https://example.com'
                        && ($opts['json']['email'] ?? '') === 'user@example.com';
                })
            )
            ->willReturn($this->buildApiResponse(
                '{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"secret"}'
            ));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);
        $this->siteSettingsService->method('writeSettings');

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

    private function buildApiResponse(string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        return $response;
    }
}
