<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

readonly class HmacSigner implements HmacSignerInterface
{
    /**
     * Builds HMAC authentication headers for a signed API request.
     *
     * @return array<string, string>
     * @throws \Exception
     */
    public function buildHeaders(string $method, string $path, string $instanceId, string $instanceSecret): array
    {
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
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
