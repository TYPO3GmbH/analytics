<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use T3G\Analytics\Middleware\TrackingCodeMiddleware;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TrackingCodeMiddlewareTest extends UnitTestCase
{
    private TrackingCodeMiddleware $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new TrackingCodeMiddleware();
    }

    #[Test]
    public function processPassesThroughWhenNoSiteAttribute(): void
    {
        $response = $this->buildHtmlResponse('<html><body></body></html>');
        $handler = $this->buildHandler($response);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('site')->willReturn(null);

        $result = $this->subject->process($request, $handler);

        self::assertSame($response, $result);
    }

    #[Test]
    public function processPassesThroughWhenTrackingCodeEmpty(): void
    {
        $response = $this->buildHtmlResponse('<html><body></body></html>');
        $handler = $this->buildHandler($response);
        $request = $this->buildRequestWithSite(['trackingCode' => '', 'status' => 'active']);

        $result = $this->subject->process($request, $handler);

        self::assertSame($response, $result);
    }

    #[Test]
    public function processPassesThroughWhenStatusIsNotActive(): void
    {
        $response = $this->buildHtmlResponse('<html><body></body></html>');
        $handler = $this->buildHandler($response);
        $request = $this->buildRequestWithSite([
            'trackingCode' => '<!-- code --><script>va()</script><!-- code -->',
            'status' => 'pending',
        ]);

        $result = $this->subject->process($request, $handler);

        self::assertSame($response, $result);
    }

    #[Test]
    public function processPassesThroughWhenContentTypeIsNotHtml(): void
    {
        $response = $this->buildResponse('{"foo":"bar"}', 'application/json');
        $handler = $this->buildHandler($response);
        $request = $this->buildRequestWithSite([
            'trackingCode' => '<!-- code --><script>va()</script><!-- code -->',
            'status' => 'active',
        ]);

        $result = $this->subject->process($request, $handler);

        self::assertSame($response, $result);
    }

    #[Test]
    public function processPassesThroughWhenBodyHasNoClosingBodyTag(): void
    {
        $response = $this->buildHtmlResponse('<html><head></head></html>');
        $handler = $this->buildHandler($response);
        $request = $this->buildRequestWithSite([
            'trackingCode' => '<!-- code --><script>va()</script><!-- code -->',
            'status' => 'active',
        ]);

        $result = $this->subject->process($request, $handler);

        self::assertSame($response, $result);
    }

    #[Test]
    public function processInjectsTrackingCodeBeforeClosingBodyTag(): void
    {
        $trackingCode = '<!-- TYPO3 Analytics --><script>va()</script><!-- TYPO3 Analytics -->';
        $html = '<html><body><p>Hello</p></body></html>';

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($html);

        /** @var ResponseInterface&MockObject $response */
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')->with('Content-Type')->willReturn('text/html; charset=utf-8');

        $capturedBody = null;
        $response->method('withBody')->willReturnCallback(
            static function ($newBody) use ($response, &$capturedBody): ResponseInterface {
                $capturedBody = $newBody;
                return $response;
            }
        );

        $handler = $this->buildHandler($response);
        $request = $this->buildRequestWithSite(['trackingCode' => $trackingCode, 'status' => 'active']);

        $this->subject->process($request, $handler);

        self::assertNotNull($capturedBody);
        $injected = (string)$capturedBody;
        self::assertStringContainsString($trackingCode, $injected);
        self::assertStringContainsString($trackingCode . "\n</body>", $injected);
    }

    #[Test]
    public function processInjectsTrackingCodeWhenContentTypeIsAbsent(): void
    {
        $trackingCode = '<script>va()</script>';
        $html = '<html><body></body></html>';

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($html);

        /** @var ResponseInterface&MockObject $response */
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')->with('Content-Type')->willReturn('');
        $response->method('withBody')->willReturnCallback(static function ($newBody) use ($response): ResponseInterface {
            $new = clone $response;
            return $new;
        });

        $handler = $this->buildHandler($response);
        $request = $this->buildRequestWithSite(['trackingCode' => $trackingCode, 'status' => 'active']);

        $result = $this->subject->process($request, $handler);

        // When Content-Type is absent the middleware should still inject
        self::assertNotSame($response, $result);
    }

    /** Helpers */

    private function buildHandler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);
        return $handler;
    }

    private function buildRequestWithSite(array $siteSettings): ServerRequestInterface
    {
        $settings = new SiteSettings(new Settings($siteSettings), [], []);

        $site = $this->createMock(Site::class);
        $site->method('getSettings')->willReturn($settings);
        $site->method('getBase')->willReturn(new Uri('https://example.com'));

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('site')->willReturn($site);
        return $request;
    }

    private function buildHtmlResponse(string $body): ResponseInterface
    {
        return $this->buildResponse($body, 'text/html; charset=utf-8');
    }

    private function buildResponse(string $body, string $contentType): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        /** @var ResponseInterface&MockObject $response */
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')->with('Content-Type')->willReturn($contentType);
        $response->method('withBody')->willReturnSelf();
        return $response;
    }
}
