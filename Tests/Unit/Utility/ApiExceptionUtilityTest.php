<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use T3G\Analytics\Utility\ApiExceptionUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ApiExceptionUtilityTest extends UnitTestCase
{
    #[Test]
    public function extractReasonReturnsDetailFromJsonBody(): void
    {
        $e = $this->buildExceptionWithJsonBody(['detail' => 'quota exceeded'], 422, 'Unprocessable Entity');

        self::assertSame('quota exceeded', ApiExceptionUtility::extractReason($e));
    }

    #[Test]
    public function extractReasonFallsBackToDescriptionWhenDetailMissing(): void
    {
        $e = $this->buildExceptionWithJsonBody(['description' => 'not found'], 404, 'Not Found');

        self::assertSame('not found', ApiExceptionUtility::extractReason($e));
    }

    #[Test]
    public function extractReasonFallsBackToExceptionMessageWhenJsonHasNeitherDetailNorDescription(): void
    {
        $e = $this->buildExceptionWithJsonBody(['code' => 42], 400, 'Bad Request', 'original message');

        self::assertSame('original message', ApiExceptionUtility::extractReason($e));
    }

    #[Test]
    public function extractReasonReturnsHttpStatusInfoWhenBodyIsHtml(): void
    {
        $e = $this->buildExceptionWithRawBody('<!DOCTYPE html><html><body>Error</body></html>', 500, 'Internal Server Error');

        self::assertSame('HTTP 500 Internal Server Error', ApiExceptionUtility::extractReason($e));
    }

    #[Test]
    public function extractReasonReturnsHttpStatusInfoWhenBodyIsEmpty(): void
    {
        $e = $this->buildExceptionWithRawBody('', 503, 'Service Unavailable');

        self::assertSame('HTTP 503 Service Unavailable', ApiExceptionUtility::extractReason($e));
    }

    #[Test]
    public function extractReasonReturnsExceptionMessageWhenNoResponse(): void
    {
        $e = new \RuntimeException('Connection refused');

        self::assertSame('Connection refused', ApiExceptionUtility::extractReason($e));
    }

    /** Helpers */

    private function buildExceptionWithJsonBody(array $data, int $status, string $reason, string $message = ''): \Throwable
    {
        return $this->buildExceptionWithRawBody((string)json_encode($data), $status, $reason, $message);
    }

    private function buildExceptionWithRawBody(string $body, int $status, string $reason, string $message = ''): \Throwable
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getReasonPhrase')->willReturn($reason);

        return new class ($response, $message) extends \RuntimeException {
            public function __construct(
                private readonly ResponseInterface $response,
                string $message,
            ) {
                parent::__construct($message);
            }

            public function getResponse(): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
