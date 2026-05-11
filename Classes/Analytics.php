<?php

declare(strict_types=1);

namespace T3G\Analytics;

final class Analytics
{
    public const INTP_ID = 'cad26303-1c79-415e-8b39-45d8aadfb7f3';

    private const API_BASE_URL_DEFAULT = 'https://foo:bar@stage.api.analytics.typo3.com/api';

    /**
     * Returns the API base URL without credentials.
     */
    public static function getApiBaseUrl(): string
    {
        $parts = self::parseApiUrl();
        $url = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        return $url . rtrim($parts['path'] ?? '', '/');
    }

    /**
     * Returns base request options for all API calls (verify only).
     *
     * @return array<string, mixed>
     */
    public static function getApiRequestOptions(): array
    {
        return ['verify' => false];
    }

    /**
     * Returns the Basic Auth option when credentials are embedded in the API URL
     * (e.g. https://user:pass@stage.api.analytics.typo3.com/api).
     *
     * Must NOT be used together with HMAC-authenticated requests because Guzzle's
     * auth option sets CURLOPT_USERPWD which overrides any existing Authorization header.
     *
     * @return array<string, mixed>
     */
    public static function getApiAuthOptions(): array
    {
        $parts = self::parseApiUrl();
        if (!empty($parts['user'])) {
            return ['auth' => [$parts['user'], $parts['pass'] ?? '']];
        }
        return [];
    }

    /** @return array<string, mixed> */
    private static function parseApiUrl(): array
    {
        $url = rtrim(
            (string)($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl'] ?? self::API_BASE_URL_DEFAULT),
            '/'
        );
        return parse_url($url) ?: [];
    }
}
