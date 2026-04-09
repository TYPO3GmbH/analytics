<?php

declare(strict_types=1);

namespace T3G\Analytics;

final class Analytics
{
    public const string INTP_ID = 'cad26303-1c79-415e-8b39-45d8aadfb7f3';

    private const string API_BASE_URL_DEFAULT = 'https://site-analytics-middleware.ddev.site/api';

    public static function getApiBaseUrl(): string
    {
        return rtrim(
            (string)($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['apiBaseUrl'] ?? self::API_BASE_URL_DEFAULT),
            '/'
        );
    }
}
