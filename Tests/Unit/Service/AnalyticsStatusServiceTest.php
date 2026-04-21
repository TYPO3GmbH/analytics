<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use T3G\Analytics\Service\AnalyticsStatusService;
use T3G\Analytics\Service\CipherService;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
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

    /** @var FrontendInterface&MockObject */
    private FrontendInterface $cache;
    /** @var RequestFactory&MockObject */
    private RequestFactory $requestFactory;
    /** @var CipherService&MockObject */
    private CipherService $cipherService;
    /** @var SiteSettingsService&MockObject */
    private SiteSettingsService $siteSettingsService;
    /** @var SiteSettingsFactory&MockObject */
    private SiteSettingsFactory $siteSettingsFactory;

    private AnalyticsStatusService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = $this->createMock(FrontendInterface::class);
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->cipherService = $this->createMock(CipherService::class);
        $this->siteSettingsService = $this->createMock(SiteSettingsService::class);
        $this->siteSettingsFactory = $this->createMock(SiteSettingsFactory::class);

        $this->cipherService->method('decrypt')->willReturn('plain-secret');

        $this->subject = new AnalyticsStatusService(
            $this->cache,
            $this->requestFactory,
            $this->cipherService,
            new NullLogger(),
            $this->siteSettingsService,
            $this->siteSettingsFactory,
        );
    }

    #[Test]
    public function persistsTrackingCodeAndStatusWhenApiResponseContainsThem(): void
    {
        $site = $this->buildSite('main', 'w-123', 'i-456');
        $this->requestFactory->method('request')->willReturn(
            $this->buildApiResponse('{"status":"active","trackingId":"tc-abc"}')
        );
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
        $this->requestFactory->method('request')->willReturn(
            $this->buildApiResponse('{"status":"active","trackingId":"tc-abc"}')
        );

        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $this->subject->getStatus($site, forceRefresh: true);
    }

    #[Test]
    public function skipsWriteSettingsWhenApiResponseHasNoTrackingIdOrStatus(): void
    {
        $site = $this->buildSite('main', 'w-123', 'i-456');
        $this->requestFactory->method('request')->willReturn(
            $this->buildApiResponse('{"packageName":"Pro"}')
        );

        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $this->subject->getStatus($site, forceRefresh: true);
    }

    #[Test]
    public function skipsWriteSettingsOnCacheHit(): void
    {
        $site = $this->buildSite('main', 'w-123', 'i-456');
        $this->cache->method('has')->willReturn(true);
        $this->cache->method('get')->willReturn(['status' => 'active', '_fetchedAt' => time()]);

        $this->requestFactory->expects(self::never())->method('request');
        $this->siteSettingsService->expects(self::never())->method('writeSettings');

        $this->subject->getStatus($site);
    }

    /** Helpers */

    private function buildSite(string $identifier, string $websiteId, string $instanceId, array $extraSettings = []): Site&MockObject
    {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getBase')->willReturn(new Uri('https://example.com'));
        $site->method('getSettings')->willReturn(new SiteSettings(
            new Settings(array_merge(['websiteId' => $websiteId, 'instanceId' => $instanceId, 'instanceSecret' => 'enc-secret'], $extraSettings)),
            [],
            []
        ));
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
