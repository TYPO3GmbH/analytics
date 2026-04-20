<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use T3G\Analytics\Middleware\TrackingCodeMiddleware;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

/**
 * Functional tests for TrackingCodeMiddleware.
 *
 * Uses real TYPO3 PSR-7 HTTP objects (Response, Stream) to verify that the
 * body-manipulation logic works correctly end-to-end, without mocking the
 * stream layer.
 */
final class TrackingCodeMiddlewareTest extends FunctionalTestCase
{
    private TrackingCodeMiddleware $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new TrackingCodeMiddleware();
    }

    #[Test]
    public function processInjectsTrackingCodeIntoRealHtmlResponse(): void
    {
        $trackingCode = '<!-- TYPO3 Analytics --><script>va("active-id")</script><!-- TYPO3 Analytics -->';
        $html = "<!DOCTYPE html>\n<html>\n<head><title>Test</title></head>\n<body><p>Hello</p>\n</body>\n</html>";

        $request = $this->buildRequestWithSite(['trackingCode' => $trackingCode, 'status' => 'active']);
        $handler = $this->buildHandler($this->buildHtmlResponse($html));

        $response = $this->subject->process($request, $handler);

        $body = (string)$response->getBody();
        self::assertStringContainsString($trackingCode, $body);
        self::assertStringContainsString($trackingCode . "\n</body>", $body);
        // Original content is preserved
        self::assertStringContainsString('<p>Hello</p>', $body);
    }

    #[Test]
    public function processDoesNotInjectWhenStatusIsPending(): void
    {
        $trackingCode = '<!-- TYPO3 Analytics --><script>va("id")</script><!-- TYPO3 Analytics -->';
        $html = '<html><body><p>Hello</p></body></html>';

        $request = $this->buildRequestWithSite(['trackingCode' => $trackingCode, 'status' => 'pending']);
        $originalResponse = $this->buildHtmlResponse($html);
        $handler = $this->buildHandler($originalResponse);

        $response = $this->subject->process($request, $handler);

        self::assertSame($originalResponse, $response);
        self::assertStringNotContainsString($trackingCode, (string)$response->getBody());
    }

    #[Test]
    public function processDoesNotInjectWhenStatusIsInactive(): void
    {
        $trackingCode = '<!-- TYPO3 Analytics --><script>va("id")</script><!-- TYPO3 Analytics -->';
        $html = '<html><body></body></html>';

        $request = $this->buildRequestWithSite(['trackingCode' => $trackingCode, 'status' => 'inactive']);
        $originalResponse = $this->buildHtmlResponse($html);
        $handler = $this->buildHandler($originalResponse);

        $response = $this->subject->process($request, $handler);

        self::assertSame($originalResponse, $response);
    }

    #[Test]
    public function processDoesNotInjectWhenStatusIsCancelled(): void
    {
        $trackingCode = '<!-- TYPO3 Analytics --><script>va("id")</script><!-- TYPO3 Analytics -->';
        $html = '<html><body></body></html>';

        $request = $this->buildRequestWithSite(['trackingCode' => $trackingCode, 'status' => 'cancelled']);
        $originalResponse = $this->buildHtmlResponse($html);
        $handler = $this->buildHandler($originalResponse);

        $response = $this->subject->process($request, $handler);

        self::assertSame($originalResponse, $response);
    }

    #[Test]
    public function processDoesNotModifyJsonResponse(): void
    {
        $request = $this->buildRequestWithSite([
            'trackingCode' => '<script>va()</script>',
            'status' => 'active',
        ]);

        $jsonBody = '{"key":"value"}';
        $response = (new Response())
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->buildStream($jsonBody));

        $handler = $this->buildHandler($response);

        $result = $this->subject->process($request, $handler);

        self::assertSame($response, $result);
        self::assertSame($jsonBody, (string)$result->getBody());
    }

    /** Helpers */

    private function buildRequestWithSite(array $siteSettings): ServerRequestInterface
    {
        $settings = new SiteSettings(new Settings($siteSettings), [], []);

        $site = $this->createMock(Site::class);
        $site->method('getSettings')->willReturn($settings);
        $site->method('getBase')->willReturn(new Uri('https://example.com'));

        return (new ServerRequest(new Uri('https://example.com/'), 'GET'))
            ->withAttribute('site', $site);
    }

    private function buildHtmlResponse(string $body): ResponseInterface
    {
        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->buildStream($body));
    }

    private function buildHandler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);
        return $handler;
    }

    private function buildStream(string $content): \Psr\Http\Message\StreamInterface
    {
        $stream = new \TYPO3\CMS\Core\Http\Stream('php://temp', 'r+');
        $stream->write($content);
        $stream->rewind();
        return $stream;
    }
}
