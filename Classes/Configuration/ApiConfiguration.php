<?php

declare(strict_types=1);

namespace T3G\Analytics\Configuration;

use TYPO3\CMS\Core\Http\Uri;

final readonly class ApiConfiguration
{
    private const DEFAULT_BASE_URL = 'https://middleware.analytics.typo3.com/api';
    private const DEFAULT_INTP_ID = '28096317-d75a-43b7-af39-f0862b66afa3';
    private const DEFAULT_ANALYTICS_API_BASE_URL = 'https://api.analytics.typo3.com/api';

    public function getIntpId(): string
    {
        $configured = $this->load('TYPO3_ANALYTICS_INTP_ID', 'intpId');
        return $configured !== '' ? $configured : self::DEFAULT_INTP_ID;
    }

    public function getAnalyticsApiBaseUrl(): string
    {
        $configured = $this->load('TYPO3_ANALYTICS_DATA_API_BASE_URL', 'analyticsApiBaseUrl');
        if ($configured === '' || !$this->isValidHttpUri($configured)) {
            return self::DEFAULT_ANALYTICS_API_BASE_URL;
        }
        return rtrim($configured, '/');
    }

    public function getBaseUrl(): string
    {
        $configured = $this->load('TYPO3_ANALYTICS_API_BASE_URL', 'apiBaseUrl');
        $url = $configured !== '' && $this->isValidHttpUri($configured) ? $configured : self::DEFAULT_BASE_URL;
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
        $verifySsl = $this->load('TYPO3_ANALYTICS_VERIFY_SSL', 'verifySsl');
        return ['verify' => $verifySsl !== '0'];
    }

    /**
     * Returns Basic Auth options when credentials are embedded in the configured API URL.
     * Only used for registration (not for HMAC-signed requests).
     *
     * @return array<string, mixed>
     */
    public function getAuthOptions(): array
    {
        $configured = $this->load('TYPO3_ANALYTICS_API_BASE_URL', 'apiBaseUrl');
        if ($configured === '') {
            return [];
        }
        $parts = parse_url($configured) ?: [];
        if (!empty($parts['user'])) {
            return ['auth' => [$parts['user'], $parts['pass'] ?? '']];
        }
        return [];
    }

    private function load(string $envVar, string $confVarsKey): string
    {
        $env = getenv($envVar);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return (string)($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics'][$confVarsKey] ?? '');
    }

    private function isValidHttpUri(string $url): bool
    {
        try {
            $scheme = (new Uri($url))->getScheme();
            return in_array($scheme, ['http', 'https'], true);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
