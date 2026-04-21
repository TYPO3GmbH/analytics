<?php

declare(strict_types=1);

namespace T3G\Analytics\Utility;

final class ApiExceptionHelper
{
    public static function extractReason(\Throwable $e): string
    {
        if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
            $body = json_decode((string)$e->getResponse()->getBody(), true);
            if (is_array($body)) {
                return (string)($body['detail'] ?? $body['description'] ?? $e->getMessage());
            }
        }

        return $e->getMessage();
    }
}
