<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface HmacSignerInterface
{
    /**
     * @return array<string, string>
     * @throws \Exception
     */
    public function buildHeaders(string $method, string $path, string $instanceId, string $instanceSecret, string $body = ''): array;
}
