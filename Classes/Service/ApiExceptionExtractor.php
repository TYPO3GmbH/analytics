<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

readonly class ApiExceptionExtractor implements ApiExceptionExtractorInterface
{
    public function extractReason(\Throwable $e): string
    {
        if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
            $response = $e->getResponse();
            $body = json_decode((string)$response->getBody(), true);
            if (is_array($body)) {
                return (string)($body['detail'] ?? $body['description'] ?? $e->getMessage());
            }
            return sprintf('HTTP %d %s', $response->getStatusCode(), $response->getReasonPhrase());
        }
        return $e->getMessage();
    }
}
