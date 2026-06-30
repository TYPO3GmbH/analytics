<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface ApiExceptionExtractorInterface
{
    public function extractReason(\Throwable $e): string;
}
