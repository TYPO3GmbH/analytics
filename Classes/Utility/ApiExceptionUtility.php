<?php

declare(strict_types=1);

namespace T3G\Analytics\Utility;

final class ApiExceptionUtility
{
    public static function extractReason(\Throwable $e): string
    {
        if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
            $response = $e->getResponse();
            $body = json_decode((string)$response->getBody(), true);
            if (is_array($body)) {
                return (string)($body['detail'] ?? $body['description'] ?? $e->getMessage());
            }
            // Non-JSON response (e.g., HTML error page): return HTTP status info only
            return sprintf('HTTP %d %s', $response->getStatusCode(), $response->getReasonPhrase());
        }

        return $e->getMessage();
    }
}
