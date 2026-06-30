<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Service\HmacSigner;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class HmacSignerTest extends UnitTestCase
{
    private HmacSigner $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new HmacSigner();
    }

    #[Test]
    public function buildHeadersReturnsAllThreeHeaders(): void
    {
        $headers = $this->subject->buildHeaders('GET', '/api/status/w-123', 'i-456', 'secret');

        self::assertArrayHasKey('Authorization', $headers);
        self::assertArrayHasKey('X-Timestamp', $headers);
        self::assertArrayHasKey('X-Content-Hash', $headers);
    }

    #[Test]
    public function buildHeadersAuthorizationHasCorrectFormat(): void
    {
        $headers = $this->subject->buildHeaders('GET', '/api/status/w-123', 'my-instance', 'secret');

        self::assertMatchesRegularExpression('/^HMAC my-instance:[A-Za-z0-9+\/=]+$/', $headers['Authorization']);
    }

    #[Test]
    public function buildHeadersContentHashIsSha256OfEmptyString(): void
    {
        $headers = $this->subject->buildHeaders('GET', '/api/status/w-123', 'i-456', 'secret');

        self::assertSame(hash('sha256', ''), $headers['X-Content-Hash']);
    }

    #[Test]
    public function buildHeadersTimestampIsAtomFormat(): void
    {
        $headers = $this->subject->buildHeaders('GET', '/api/status/w-123', 'i-456', 'secret');

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $headers['X-Timestamp']
        );
    }

    #[Test]
    public function buildHeadersProducesDeterministicSignatureForSameInputs(): void
    {
        $method = 'GET';
        $path = '/api/status/w-123';
        $instanceId = 'i-456';
        $instanceSecret = 'my-secret';

        $headersA = $this->subject->buildHeaders($method, $path, $instanceId, $instanceSecret);

        $contentHash = hash('sha256', '');
        $canonical = implode("\n", ['GET', $path, $headersA['X-Timestamp'], $contentHash]);
        $expectedSignature = base64_encode(hash_hmac('sha256', $canonical, $instanceSecret, true));

        self::assertSame('HMAC ' . $instanceId . ':' . $expectedSignature, $headersA['Authorization']);
    }

    #[Test]
    public function buildHeadersNormalizesHttpMethodToUppercase(): void
    {
        $headersLower = $this->subject->buildHeaders('get', '/api/status/w-123', 'i-456', 'secret');

        $contentHash = hash('sha256', '');
        $canonical = implode("\n", ['GET', '/api/status/w-123', $headersLower['X-Timestamp'], $contentHash]);
        $expected = base64_encode(hash_hmac('sha256', $canonical, 'secret', true));

        self::assertSame('HMAC i-456:' . $expected, $headersLower['Authorization']);
    }

    #[Test]
    public function buildHeadersSignatureDiffersForDifferentSecrets(): void
    {
        $headers1 = $this->subject->buildHeaders('GET', '/api/status/w-123', 'i-456', 'secret-one');
        $headers2 = $this->subject->buildHeaders('GET', '/api/status/w-123', 'i-456', 'secret-two');

        self::assertNotSame($headers1['Authorization'], $headers2['Authorization']);
    }

    #[Test]
    public function buildHeadersSignatureDiffersForDifferentPaths(): void
    {
        $headers1 = $this->subject->buildHeaders('GET', '/api/status/w-123', 'i-456', 'secret');
        $headers2 = $this->subject->buildHeaders('GET', '/api/dashboard-url/w-123', 'i-456', 'secret');

        self::assertNotSame($headers1['Authorization'], $headers2['Authorization']);
    }
}
