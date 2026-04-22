<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Service\InstanceRegistrationService;
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

    /** @var RequestFactory&MockObject */
    private RequestFactory $requestFactory;
    /** @var CipherService&MockObject */
    private CipherService $cipherService;
    /** @var SiteSettingsService&MockObject */
    private SiteSettingsService $siteSettingsService;
    /** @var SiteSettingsFactory&MockObject */
    private SiteSettingsFactory $siteSettingsFactory;

    private InstanceRegistrationService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->cipherService = $this->createMock(CipherService::class);
        $this->siteSettingsService = $this->createMock(SiteSettingsService::class);
        $this->siteSettingsFactory = $this->createMock(SiteSettingsFactory::class);

        $this->subject = new InstanceRegistrationService(
            $this->requestFactory,
            $this->cipherService,
            $this->siteSettingsService,
            $this->siteSettingsFactory,
            new NullLogger(),
        );
    }

    #[Test]
    public function registerThrowsWhenApiCallFails(): void
    {
        $this->requestFactory->method('request')->willThrowException(new \RuntimeException('connection refused'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('connection refused');

        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    #[Test]
    public function registerThrowsWhenApiResponseMissesWebsiteId(): void
    {
        $this->requestFactory->method('request')->willReturn(
            $this->buildApiResponse('{"instanceId":"i-456","instanceSecret":"s3cr3t"}')
        );

        $this->expectException(\RuntimeException::class);

        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    #[Test]
    public function registerThrowsWhenApiResponseMissesInstanceId(): void
    {
        $this->requestFactory->method('request')->willReturn(
            $this->buildApiResponse('{"websiteId":"w-123","instanceSecret":"s3cr3t"}')
        );

        $this->expectException(\RuntimeException::class);

        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    #[Test]
    public function registerReturnsEmptyStringWhenNoCheckoutUrl(): void
    {
        $this->requestFactory->method('request')->willReturn(
            $this->buildApiResponse('{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"s3cr3t"}')
        );
        $this->cipherService->method('encrypt')->willReturn('enc-secret');
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);

        $result = $this->subject->register($this->buildSite(), 'user@example.com');

        self::assertSame('', $result);
    }

    #[Test]
    public function registerReturnsCheckoutUrlFromApiResponse(): void
    {
        $checkoutUrl = 'https://checkout.example.com/plan?token=abc';
        $this->requestFactory->method('request')->willReturn(
            $this->buildApiResponse('{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"s3cr3t","checkoutUrl":"' . $checkoutUrl . '"}')
        );
        $this->cipherService->method('encrypt')->willReturn('enc-secret');
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);

        $result = $this->subject->register($this->buildSite(), 'user@example.com');

        self::assertSame($checkoutUrl, $result);
    }

    #[Test]
    public function registerWritesCredentialsAndMergesExistingSettings(): void
    {
        $site = $this->buildSite();
        $this->requestFactory->method('request')->willReturn(
            $this->buildApiResponse('{"websiteId":"w-123","instanceId":"i-456","instanceSecret":"s3cr3t"}')
        );
        $this->cipherService->method('encrypt')->with('s3cr3t')->willReturn('enc-secret');
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn(['existingKey' => 'val']);

        $this->siteSettingsService
            ->expects(self::once())
            ->method('writeSettings')
            ->with(
                $site,
                self::callback(static function (array $settings): bool {
                    return $settings['websiteId'] === 'w-123'
                        && $settings['instanceId'] === 'i-456'
                        && $settings['instanceSecret'] === 'enc-secret'
                        && $settings['existingKey'] === 'val'
                        && !array_key_exists('checkoutUrl', $settings);
                })
            );

        $this->subject->register($site, 'user@example.com');
    }

    #[Test]
    public function registerSendsCorrectDomainAndEmail(): void
    {
        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('/auth/register/instance'),
                'POST',
                self::callback(static function (array $options): bool {
                    return ($options['json']['domain'] ?? '') === 'https://example.com'
                        && ($options['json']['email'] ?? '') === 'user@example.com';
                })
            )
            ->willReturn($this->buildApiResponse('{"websiteId":"w-123","instanceId":"i-456"}'));
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);

        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    #[Test]
    public function registerSkipsEncryptionWhenSecretIsEmpty(): void
    {
        $this->requestFactory->method('request')->willReturn(
            $this->buildApiResponse('{"websiteId":"w-123","instanceId":"i-456"}')
        );
        $this->siteSettingsFactory->method('loadLocalSettings')->willReturn([]);
        $this->cipherService->expects(self::never())->method('encrypt');

        $this->siteSettingsService
            ->expects(self::once())
            ->method('writeSettings')
            ->with($this->buildSite(), self::callback(static fn (array $s): bool => $s['instanceSecret'] === ''));

        $this->subject->register($this->buildSite(), 'user@example.com');
    }

    /** Helpers */

    private function buildSite(): Site&MockObject
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
