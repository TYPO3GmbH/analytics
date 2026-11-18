<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Service\AnalyticsApiClient;
use T3G\Analytics\Service\ApiExceptionExtractor;
use T3G\Analytics\Service\HmacSigner;
use T3G\Analytics\Tests\Fixtures\AnalyticsIds;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AnalyticsApiClientTest extends UnitTestCase
{
    private const UUID_V1 = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

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

    public static function invalidIdsDataProvider(): \Generator
    {
        $calls = [
            'fetchStatus' => static fn (AnalyticsApiClient $client, string $websiteId, string $instanceId): array => $client->fetchStatus($websiteId, $instanceId, 'my-secret'),
            'fetchDashboardUrl' => static fn (AnalyticsApiClient $client, string $websiteId, string $instanceId): ?string => $client->fetchDashboardUrl($websiteId, $instanceId, 'my-secret'),
            'createApiKey' => static fn (AnalyticsApiClient $client, string $websiteId, string $instanceId): array => $client->createApiKey($websiteId, $instanceId, 'my-secret'),
            'fetchCheckoutUrl' => static fn (AnalyticsApiClient $client, string $websiteId, string $instanceId): ?string => $client->fetchCheckoutUrl($websiteId, $instanceId, 'my-secret'),
        ];
        foreach ($calls as $method => $call) {
            yield $method . ' with websiteId no UUID' => [$call, 'not-a-uuid', AnalyticsIds::INSTANCE_ID];
            yield $method . ' with websiteId UUIDv1' => [$call, self::UUID_V1, AnalyticsIds::INSTANCE_ID];
            yield $method . ' with instanceId no UUID' => [$call, AnalyticsIds::WEBSITE_ID, 'not-a-uuid'];
            yield $method . ' with instanceId UUIDv1' => [$call, AnalyticsIds::WEBSITE_ID, self::UUID_V1];
        }
    }

    #[Test]
    #[DataProvider('invalidIdsDataProvider')]
    public function invalidIdsAreRejectedBeforeRequest(\Closure $call, string $websiteId, string $instanceId): void
    {
        $exception = null;
        try {
            $call($this->subject, $websiteId, $instanceId);
        } catch (AnalyticsApiException $e) {
            $exception = $e;
        }

        self::assertInstanceOf(AnalyticsApiException::class, $exception);
        self::assertStringContainsString('is not a valid UUIDv4', $exception->getMessage());
        self::assertCount(0, $this->httpHistory);
    }

    #[Test]
    public function createApiKeySendsPostToCorrectEndpoint(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['apiKeyId' => AnalyticsIds::API_KEY_ID, 'apiKey' => 'secret-value'], JSON_THROW_ON_ERROR)));

        $this->subject->createApiKey(AnalyticsIds::WEBSITE_ID, AnalyticsIds::INSTANCE_ID, 'my-secret');

        self::assertCount(1, $this->httpHistory);
        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/api-keys/' . AnalyticsIds::WEBSITE_ID, (string)$request->getUri());
    }

    #[Test]
    public function createApiKeyReturnsApiKeyIdAndApiKey(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['apiKeyId' => AnalyticsIds::API_KEY_ID, 'apiKey' => 'secret-value'], JSON_THROW_ON_ERROR)));

        $result = $this->subject->createApiKey(AnalyticsIds::WEBSITE_ID, AnalyticsIds::INSTANCE_ID, 'my-secret');

        self::assertSame(AnalyticsIds::API_KEY_ID, $result['apiKeyId']);
        self::assertSame('secret-value', $result['apiKey']);
    }

    #[Test]
    public function createApiKeySendsHmacAuthorizationHeader(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['apiKeyId' => AnalyticsIds::API_KEY_ID, 'apiKey' => 'secret-value'], JSON_THROW_ON_ERROR)));

        $this->subject->createApiKey(AnalyticsIds::WEBSITE_ID, AnalyticsIds::INSTANCE_ID, 'my-secret');

        $authHeader = $this->httpHistory[0]['request']->getHeaderLine('Authorization');
        self::assertStringStartsWith('HMAC ' . AnalyticsIds::INSTANCE_ID . ':', $authHeader);
    }

    #[Test]
    public function createApiKeyThrowsOnNetworkError(): void
    {
        $this->mockHandler->append(new \RuntimeException('connection refused'));

        $this->expectException(AnalyticsApiException::class);
        $this->expectExceptionMessage('connection refused');

        $this->subject->createApiKey(AnalyticsIds::WEBSITE_ID, AnalyticsIds::INSTANCE_ID, 'my-secret');
    }

    #[Test]
    public function createApiKeyThrowsOnHttpError(): void
    {
        $this->mockHandler->append(new Response(403, [], json_encode(['message' => 'Forbidden'], JSON_THROW_ON_ERROR)));

        $this->expectException(AnalyticsApiException::class);

        $this->subject->createApiKey(AnalyticsIds::WEBSITE_ID, AnalyticsIds::INSTANCE_ID, 'my-secret');
    }

    #[Test]
    public function createApiKeyReturnsEmptyStringsWhenResponseBodyLacksFields(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(new \stdClass(), JSON_THROW_ON_ERROR)));

        $result = $this->subject->createApiKey(AnalyticsIds::WEBSITE_ID, AnalyticsIds::INSTANCE_ID, 'my-secret');

        self::assertSame('', $result['apiKeyId']);
        self::assertSame('', $result['apiKey']);
    }

    #[Test]
    public function fetchDashboardUrlSendsGetWithoutRoleParamByDefault(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['dashboardUrl' => 'https://dashboard.example.com'], JSON_THROW_ON_ERROR)));

        $this->subject->fetchDashboardUrl(AnalyticsIds::WEBSITE_ID, AnalyticsIds::INSTANCE_ID, 'my-secret');

        $uri = (string)$this->httpHistory[0]['request']->getUri();
        self::assertStringContainsString('/dashboard-url/' . AnalyticsIds::WEBSITE_ID, $uri);
        self::assertStringNotContainsString('role=', $uri);
    }

    #[Test]
    public function fetchDashboardUrlAppendsRoleWatcherWhenWatcherIsTrue(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['dashboardUrl' => 'https://dashboard.example.com'], JSON_THROW_ON_ERROR)));

        $this->subject->fetchDashboardUrl(AnalyticsIds::WEBSITE_ID, AnalyticsIds::INSTANCE_ID, 'my-secret', true);

        $uri = (string)$this->httpHistory[0]['request']->getUri();
        self::assertStringContainsString('role=watcher', $uri);

        // HMAC must be signed including the query string so middleware validation passes
        $authHeader = $this->httpHistory[0]['request']->getHeaderLine('Authorization');
        self::assertNotEmpty($authHeader);
    }

    #[Test]
    public function fetchDashboardUrlReturnsUrlFromResponse(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['dashboardUrl' => 'https://dashboard.example.com?token=abc'], JSON_THROW_ON_ERROR)));

        $result = $this->subject->fetchDashboardUrl(AnalyticsIds::WEBSITE_ID, AnalyticsIds::INSTANCE_ID, 'my-secret');

        self::assertSame('https://dashboard.example.com?token=abc', $result);
    }

    #[Test]
    public function fetchDashboardUrlReturnsNullWhenResponseHasNoDashboardUrl(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(new \stdClass(), JSON_THROW_ON_ERROR)));

        self::assertNull($this->subject->fetchDashboardUrl(AnalyticsIds::WEBSITE_ID, AnalyticsIds::INSTANCE_ID, 'my-secret'));
    }
}
