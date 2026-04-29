<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Utility\HmacUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class HmacUtilityTest extends UnitTestCase
{
    #[Test]
    public function buildHeadersReturnsAllThreeHeaders(): void
    {
        $headers = HmacUtility::buildHeaders('GET', '/api/status/w-123', 'i-456', 'secret');

        self::assertArrayHasKey('Authorization', $headers);
        self::assertArrayHasKey('X-Timestamp', $headers);
        self::assertArrayHasKey('X-Content-Hash', $headers);
    }

    #[Test]
    public function buildHeadersAuthorizationHasCorrectFormat(): void
    {
        $headers = HmacUtility::buildHeaders('GET', '/api/status/w-123', 'my-instance', 'secret');

        self::assertMatchesRegularExpression('/^HMAC my-instance:[A-Za-z0-9+\/=]+$/', $headers['Authorization']);
    }

    #[Test]
    public function buildHeadersContentHashIsSha256OfEmptyString(): void
    {
        $headers = HmacUtility::buildHeaders('GET', '/api/status/w-123', 'i-456', 'secret');

        self::assertSame(hash('sha256', ''), $headers['X-Content-Hash']);
    }

    #[Test]
    public function buildHeadersTimestampIsAtomFormat(): void
    {
        $headers = HmacUtility::buildHeaders('GET', '/api/status/w-123', 'i-456', 'secret');

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

        $headersA = HmacUtility::buildHeaders($method, $path, $instanceId, $instanceSecret);
        $headersB = HmacUtility::buildHeaders($method, $path, $instanceId, $instanceSecret);

        // Timestamps may differ between calls — verify the signature is consistent
        // with the timestamp that was used by re-computing it manually
        $contentHash = hash('sha256', '');
        $canonical = implode("\n", ['GET', $path, $headersA['X-Timestamp'], $contentHash]);
        $expectedSignature = base64_encode(hash_hmac('sha256', $canonical, $instanceSecret, true));

        self::assertSame('HMAC ' . $instanceId . ':' . $expectedSignature, $headersA['Authorization']);
    }

    #[Test]
    public function buildHeadersNormalizesHttpMethodToUppercase(): void
    {
        $headersLower = HmacUtility::buildHeaders('get', '/api/status/w-123', 'i-456', 'secret');
        $headersUpper = HmacUtility::buildHeaders('GET', '/api/status/w-123', 'i-456', 'secret');

        // Strip timestamps (which differ) and compare just the signature structure
        $signatureLower = explode(':', $headersLower['Authorization'], 2)[1];
        $signatureUpper = explode(':', $headersUpper['Authorization'], 2)[1];

        // Both must produce the same signature when timestamps match
        $contentHash = hash('sha256', '');
        $canonical = implode("\n", ['GET', '/api/status/w-123', $headersLower['X-Timestamp'], $contentHash]);
        $expected = base64_encode(hash_hmac('sha256', $canonical, 'secret', true));

        self::assertSame($expected, $signatureLower);
    }

    #[Test]
    public function buildHeadersSignatureDiffersForDifferentSecrets(): void
    {
        $headers1 = HmacUtility::buildHeaders('GET', '/api/status/w-123', 'i-456', 'secret-one');
        $headers2 = HmacUtility::buildHeaders('GET', '/api/status/w-123', 'i-456', 'secret-two');

        self::assertNotSame($headers1['Authorization'], $headers2['Authorization']);
    }

    #[Test]
    public function buildHeadersSignatureDiffersForDifferentPaths(): void
    {
        $headers1 = HmacUtility::buildHeaders('GET', '/api/status/w-123', 'i-456', 'secret');
        $headers2 = HmacUtility::buildHeaders('GET', '/api/dashboard-url/w-123', 'i-456', 'secret');

        self::assertNotSame($headers1['Authorization'], $headers2['Authorization']);
    }
}
