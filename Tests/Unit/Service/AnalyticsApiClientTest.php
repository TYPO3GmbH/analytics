<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Service\AnalyticsApiClient;
use T3G\Analytics\Service\ApiExceptionExtractor;
use T3G\Analytics\Service\HmacSigner;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AnalyticsApiClientTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private MockHandler $mockHandler;
    private array $httpHistory = [];
    private AnalyticsApiClient $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockHandler = new MockHandler();
        $this->httpHistory = [];
        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->httpHistory));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = ['verify' => false, 'handler' => $stack];

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['verifySsl'] = '0';

        $this->subject = new AnalyticsApiClient(
            new RequestFactory(new GuzzleClientFactory()),
            new ApiConfiguration(),
            new HmacSigner(),
            new ApiExceptionExtractor(),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']);
        parent::tearDown();
    }

    #[Test]
    public function createApiKeySendsPostToCorrectEndpoint(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"apiKeyId":"key-uuid","apiKey":"secret-value"}'));

        $this->subject->createApiKey('w-123', 'i-456', 'my-secret');

        self::assertCount(1, $this->httpHistory);
        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/api-keys/w-123', (string)$request->getUri());
    }

    #[Test]
    public function createApiKeyReturnsApiKeyIdAndApiKey(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"apiKeyId":"key-uuid","apiKey":"secret-value"}'));

        $result = $this->subject->createApiKey('w-123', 'i-456', 'my-secret');

        self::assertSame('key-uuid', $result['apiKeyId']);
        self::assertSame('secret-value', $result['apiKey']);
    }

    #[Test]
    public function createApiKeySendsHmacAuthorizationHeader(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"apiKeyId":"k","apiKey":"v"}'));

        $this->subject->createApiKey('w-123', 'i-456', 'my-secret');

        $authHeader = $this->httpHistory[0]['request']->getHeaderLine('Authorization');
        self::assertStringStartsWith('HMAC i-456:', $authHeader);
    }

    #[Test]
    public function createApiKeyThrowsOnNetworkError(): void
    {
        $this->mockHandler->append(new \RuntimeException('connection refused'));

        $this->expectException(AnalyticsApiException::class);
        $this->expectExceptionMessage('connection refused');

        $this->subject->createApiKey('w-123', 'i-456', 'my-secret');
    }

    #[Test]
    public function createApiKeyThrowsOnHttpError(): void
    {
        $this->mockHandler->append(new Response(403, [], '{"message":"Forbidden"}'));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->createApiKey('w-123', 'i-456', 'my-secret');
    }

    #[Test]
    public function createApiKeyReturnsEmptyStringsWhenResponseBodyLacksFields(): void
    {
        $this->mockHandler->append(new Response(200, [], '{}'));

        $result = $this->subject->createApiKey('w-123', 'i-456', 'my-secret');

        self::assertSame('', $result['apiKeyId']);
        self::assertSame('', $result['apiKey']);
    }

    #[Test]
    public function fetchDashboardUrlSendsGetWithoutRoleParamByDefault(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"dashboardUrl":"https://dashboard.example.com"}'));

        $this->subject->fetchDashboardUrl('w-123', 'i-456', 'my-secret');

        $uri = (string)$this->httpHistory[0]['request']->getUri();
        self::assertStringContainsString('/dashboard-url/w-123', $uri);
        self::assertStringNotContainsString('role=', $uri);
    }

    #[Test]
    public function fetchDashboardUrlAppendsRoleWatcherWhenWatcherIsTrue(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"dashboardUrl":"https://dashboard.example.com"}'));

        $this->subject->fetchDashboardUrl('w-123', 'i-456', 'my-secret', watcher: true);

        $uri = (string)$this->httpHistory[0]['request']->getUri();
        self::assertStringContainsString('role=watcher', $uri);

        // HMAC must be signed including the query string so middleware validation passes
        $authHeader = $this->httpHistory[0]['request']->getHeaderLine('Authorization');
        self::assertNotEmpty($authHeader);
    }

    #[Test]
    public function fetchDashboardUrlReturnsUrlFromResponse(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"dashboardUrl":"https://dashboard.example.com?token=abc"}'));

        $result = $this->subject->fetchDashboardUrl('w-123', 'i-456', 'my-secret');

        self::assertSame('https://dashboard.example.com?token=abc', $result);
    }

    #[Test]
    public function fetchDashboardUrlReturnsNullWhenResponseHasNoDashboardUrl(): void
    {
        $this->mockHandler->append(new Response(200, [], '{}'));

        self::assertNull($this->subject->fetchDashboardUrl('w-123', 'i-456', 'my-secret'));
    }
}
