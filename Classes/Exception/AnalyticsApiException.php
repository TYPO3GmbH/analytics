<?php

declare(strict_types=1);

namespace T3G\Analytics\Exception;

final class AnalyticsApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?int $statusCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($reason, $statusCode ?? 0, $previous);
    }
}
