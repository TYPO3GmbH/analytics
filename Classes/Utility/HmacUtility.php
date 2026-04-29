<?php

declare(strict_types=1);

namespace T3G\Analytics\Utility;

final class HmacUtility
{
    /**
     * Builds HMAC authentication headers for a signed API request.
     *
     * @return array<string, string>
     */
    public static function buildHeaders(string $method, string $path, string $instanceId, string $instanceSecret): array
    {
        $timestamp = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
        $contentHash = hash('sha256', '');

        $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $contentHash]);
        $signature = base64_encode(hash_hmac('sha256', $canonical, $instanceSecret, true));

        return [
            'Authorization' => 'HMAC ' . $instanceId . ':' . $signature,
            'X-Timestamp' => $timestamp,
            'X-Content-Hash' => $contentHash,
        ];
    }
}
