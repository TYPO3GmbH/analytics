<?php

declare(strict_types=1);

namespace T3G\Analytics\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final readonly class ApiConfiguration
{
    private const DEFAULT_BASE_URL = 'https://middleware.analytics.typo3.com/api';

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    public function getAnalyticsApiBaseUrl(): string
    {
        $configured = (string)($this->extensionConfiguration->get('analytics', 'analyticsApiBaseUrl') ?? '');
        return $configured !== '' ? rtrim($configured, '/') : 'https://api.analytics.typo3.com/api';
    }

    public function getBaseUrl(): string
    {
        $configured = (string)($this->extensionConfiguration->get('analytics', 'apiBaseUrl') ?? '');
        $url = $configured !== '' ? $configured : self::DEFAULT_BASE_URL;
        $url = rtrim($url, '/');
        $parts = parse_url($url) ?: [];
        $result = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }
        return $result . rtrim($parts['path'] ?? '', '/');
    }

    /** @return array<string, mixed> */
    public function getRequestOptions(): array
    {
        $verifySsl = (bool)($this->extensionConfiguration->get('analytics', 'verifySsl') ?? true);
        return ['verify' => $verifySsl];
    }

    /**
     * Returns Basic Auth options when credentials are embedded in the configured API URL.
     * Only used for registration (not for HMAC-signed requests).
     *
     * @return array<string, mixed>
     */
    public function getAuthOptions(): array
    {
        $configured = (string)($this->extensionConfiguration->get('analytics', 'apiBaseUrl') ?? '');
        if ($configured === '') {
            return [];
        }
        $parts = parse_url($configured) ?: [];
        if (!empty($parts['user'])) {
            return ['auth' => [$parts['user'], $parts['pass'] ?? '']];
        }
        return [];
    }
}
